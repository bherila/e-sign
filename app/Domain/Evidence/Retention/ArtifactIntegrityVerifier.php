<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\Exceptions\ArtifactStorageException;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Re-reads every published artifact and checks it is still the artifact the row describes.
 *
 * ## What it proves, and what it does not
 *
 * It proves that the bytes currently retrievable through the storage adapter hash to the
 * SHA-256 recorded when they were published, and that an executed PDF still carries a seal
 * that validates over those bytes. That is the check that catches silent corruption, a
 * half-restored backup, a truncated write, and an object replaced out of band.
 *
 * It is **not** independent assurance about the seal itself: `ArtifactValidator` shares a
 * library with the sealer, so it cannot find a fault common to both directions. That is
 * what `scripts/validate-seal.sh` and the external validators in CI are for. What this adds
 * over the read-back check at publication time is *elapsed time* — publication proved the
 * bytes were durable that day, and this proves they still are.
 *
 * ## Verification survives a key rotation, because it never asks the active configuration
 *
 * A sealed artifact is checked against the certificate for the `seal_key_id` **the artifact
 * itself recorded**, resolved through {@see SealCertificateDirectory}. The active seal
 * configuration is not consulted and is not relevant: after a rotation, most published
 * artifacts were sealed by a key that no longer signs anything, and their private key should
 * already have been destroyed. Verification needs neither.
 *
 * Three things are then checked, and they fail differently on purpose:
 *
 *  - the recorded key id resolves to a certificate here at all — if it does not, this
 *    deployment is holding a document it cannot attribute, which is an evidence gap
 *    (`unresolvable_key`), not corruption;
 *  - that certificate's SHA-256 is the `seal_certificate_sha256` the row recorded;
 *  - the CMS actually verified against that same certificate, which is what
 *    `ValidationReport::$signerFingerprint` reports.
 *
 * The last one is the interesting one: it is what stops an artifact from being accepted
 * because *some* trusted key sealed it rather than the key the evidence names.
 *
 * ## The digest is recomputed, never trusted
 *
 * `ArtifactStore::digestOf()` streams the object back and hashes it. It never reads an
 * ETag: an ETag is a transfer checksum whose algorithm depends on how the object was
 * uploaded, and docs/HANDOFF.md section 12 forbids using one as this application's document
 * hash.
 *
 * ## What is deliberately skipped
 *
 *  - **Artifacts of a soft-deleted envelope.** Retention removed those bytes on purpose
 *    (see {@see BlobPurger}); reporting them as missing would make a correctly applied
 *    retention policy look like data loss and would train an operator to ignore the probe.
 *  - **Unpublished rows.** There are none in normal operation — the row is inserted with
 *    `published_at` set, in the publishing transaction — but a row without it describes an
 *    object no reader is entitled to yet.
 *
 * The `--workspace` and `--since` filters narrow the run, and both are recorded on the run
 * row: a narrow run is not evidence about the artifacts it did not look at, and the probe
 * needs to be able to say so.
 */
final readonly class ArtifactIntegrityVerifier
{
    /**
     * Findings retained on the run row.
     *
     * Bounded because the pathological case — a whole disk unmounted — produces one finding
     * per artifact, and a JSON column holding a hundred thousand of them is a second
     * failure on top of the first. The counts are always exact; only the detail is capped.
     */
    public const MAX_FINDINGS = 50;

    public function __construct(
        private ConnectionInterface $db,
        private ArtifactStore $store,
        private ArtifactValidator $validator,
        private SealCertificateDirectory $certificates,
    ) {}

    /**
     * @param  string|null  $workspacePublicId  Restrict to one workspace, by public ULID.
     * @param  CarbonImmutable|null  $since  Only artifacts published at or after this moment.
     * @param  Closure(string, string, bool): void|null  $onArtifact  Called with the artifact
     *                                                                public id, its kind, and
     *                                                                whether it verified, so a
     *                                                                command can stream progress.
     * @param  string|null  $sealKeyId  Restrict to artifacts sealed under one key id — the
     *                                  post-rotation question, "do the documents sealed by the
     *                                  key I just retired still verify?"
     */
    public function verify(
        ?string $workspacePublicId = null,
        ?CarbonImmutable $since = null,
        ?Closure $onArtifact = null,
        ?string $sealKeyId = null,
    ): ArtifactVerificationRun {
        $run = ArtifactVerificationRun::create([
            'workspace_public_id' => $workspacePublicId,
            'published_since' => $since,
            'seal_key_id' => $sealKeyId,
            'started_at' => CarbonImmutable::now(),
        ]);

        $checked = 0;
        $mismatches = 0;
        $missing = 0;
        $invalid = 0;
        $unresolvable = 0;
        $findings = [];

        foreach ($this->artifacts($workspacePublicId, $since, $sealKeyId) as $artifact) {
            $checked++;
            $problem = $this->inspect($artifact, $findings);

            match ($problem) {
                'digest_mismatch' => $mismatches++,
                'missing', 'unreadable' => $missing++,
                'invalid_signature' => $invalid++,
                'unresolvable_key' => $unresolvable++,
                default => null,
            };

            if ($onArtifact !== null) {
                $onArtifact((string) $artifact->public_id, (string) $artifact->kind, $problem === null);
            }
        }

        $run->fill([
            'finished_at' => CarbonImmutable::now(),
            'artifacts_checked' => $checked,
            'digest_mismatches' => $mismatches,
            'missing_objects' => $missing,
            'invalid_signatures' => $invalid,
            'unresolvable_keys' => $unresolvable,
            'passed' => $mismatches + $missing + $invalid + $unresolvable === 0,
            'findings' => $findings === [] ? null : $findings,
        ])->save();

        return $run->refresh();
    }

    /**
     * Published artifacts in scope, read through the query builder.
     *
     * Eloquent's soft-delete scope on `envelopes` would do the right thing here by accident;
     * the join makes it a decision instead, and the same query then also carries the
     * workspace filter without a second round trip.
     *
     * @return Collection<int, stdClass>
     */
    private function artifacts(?string $workspacePublicId, ?CarbonImmutable $since, ?string $sealKeyId = null)
    {
        $query = $this->db->table('artifacts')
            ->join('envelopes', 'envelopes.id', '=', 'artifacts.envelope_id')
            ->join('workspaces', 'workspaces.id', '=', 'envelopes.workspace_id')
            ->whereNotNull('artifacts.published_at')
            ->whereNull('envelopes.deleted_at')
            ->orderBy('artifacts.id')
            ->select([
                'artifacts.public_id',
                'artifacts.kind',
                'artifacts.disk',
                'artifacts.path',
                'artifacts.sha256',
                'artifacts.bytes',
                'artifacts.seal_key_id',
                'artifacts.seal_certificate_sha256',
                'envelopes.public_id as envelope_public_id',
                'workspaces.public_id as workspace_public_id',
            ]);

        if ($workspacePublicId !== null) {
            $query->where('workspaces.public_id', $workspacePublicId);
        }

        if ($since !== null) {
            $query->where('artifacts.published_at', '>=', $since);
        }

        if ($sealKeyId !== null) {
            $query->where('artifacts.seal_key_id', $sealKeyId);
        }

        return $query->get();
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return string|null The problem kind, or null when the artifact verified.
     */
    private function inspect(stdClass $artifact, array &$findings): ?string
    {
        $disk = (string) $artifact->disk;
        $path = (string) $artifact->path;
        $expected = (string) $artifact->sha256;

        try {
            if (! $this->store->exists($disk, $path)) {
                $this->addFinding($findings, $artifact, 'missing', 'The object is not present on its disk.');

                return 'missing';
            }

            $actual = $this->store->digestOf($disk, $path);
        } catch (ArtifactStorageException) {
            $this->addFinding($findings, $artifact, 'unreadable', 'The object could not be read back.');

            return 'unreadable';
        }

        if (! hash_equals($expected, $actual)) {
            $this->addFinding($findings, $artifact, 'digest_mismatch', sprintf(
                'Recomputed SHA-256 %s does not match the recorded %s.',
                $actual,
                $expected,
            ));

            return 'digest_mismatch';
        }

        if ((string) $artifact->kind !== ArtifactKind::ExecutedPdf->value) {
            return null;
        }

        // Only the executed PDF carries a seal. Re-running the validator on it is the second
        // half of the check: bytes that hash correctly and no longer validate would mean the
        // recorded digest and the seal disagree, which the digest comparison alone cannot see.
        try {
            $report = $this->validator->validate($this->store->get($disk, $path));
        } catch (ArtifactStorageException) {
            $this->addFinding($findings, $artifact, 'unreadable', 'The executed PDF could not be read back in full.');

            return 'unreadable';
        }

        if (! $report->isValid()) {
            $this->addFinding($findings, $artifact, 'invalid_signature', sprintf(
                'The seal no longer validates: %s',
                implode('; ', array_slice($report->failures, 0, 3)) ?: 'no failure was reported, but the report is not valid.',
            ));

            return 'invalid_signature';
        }

        return $this->checkAttribution($artifact, $report->signerFingerprint, $findings);
    }

    /**
     * Check the artifact against the certificate for the key id it recorded.
     *
     * This is the half that survives a rotation. Nothing here reads the active seal
     * configuration: the key id comes off the row, the certificate comes out of the
     * directory, and a deployment that has rotated three times answers all four generations
     * the same way.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return string|null The problem kind, or null when attribution held.
     */
    private function checkAttribution(stdClass $artifact, string $signerFingerprint, array &$findings): ?string
    {
        $keyId = (string) ($artifact->seal_key_id ?? '');
        $recordedFingerprint = strtolower((string) ($artifact->seal_certificate_sha256 ?? ''));

        if ($keyId === '') {
            // Pre-rotation rows, and any artifact sealed before the key id was recorded.
            // Nothing to resolve, and inventing a failure here would report history as a
            // fault. The seal itself was already checked above.
            return null;
        }

        try {
            $certificate = $this->certificates->certificateFor($keyId);
        } catch (SealingException $exception) {
            $this->addFinding($findings, $artifact, 'unresolvable_key', sprintf(
                'The artifact was sealed under key id "%s", which this deployment cannot resolve to a '
                .'certificate: %s',
                $keyId,
                $exception->getMessage(),
            ));

            return 'unresolvable_key';
        }

        if ($recordedFingerprint !== '' && ! $certificate->matchesFingerprint($recordedFingerprint)) {
            $this->addFinding($findings, $artifact, 'unresolvable_key', sprintf(
                'The certificate configured for key id "%s" has SHA-256 %s, but the artifact records %s. '
                .'The wrong certificate is registered for this key id.',
                $keyId,
                $certificate->fingerprint,
                $recordedFingerprint,
            ));

            return 'unresolvable_key';
        }

        // The CMS verified against *a* certificate. This is where we insist it was this one:
        // otherwise an artifact sealed by any key the library could read would pass as an
        // artifact sealed by the key the evidence names.
        if ($signerFingerprint !== '' && ! hash_equals($certificate->fingerprint, strtolower($signerFingerprint))) {
            $this->addFinding($findings, $artifact, 'invalid_signature', sprintf(
                'The seal verified against a certificate with SHA-256 %s, but the artifact records key id '
                .'"%s", whose certificate is %s.',
                strtolower($signerFingerprint),
                $keyId,
                $certificate->fingerprint,
            ));

            return 'invalid_signature';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function addFinding(array &$findings, stdClass $artifact, string $problem, string $detail): void
    {
        if (count($findings) >= self::MAX_FINDINGS) {
            return;
        }

        // Identifiers and digests only. A storage key is never recorded here: this row is
        // read by an operational probe, and the key carries the workspace identifier and
        // the digest in a form somebody could paste into a request.
        $findings[] = [
            'artifact' => (string) $artifact->public_id,
            'envelope' => (string) $artifact->envelope_public_id,
            'workspace' => (string) $artifact->workspace_public_id,
            'kind' => (string) $artifact->kind,
            'problem' => $problem,
            'detail' => $detail,
        ];
    }
}
