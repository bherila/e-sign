<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native\Models;

use App\Domain\Identity\Credentials\ServiceCredential;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded `Idempotency-Key`, scoped to the credential that presented it.
 *
 * Scoped to the credential and not to the workspace on purpose: two integrations sharing a
 * tenant generate their keys independently, and a collision between them would make one
 * integration replay the other's response.
 *
 * @property int $id
 * @property int $credential_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $completed_at
 */
class IdempotencyKey extends Model
{
    /**
     * The table is named for the surface it serves, not for the class. `IdempotencyKey`
     * reads better in code than `ApiIdempotencyKey`, and the table has to say plainly which
     * of the two HTTP surfaces owns it when an operator is looking at a schema dump.
     */
    protected $table = 'api_idempotency_keys';

    /** Written once, on the claim; a replay never touches the row again. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'credential_id',
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ServiceCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ServiceCredential::class, 'credential_id');
    }

    /** A claimed row whose request has not finished (or did not succeed). */
    public function isInFlight(): bool
    {
        return $this->response_status === null;
    }
}
