<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Readiness;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The worker's last account of the seal material, for one key id (issue #37).
 * See {@see SealReadinessAttester}.
 *
 * @property int $id
 * @property string $key_id
 * @property string|null $certificate_sha256
 * @property CarbonImmutable|null $certificate_not_after
 * @property array<string, string|null> $levels Assurance level => null when available, else the reason.
 * @property string|null $material_error
 * @property CarbonImmutable $checked_at
 */
class SealReadinessAttestation extends Model
{
    protected $table = 'seal_readiness_attestations';

    protected $fillable = [
        'key_id',
        'certificate_sha256',
        'certificate_not_after',
        'levels',
        'material_error',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'levels' => 'array',
            'certificate_not_after' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
