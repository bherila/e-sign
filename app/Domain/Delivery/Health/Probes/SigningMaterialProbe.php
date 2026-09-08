<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Two questions about the seal, both of which have to be yes.
 *
 * **Can this deployment still seal?** The configured certificate and private key are present
 * and readable, and the certificate has not expired and is not about to. The private key is
 * never read past an `is_readable()` check; its contents never enter this process.
 *
 * **Can this deployment still verify what it has already sealed?** Every published artifact
 * records the `seal_key_id` that produced it. If any of those ids no longer resolves to a
 * certificate here, the deployment is holding executed evidence it cannot attribute — the
 * bytes are fine and nobody on this host can say who sealed them. That is the failure mode a
 * key rotation introduces, and it is silent: sealing keeps working, sending keeps working,
 * and only the old documents are affected.
 *
 * It is reported as a **failure**, not a warning, deliberately. It is an evidence gap, and
 * the fix is small and mechanical — put the retired certificate back in
 * `ESIGN_SEAL_RETIRED_KEYS` (never the retired private key, which verification does not
 * need). Warning about it would train an operator to scroll past the one signal that says
 * the archive stopped being verifiable.
 *
 * The rotation check runs only once the active material is configured, so the Docker web
 * role — which deliberately mounts no seal material and reports that first — does not also
 * report every artifact as unattributable. See docs/operations/seal-key-management.md.
 */
final class SigningMaterialProbe implements HealthProbe
{
    /** Key ids named in the message before it is summarized. */
    private const MAX_REPORTED_KEY_IDS = 3;

    public function __construct(
        private readonly SealCertificateDirectory $certificates,
        private readonly ConnectionInterface $db,
    ) {}

    public function name(): string
    {
        return 'signing_material';
    }

    public function check(): ProbeResult
    {
        $certificatePath = config('esign.seal.certificate_path');
        $privateKeyPath = config('esign.seal.private_key_path');

        if (! is_string($certificatePath) || $certificatePath === '' || ! is_string($privateKeyPath) || $privateKeyPath === '') {
            if (app()->environment('production')) {
                return ProbeResult::fail($this->name(), 'Signing certificate/key are not configured.');
            }

            return ProbeResult::warn($this->name(), 'Signing certificate/key are not configured.');
        }

        if (! is_readable($privateKeyPath)) {
            return ProbeResult::fail($this->name(), 'Signing private key is not readable.');
        }

        if (! is_readable($certificatePath)) {
            return ProbeResult::fail($this->name(), 'Signing certificate is not readable.');
        }

        try {
            $contents = file_get_contents($certificatePath);
        } catch (Throwable) {
            $contents = false;
        }

        if ($contents === false || $contents === '') {
            return ProbeResult::fail($this->name(), 'Signing certificate could not be read.');
        }

        $parsed = @openssl_x509_parse($contents);

        if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
            return ProbeResult::fail($this->name(), 'Signing certificate could not be parsed.');
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
        $daysRemaining = CarbonImmutable::now()->diffInDays($expiresAt, false);
        $warnDays = (int) config('esign.health.cert_warn_days', 30);
        $activeKeyId = $this->certificates->activeKeyId();
        $active = $activeKeyId === '' ? 'no key id' : 'key id '.$activeKeyId;

        if ($daysRemaining <= 0) {
            return ProbeResult::fail($this->name(), "Signing certificate ({$active}) has expired.");
        }

        $gap = $this->unattributableKeyIds();

        if ($gap !== []) {
            return ProbeResult::fail($this->name(), $this->gapMessage($gap));
        }

        if ($daysRemaining < $warnDays) {
            return ProbeResult::warn($this->name(), "Signing certificate ({$active}) expires in {$daysRemaining} day(s).");
        }

        $retired = count($this->certificates->retiredKeyIds());

        return ProbeResult::ok($this->name(), sprintf(
            'Signing certificate (%s) expires in %d day(s); %d retired key(s) still resolvable.',
            $active,
            $daysRemaining,
            $retired,
        ));
    }

    /**
     * Seal key ids named by published artifacts that this deployment cannot resolve.
     *
     * A `DISTINCT` over an indexed column, so the cost is the number of key versions rather
     * than the number of artifacts — a readiness endpoint can afford it, unlike re-reading
     * the artifacts themselves (which is `esign:artifacts:verify`'s job on a schedule).
     *
     * @return list<string>
     */
    private function unattributableKeyIds(): array
    {
        try {
            $recorded = $this->db->table('artifacts')
                ->whereNotNull('published_at')
                ->where('seal_key_id', '<>', '')
                ->distinct()
                ->orderBy('seal_key_id')
                ->pluck('seal_key_id');
        } catch (Throwable) {
            // The database being unreachable is DatabaseProbe's finding, not this one's.
            // Reporting it twice would send an operator to the seal runbook for a database
            // outage.
            return [];
        }

        $gap = [];

        foreach ($recorded as $keyId) {
            $keyId = (string) $keyId;

            try {
                $this->certificates->certificateFor($keyId);
            } catch (SealingException) {
                $gap[] = $keyId;
            }
        }

        return $gap;
    }

    /**
     * @param  list<string>  $gap
     */
    private function gapMessage(array $gap): string
    {
        $named = array_slice($gap, 0, self::MAX_REPORTED_KEY_IDS);
        $suffix = count($gap) > count($named) ? ' and '.(count($gap) - count($named)).' more' : '';

        return sprintf(
            'Published artifacts were sealed under %d seal key id(s) this deployment cannot resolve to a '
            .'certificate (%s%s). Those documents cannot be attributed here; add the retired certificate '
            .'to ESIGN_SEAL_RETIRED_KEYS.',
            count($gap),
            implode(', ', $named),
            $suffix,
        );
    }
}
