<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Readiness;

use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Opens the seal material where it is mounted and records what it found (issue #37).
 *
 * Runs in the worker — at start (`.docker/scripts/entrypoint.sh`) and every five minutes on the
 * queue ({@see AttestSealReadiness}) — because in the Docker layout that is the only role the
 * private key reaches. The check is exactly the one the local send gate makes: the sealer's own
 * preflight per assurance level, which loads the material, so an expired certificate, a key that
 * does not match it, an unreadable passphrase and an unusable timestamp authority are each a
 * recorded reason. Nothing is signed and no timestamp authority is contacted.
 *
 * Failures are recorded, not thrown: a row that says "unavailable, because" is what makes the
 * web role refuse with the reason instead of with "nobody has checked".
 */
final readonly class SealReadinessAttester
{
    public function __construct(
        private PdfSealer $sealer,
        private SealIdentity $identity,
        private Repository $config,
    ) {}

    public function attest(?CarbonImmutable $now = null): SealReadinessAttestation
    {
        $keyId = (string) $this->config->get('esign.seal.key_id', '');
        $fingerprint = null;
        $notAfter = null;
        $materialError = null;

        try {
            $fingerprint = $this->identity->certificateFingerprint();
            $notAfter = CarbonImmutable::instance($this->identity->notAfter());
        } catch (SealingException $exception) {
            $materialError = $exception->getMessage();
        } catch (Throwable) {
            // As SealMaterialAssurancePolicyCheck does: an unexpected message may name a path.
            $materialError = 'The configured seal material could not be read. Run esign:seal:status for the reason.';
        }

        $levels = [];

        foreach (AssuranceLevel::cases() as $level) {
            if ($materialError !== null) {
                $levels[$level->value] = $materialError;

                continue;
            }

            try {
                $this->sealer->preflight($level);
                $levels[$level->value] = null;
            } catch (SealingException $exception) {
                $levels[$level->value] = $exception->getMessage();
            } catch (Throwable) {
                $levels[$level->value] = 'The configured seal material could not be checked. Run esign:seal:status for the reason.';
            }
        }

        return SealReadinessAttestation::query()->updateOrCreate(
            ['key_id' => $keyId],
            [
                'certificate_sha256' => $fingerprint !== null && preg_match('/^[0-9a-f]{64}$/D', $fingerprint) === 1 ? $fingerprint : null,
                'certificate_not_after' => $notAfter,
                'levels' => $levels,
                'material_error' => $materialError === null ? null : mb_substr($materialError, 0, 500),
                'checked_at' => $now ?? CarbonImmutable::now(),
            ],
        );
    }
}
