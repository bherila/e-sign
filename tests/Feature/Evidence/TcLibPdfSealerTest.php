<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealFailedException;
use App\Domain\Evidence\Sealing\Exceptions\SealMaterialUnavailableException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityDestinationException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use App\Domain\Evidence\Sealing\SealRequest;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use App\Domain\Evidence\Sealing\TcLibPdfSealer;
use App\Domain\Evidence\Sealing\ValidationReport;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * PAdES B-B sealing on tc-lib-pdf, and the fail-closed behaviour around it.
 *
 * The positive case establishes that the produced bytes carry a detached CAdES
 * CMS under SHA-256 covering the whole file. It is a self-check: the
 * authoritative verdict is pyHanko's, via scripts/validate-seal.sh.
 */
final class TcLibPdfSealerTest extends TestCase
{
    public function test_it_seals_a_synthetic_pdf_to_pades_b_b(): void
    {
        $input = SealingFixtures::syntheticPdf();

        $artifact = SealingFixtures::sealer()->seal(SealingFixtures::request($input));

        $this->assertSame(AssuranceLevel::PadesBB, $artifact->level);
        $this->assertSame(SealingFixtures::KEY_ID, $artifact->keyId);
        $this->assertSame('sha256', $artifact->digestAlgorithm);
        $this->assertNull($artifact->timestampAuthority);
        $this->assertSame(hash('sha256', $artifact->pdf), $artifact->sha256);
        $this->assertStringStartsWith('%PDF-', $artifact->pdf);
        $this->assertGreaterThan(strlen($input), strlen($artifact->pdf));

        $report = (new TcLibPdfArtifactValidator)->validate($artifact->pdf);

        $this->assertSame([], $report->failures);
        $this->assertTrue($report->isValid());
        $this->assertSame('ETSI.CAdES.detached', $report->subFilter);
        $this->assertSame('sha256', $report->digestAlgorithm);
        $this->assertTrue($report->coversWholeFile);
        $this->assertTrue($report->cryptographicallySound);
        $this->assertFalse($report->hasSignatureTimestamp);
        $this->assertSame(1, $report->revisions);
        $this->assertStringContainsString('NOT FOR USE', $report->signerSubject);
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());
    }

    public function test_it_leaves_the_input_bytes_untouched(): void
    {
        $input = SealingFixtures::syntheticPdf();
        $before = hash('sha256', $input);

        SealingFixtures::sealer()->seal(SealingFixtures::request($input));

        $this->assertSame($before, hash('sha256', $input));
    }

    public function test_it_seals_with_a_passphrase_protected_key(): void
    {
        $material = SealingFixtures::material(
            privateKey: 'seal-encrypted.test.pkey',
            passphrase: SealingFixtures::TEST_PASSPHRASE,
        );

        $artifact = SealingFixtures::sealer($material)
            ->seal(SealingFixtures::request(SealingFixtures::syntheticPdf()));

        $this->assertTrue((new TcLibPdfArtifactValidator)->validate($artifact->pdf)->isValid());
    }

    public function test_it_refuses_b_t_when_no_timestamp_authority_is_configured(): void
    {
        $this->expectException(TimestampAuthorityNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/not downgraded to pades-b-b/');

        SealingFixtures::sealer(timestampAuthority: new HttpTimestampAuthority(''))
            ->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));
    }

    public function test_it_refuses_b_t_when_the_configured_authority_fails_the_destination_policy(): void
    {
        $this->expectException(TimestampAuthorityDestinationException::class);
        $this->expectExceptionMessageMatches('/non-public address/');

        SealingFixtures::sealer(timestampAuthority: new HttpTimestampAuthority('https://127.0.0.1/tsr'))
            ->seal(SealingFixtures::request(SealingFixtures::syntheticPdf(), AssuranceLevel::PadesBT));
    }

    public function test_it_refuses_an_empty_input(): void
    {
        $this->expectException(SealFailedException::class);
        $this->expectExceptionMessageMatches('/nothing to seal/');

        SealingFixtures::sealer()->seal(new SealRequest('', AssuranceLevel::PadesBB));
    }

    public function test_it_refuses_an_input_that_is_not_a_pdf(): void
    {
        $this->expectException(SealFailedException::class);

        SealingFixtures::sealer()->seal(SealingFixtures::request('this is not a PDF'));
    }

    public function test_the_preflight_rejects_an_unconfigured_deployment_before_signers_are_invited(): void
    {
        config()->set('esign.seal', [
            'key_id' => '',
            'certificate_path' => '',
            'private_key_path' => '',
            'digest_algorithm' => 'sha256',
        ]);

        $this->expectException(SealMaterialUnavailableException::class);

        $this->app->make(PdfSealer::class)->preflight(AssuranceLevel::PadesBB);
    }

    public function test_the_preflight_rejects_b_t_without_a_timestamp_authority(): void
    {
        config()->set('esign.seal', [
            'key_id' => SealingFixtures::KEY_ID,
            'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
            'private_key_path' => SealingFixtures::cryptoPath('seal.test.pkey'),
            'private_key_passphrase' => '',
            'chain_path' => SealingFixtures::cryptoPath('root.test.crt'),
            'digest_algorithm' => 'sha256',
        ]);
        config()->set('esign.tsa.url', '');

        $sealer = $this->app->make(PdfSealer::class);

        // B-B is reachable with this configuration.
        $sealer->preflight(AssuranceLevel::PadesBB);

        $this->expectException(TimestampAuthorityNotConfiguredException::class);

        $sealer->preflight(AssuranceLevel::PadesBT);
    }

    public function test_the_container_wires_the_evidence_sealing_ports(): void
    {
        $this->assertInstanceOf(TimestampAuthority::class, $this->app->make(TimestampAuthority::class));
        $this->assertInstanceOf(ArtifactValidator::class, $this->app->make(ArtifactValidator::class));
        $this->assertInstanceOf(PdfSealer::class, $this->app->make(PdfSealer::class));
    }

    public function test_it_refuses_to_return_an_artifact_that_does_not_reach_the_requested_level(): void
    {
        // A validator that reports an unsigned artifact stands in for the engine
        // silently failing to sign. The sealer must refuse the result rather
        // than hand back bytes at a level nothing established.
        $refusing = new class implements ArtifactValidator
        {
            public function validate(string $pdf): ValidationReport
            {
                return ValidationReport::unreadable(['stub: no signature found']);
            }
        };

        $sealer = new TcLibPdfSealer(
            material: static fn () => SealingFixtures::material(),
            timestampAuthority: new HttpTimestampAuthority(''),
            validator: $refusing,
        );

        $this->expectException(SealFailedException::class);
        $this->expectExceptionMessageMatches('/does not reach PAdES pades-b-b/');

        $sealer->seal(SealingFixtures::request(SealingFixtures::syntheticPdf()));
    }
}
