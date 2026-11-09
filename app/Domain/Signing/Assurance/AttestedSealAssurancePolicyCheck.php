<?php

declare(strict_types=1);

namespace App\Domain\Signing\Assurance;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Readiness\SealReadinessAttestation;
use App\Domain\Evidence\Sealing\Readiness\SealReadinessAttester;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * The send gate where the seal key is not mounted into the sending process (issue #37).
 *
 * `ESIGN_SEAL_READINESS=attested`. docs/HANDOFF.md section 9 still holds — absent, unusable,
 * expired or mismatched material is refused before anybody is invited — but the material is
 * opened by the worker, which holds it, and recorded
 * ({@see SealReadinessAttester}). This check accepts that
 * record only when all of these hold, and otherwise refuses with the reason:
 *
 * - it is for the key id this deployment is configured to seal with;
 * - it is fresh: checked within `esign.seal.readiness_max_age_minutes` (15 by default), so a
 *   worker that has stopped checking stops vouching;
 * - the certificate it saw is still within its validity window now;
 * - the level asked for was available when it was checked.
 *
 * Fail closed: no row, a stale row, or an unreadable one is an unavailable answer.
 */
final readonly class AttestedSealAssurancePolicyCheck implements AssurancePolicyCheck
{
    public function __construct(private Repository $config) {}

    public function unavailableReason(AssuranceLevel $level): ?string
    {
        $keyId = (string) $this->config->get('esign.seal.key_id', '');

        if ($keyId === '') {
            return 'The service seal key id is not configured.';
        }

        $attestation = SealReadinessAttestation::query()->where('key_id', $keyId)->first();

        if (! $attestation instanceof SealReadinessAttestation) {
            return 'The worker has not yet checked seal key "'.$keyId.'". Start the worker, or run '
                .'esign:seal:attest where the key is mounted.';
        }

        $maxAge = (int) $this->config->get('esign.seal.readiness_max_age_minutes', 15);
        $now = CarbonImmutable::now();

        if ($attestation->checked_at->lessThan($now->subMinutes($maxAge))) {
            return 'The seal material was last checked by the worker at '.$attestation->checked_at->toIso8601String()
                .', more than '.$maxAge.' minutes ago. Check that the worker is running.';
        }

        if ($attestation->certificate_not_after === null || $attestation->certificate_not_after->lessThanOrEqualTo($now)) {
            return $attestation->material_error ?? 'The service seal certificate has expired.';
        }

        $levels = is_array($attestation->levels) ? $attestation->levels : [];

        if (! array_key_exists($level->value, $levels)) {
            return 'The worker did not report on assurance level '.$level->value.'.';
        }

        return $levels[$level->value] === null ? null : (string) $levels[$level->value];
    }
}
