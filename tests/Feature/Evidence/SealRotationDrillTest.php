<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Delivery\Health\Probes\SigningMaterialProbe;
use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Retention\ArtifactIntegrityVerifier;
use App\Domain\Evidence\Sealing\Console\RotateSealCommand;
use App\Domain\Evidence\Sealing\SealCertificate;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use App\Domain\Identity\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationScenario;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * The rotation drill: the whole point of issue #29.
 *
 * A rotation story that is only written down is a story. This test performs one — seal under
 * key A, rotate to key B, seal again — and then proves the four things an operator is
 * actually betting on:
 *
 *  1. **Both artifacts still verify** after the rotation. The one sealed under the retired key
 *     is not collateral damage.
 *  2. **Each verifies against its own certificate.** The certificate is resolved from the
 *     `seal_key_id` the artifact recorded, never from the active configuration, and pointing
 *     the retired key id at the wrong certificate is caught.
 *  3. **Removing the retired certificate breaks it loudly.** The artifact is reported as
 *     unattributable — an evidence gap, not a pass — and the `signing_material` probe fails.
 *     A rotation that silently orphans yesterday's documents is the failure this exists to
 *     prevent, so the failure has to be visible.
 *  4. **The new envelope seals under key B**, and `esign:artifacts:verify` passes over both
 *     generations at once.
 *
 * Neither artifact is ever re-sealed. Re-sealing an executed agreement would produce different
 * bytes for a document people have already signed; the recorded key id is what keeps the
 * original bytes attributable instead.
 */
final class SealRotationDrillTest extends TestCase
{
    use RefreshDatabase;

    private const CRYPTO = 'tests/Fixtures/crypto/';

    public function test_the_rotation_drill(): void
    {
        // --- Seal document one under key A ----------------------------------------------
        $first = FinalizationScenario::signed();
        $first->finalizer()->finalize($first->envelope);

        $keyACertificate = $this->certificate(SealingFixtures::KEY_ID, 'seal.test.crt');
        $artifactA = $this->executedArtifact($first);

        $this->assertSame(SealingFixtures::KEY_ID, $artifactA->seal_key_id);
        $this->assertSame($keyACertificate->fingerprint, $artifactA->seal_certificate_sha256);

        // --- Rotate to key B --------------------------------------------------------------
        // The second envelope is built before the rotation and finalized after it, which is
        // exactly the real ordering: an envelope sent under one key is finalized under
        // whatever key is configured when finalization runs.
        $second = FinalizationScenario::signed(reset: false);
        $this->rotateToKeyB();

        $second->finalizer()->finalize($second->envelope);

        $keyBCertificate = $this->certificate(SealingFixtures::KEY_ID_B, 'seal-b.test.crt');
        $artifactB = $this->executedArtifact($second);

        $this->assertSame(SealingFixtures::KEY_ID_B, $artifactB->seal_key_id);
        $this->assertSame($keyBCertificate->fingerprint, $artifactB->seal_certificate_sha256);
        $this->assertNotSame(
            $artifactA->seal_certificate_sha256,
            $artifactB->seal_certificate_sha256,
            'The drill proves nothing if both generations share a certificate.',
        );

        // The active identity really did move. Nothing new is sealed under key A any more.
        $this->assertSame(SealingFixtures::KEY_ID_B, app(SealIdentity::class)->keyId());

        // --- 1. Both artifacts still verify ------------------------------------------------
        $this->assertSame(0, $this->artisan('esign:artifacts:verify')->run());

        // --- 2. Each verifies against its own certificate ---------------------------------
        $validator = new TcLibPdfArtifactValidator;

        $reportA = $validator->validate($this->bytesOf($artifactA));
        $reportB = $validator->validate($this->bytesOf($artifactB));

        $this->assertTrue($reportA->isValid());
        $this->assertTrue($reportB->isValid());
        $this->assertSame($keyACertificate->fingerprint, $reportA->signerFingerprint);
        $this->assertSame($keyBCertificate->fingerprint, $reportB->signerFingerprint);

        // The scoped run: "do the documents sealed by the key I just retired still verify?"
        // Every artifact of a finalization records the key id, not only the sealed PDF, so the
        // filter selects the whole generation.
        $retiredRun = app(ArtifactIntegrityVerifier::class)
            ->verify(sealKeyId: SealingFixtures::KEY_ID);

        $sealedUnderA = Artifact::query()->where('seal_key_id', SealingFixtures::KEY_ID)->count();

        $this->assertGreaterThanOrEqual(1, $sealedUnderA);
        $this->assertSame($sealedUnderA, $retiredRun->artifacts_checked);
        $this->assertSame(0, $retiredRun->problemCount());
        $this->assertSame(SealingFixtures::KEY_ID, $retiredRun->seal_key_id);
        $this->assertSame(
            0,
            Artifact::query()->where('seal_key_id', SealingFixtures::KEY_ID_B)->count() - $sealedUnderA,
            'The two generations should be the same size, or the comparison below means nothing.',
        );

        // Registering the *wrong* certificate under the retired key id is caught. This is what
        // makes "resolved from the recorded key id" a real claim rather than a comment: if the
        // check were reading the active configuration, or trusting any certificate that
        // happened to load, this would pass.
        $this->retire(SealingFixtures::KEY_ID, 'seal-b.test.crt', 'root-b.test.crt');

        $misconfigured = app(ArtifactIntegrityVerifier::class)->verify();

        $this->assertSame(1, $misconfigured->unresolvable_keys);
        $this->assertFalse($misconfigured->passed);

        // --- 3. Remove key A's certificate: the gap is loud -------------------------------
        config(['esign.seal.retired_keys' => []]);

        $orphaned = app(ArtifactIntegrityVerifier::class)->verify();

        $this->assertSame(1, $orphaned->unresolvable_keys);
        $this->assertSame(0, $orphaned->digest_mismatches, 'The bytes are fine; only the attribution is gone.');
        $this->assertSame(0, $orphaned->invalid_signatures);
        $this->assertFalse($orphaned->passed);
        $this->assertSame(1, $this->artisan('esign:artifacts:verify')->run());

        $finding = ($orphaned->findings ?? [])[0] ?? [];
        $this->assertSame('unresolvable_key', $finding['problem'] ?? null);
        $this->assertStringContainsString(SealingFixtures::KEY_ID, (string) ($finding['detail'] ?? ''));

        $probe = app(SigningMaterialProbe::class)->check();

        $this->assertSame('fail', $probe->status->value);
        $this->assertStringContainsString(SealingFixtures::KEY_ID, $probe->message);

        // --- 4. Put the retired certificate back: everything verifies again ---------------
        $this->retire(SealingFixtures::KEY_ID, 'seal.test.crt', 'root.test.crt');

        $repaired = app(ArtifactIntegrityVerifier::class)->verify();

        $this->assertSame(0, $repaired->problemCount());
        $this->assertTrue($repaired->passed);
        $this->assertSame(Artifact::query()->count(), $repaired->artifacts_checked);
        $this->assertSame(0, $this->artisan('esign:artifacts:verify')->run());

        $this->assertSame('ok', app(SigningMaterialProbe::class)->check()->status->value);
    }

    public function test_rotate_records_the_outgoing_and_incoming_material_and_writes_no_secret(): void
    {
        FinalizationScenario::configureSeal();

        $exit = $this->artisan('esign:seal:rotate', [
            '--key-id' => SealingFixtures::KEY_ID_B,
            '--certificate' => base_path(self::CRYPTO.'seal-b.test.crt'),
            '--private-key' => base_path(self::CRYPTO.'seal-b.test.pkey'),
            '--chain' => base_path(self::CRYPTO.'root-b.test.crt'),
        ])->run();

        $this->assertSame(0, $exit);

        $event = AuditEvent::query()->where('action', RotateSealCommand::AUDIT_ACTION)->sole();
        $payload = $event->payload ?? [];

        $this->assertSame(SealingFixtures::KEY_ID, $payload['outgoing_key_id'] ?? null);
        $this->assertSame(SealingFixtures::KEY_ID_B, $payload['incoming_key_id'] ?? null);
        $this->assertSame(
            $this->certificate(SealingFixtures::KEY_ID_B, 'seal-b.test.crt')->fingerprint,
            $payload['incoming_certificate_sha256'] ?? null,
        );

        // Nothing derived from a private key, and no filesystem path, reaches the trail.
        $encoded = json_encode($payload);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('PRIVATE KEY', $encoded);
        $this->assertStringNotContainsString(base_path(), $encoded);

        // The rotation is prepared, not performed: the active key has not moved.
        $this->assertSame(SealingFixtures::KEY_ID, config('esign.seal.key_id'));
    }

    public function test_rotate_refuses_material_that_would_produce_unattributable_artifacts(): void
    {
        FinalizationScenario::configureSeal();

        // Same key id as the one in force: artifacts from before and after would be
        // indistinguishable.
        $this->assertNotSame(0, $this->artisan('esign:seal:rotate', [
            '--key-id' => SealingFixtures::KEY_ID,
            '--certificate' => base_path(self::CRYPTO.'seal-b.test.crt'),
            '--private-key' => base_path(self::CRYPTO.'seal-b.test.pkey'),
        ])->run());

        // An expired certificate.
        $this->assertNotSame(0, $this->artisan('esign:seal:rotate', [
            '--key-id' => 'fixture-seal-expired',
            '--certificate' => base_path(self::CRYPTO.'seal-expired.test.crt'),
            '--private-key' => base_path(self::CRYPTO.'seal-expired.test.pkey'),
        ])->run());

        // A private key that does not belong to the certificate.
        $this->assertNotSame(0, $this->artisan('esign:seal:rotate', [
            '--key-id' => SealingFixtures::KEY_ID_B,
            '--certificate' => base_path(self::CRYPTO.'seal-b.test.crt'),
            '--private-key' => base_path(self::CRYPTO.'wrong.test.pkey'),
        ])->run());

        // A key id already retired here: rotating back onto it re-creates the ambiguity.
        $this->retire(SealingFixtures::KEY_ID_B, 'seal-b.test.crt', 'root-b.test.crt');

        $this->assertNotSame(0, $this->artisan('esign:seal:rotate', [
            '--key-id' => SealingFixtures::KEY_ID_B,
            '--certificate' => base_path(self::CRYPTO.'seal-b.test.crt'),
            '--private-key' => base_path(self::CRYPTO.'seal-b.test.pkey'),
        ])->run());

        $this->assertSame(0, AuditEvent::query()->where('action', RotateSealCommand::AUDIT_ACTION)->count());
    }

    public function test_a_retired_certificate_is_resolvable_even_after_it_expires(): void
    {
        // The whole reason verification does not go through SealMaterial. An expired
        // certificate is the normal state of a retired key, and the artifacts it sealed were
        // sealed while it was valid.
        $this->retire('fixture-seal-expired', 'seal-expired.test.crt');

        $certificate = app(SealCertificateDirectory::class)->certificateFor('fixture-seal-expired');

        $this->assertTrue($certificate->isExpired());
        $this->assertNotSame('', $certificate->fingerprint);
    }

    /**
     * Point the active seal configuration at key B and retire key A.
     */
    private function rotateToKeyB(): void
    {
        config([
            'esign.seal.key_id' => SealingFixtures::KEY_ID_B,
            'esign.seal.certificate_path' => base_path(self::CRYPTO.'seal-b.test.crt'),
            'esign.seal.private_key_path' => base_path(self::CRYPTO.'seal-b.test.pkey'),
            'esign.seal.chain_path' => base_path(self::CRYPTO.'root-b.test.crt'),
        ]);

        $this->retire(SealingFixtures::KEY_ID, 'seal.test.crt', 'root.test.crt');

        // ConfiguredSealIdentity memoizes the material it read, and it is a singleton. A real
        // rotation restarts the worker; a test has to say so explicitly.
        app()->forgetInstance(SealIdentity::class);
    }

    private function retire(string $keyId, string $certificate, string $chain = ''): void
    {
        config(['esign.seal.retired_keys' => [[
            'key_id' => $keyId,
            'certificate_path' => base_path(self::CRYPTO.$certificate),
            'chain_path' => $chain === '' ? '' : base_path(self::CRYPTO.$chain),
        ]]]);
    }

    private function certificate(string $keyId, string $file): SealCertificate
    {
        return SealCertificate::fromPath($keyId, base_path(self::CRYPTO.$file));
    }

    private function executedArtifact(FinalizationScenario $scenario): Artifact
    {
        return Artifact::query()
            ->where('envelope_id', $scenario->envelope->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();
    }

    private function bytesOf(Artifact $artifact): string
    {
        return (string) Storage::disk($artifact->disk)->get($artifact->path);
    }
}
