<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to publish an envelope's artifacts.
 *
 * The row is written under the envelope lock in step 1 and then advanced from outside it, so
 * it is the only trace a worker that dies mid-render leaves behind. What it records is
 * chosen for exactly that case: `input_snapshot` says what the attempt was rendering from,
 * and `outputs` says what it managed to get into storage.
 *
 * @property int $id
 * @property int $envelope_id
 * @property int $generation
 * @property FinalizationRunState $state
 * @property array<string, mixed> $input_snapshot
 * @property array<string, array<string, mixed>>|null $outputs
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class FinalizationRun extends Model
{
    /** The redaction ceiling for a recorded failure. The column is 1000. */
    public const MAX_ERROR_LENGTH = 1000;

    protected $fillable = [
        'envelope_id',
        'generation',
        'state',
        'input_snapshot',
        'outputs',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => FinalizationRunState::class,
            'input_snapshot' => 'array',
            'outputs' => 'array',
            'generation' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /**
     * True when this run left behind durable bytes for the same inputs as `$input`.
     *
     * The test is `outputs`, not the run's final state, and that is the important detail.
     * `outputs` is written in one place — immediately after every object has been stored *and*
     * read back with a matching digest — so its presence means the bytes were durable, whatever
     * happened to the attempt afterwards. A run that uploaded successfully and then died in the
     * publishing transaction ends up `failed`, and its bytes are exactly the ones a retry
     * should publish rather than re-seal.
     *
     * Digest equality over the canonical snapshot, not a subset comparison: reusing bytes
     * rendered from *nearly* the same evidence would publish a document nobody attested to.
     * The objects themselves are re-verified before anything is reused.
     */
    public function canSupplyBytesFor(FinalizationInput $input): bool
    {
        return $this->outputs !== null
            && $this->outputs !== []
            && $this->state !== FinalizationRunState::Published
            && hash_equals($input->digest(), (string) ($this->input_snapshot['snapshot_sha256'] ?? ''));
    }
}
