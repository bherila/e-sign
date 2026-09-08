<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One recorded run of `esign:artifacts:verify`.
 *
 * A row is written when the run starts and completed when it finishes, so a verification
 * that was killed half way through leaves a row with a null `finished_at` rather than no
 * trace — which is the difference between "the check is failing" and "the check stopped
 * running", and the readiness probe has to be able to tell those apart.
 *
 * Nothing overwrites a previous run. The history is the point: "it has been failing since
 * Tuesday" is only visible if Tuesday's row is still there.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $workspace_public_id
 * @property CarbonImmutable|null $published_since
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $artifacts_checked
 * @property int $digest_mismatches
 * @property int $missing_objects
 * @property int $invalid_signatures
 * @property bool|null $passed
 * @property list<array<string, mixed>>|null $findings
 * @property CarbonImmutable|null $created_at
 */
class ArtifactVerificationRun extends Model
{
    /** Written once, then completed once. There is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_public_id',
        'published_since',
        'started_at',
        'finished_at',
        'artifacts_checked',
        'digest_mismatches',
        'missing_objects',
        'invalid_signatures',
        'passed',
        'findings',
    ];

    protected function casts(): array
    {
        return [
            'published_since' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'artifacts_checked' => 'integer',
            'digest_mismatches' => 'integer',
            'missing_objects' => 'integer',
            'invalid_signatures' => 'integer',
            'passed' => 'boolean',
            'findings' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if (($run->public_id ?? '') === '') {
                $run->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Everything that did not match, across the three failure kinds. */
    public function problemCount(): int
    {
        return $this->digest_mismatches + $this->missing_objects + $this->invalid_signatures;
    }

    /** The most recent run that ran to completion, or null if none ever has. */
    public static function lastCompleted(): ?self
    {
        return self::query()
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }
}
