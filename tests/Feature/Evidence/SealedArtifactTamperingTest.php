<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\SealedArtifact;
use App\Domain\Evidence\Sealing\TcLibPdfArtifactValidator;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * The negative cases from issue #7, checked against the produced artifacts.
 *
 * Each case starts from a genuinely sealed PDF and breaks exactly one thing, so
 * a pass cannot come from the artifact being unreadable for some other reason.
 * The same artifacts are written to tests/Fixtures/validation and re-checked
 * with pyHanko by scripts/validate-seal.sh; this class is the in-process gate.
 */
final class SealedArtifactTamperingTest extends TestCase
{
    private static ?SealedArtifact $artifact = null;

    private static ?SealedArtifact $donor = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Sealing is RSA-3072 work; two artifacts are enough for every case here.
        self::$artifact ??= SealingFixtures::seal(marker: 'A');
        self::$donor ??= SealingFixtures::seal(marker: 'B');
    }

    public static function tearDownAfterClass(): void
    {
        self::$artifact = null;
        self::$donor = null;
    }

    public function test_the_untampered_artifact_is_the_control(): void
    {
        $report = (new TcLibPdfArtifactValidator)->validate(self::$artifact->pdf);

        $this->assertTrue($report->isValid());
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());
    }

    public function test_content_modified_after_sealing_is_rejected(): void
    {
        $tampered = SealingFixtures::modifyCoveredContent(self::$artifact->pdf);

        $this->assertSame(strlen(self::$artifact->pdf), strlen($tampered));
        $this->assertNotSame(self::$artifact->pdf, $tampered);

        $report = (new TcLibPdfArtifactValidator)->validate($tampered);

        $this->assertFalse($report->isValid());
        $this->assertNull($report->reachedLevel());
        // The byte range still covers the file: only the digest catches this.
        $this->assertTrue($report->coversWholeFile);
        $this->assertFalse($report->cryptographicallySound);
    }

    public function test_a_truncated_artifact_is_rejected(): void
    {
        $report = (new TcLibPdfArtifactValidator)->validate(
            SealingFixtures::truncate(self::$artifact->pdf)
        );

        $this->assertFalse($report->isValid());
        $this->assertFalse($report->coversWholeFile);
        $this->assertStringContainsString('truncated', implode(' ', $report->failures));
    }

    public function test_an_unexpected_incremental_update_is_rejected(): void
    {
        $updated = SealingFixtures::appendIncrementalUpdate(self::$artifact->pdf);

        $report = (new TcLibPdfArtifactValidator)->validate($updated);

        $this->assertFalse($report->isValid());
        $this->assertSame(2, $report->revisions);
        $this->assertFalse($report->coversWholeFile);
        // The original signature is still sound over the bytes it covered; the
        // artifact is refused because the signature no longer covers the file.
        $this->assertTrue($report->cryptographicallySound);
        $this->assertStringContainsString('uncovered', implode(' ', $report->failures));
    }

    public function test_a_forged_cms_lifted_from_another_document_is_rejected(): void
    {
        $forged = SealingFixtures::forgeContents(self::$artifact->pdf, self::$donor->pdf);

        $this->assertSame(strlen(self::$artifact->pdf), strlen($forged));

        $report = (new TcLibPdfArtifactValidator)->validate($forged);

        $this->assertFalse($report->isValid());
        $this->assertTrue($report->coversWholeFile);
        $this->assertFalse($report->cryptographicallySound);
    }

    public function test_an_artifact_sealed_with_an_unrelated_key_is_still_only_as_trusted_as_its_chain(): void
    {
        // Sealed with a self-signed certificate outside the fixture root. The
        // CMS verifies — integrity and trust are different questions, and only
        // an external validator pointed at a trust anchor can answer the second.
        $artifact = SealingFixtures::seal(
            material: SealingFixtures::material(
                certificate: 'untrusted.test.crt',
                privateKey: 'untrusted.test.pkey',
                chain: '',
            ),
        );

        $report = (new TcLibPdfArtifactValidator)->validate($artifact->pdf);

        $this->assertTrue($report->isValid());
        $this->assertStringContainsString('UNTRUSTED', $report->signerSubject);
    }

    public function test_a_signature_dictionary_with_no_byte_range_is_rejected(): void
    {
        $report = (new TcLibPdfArtifactValidator)->validate(SealingFixtures::syntheticPdf());

        $this->assertFalse($report->signed);
        $this->assertFalse($report->isValid());
        $this->assertNull($report->reachedLevel());
    }

    public function test_bytes_that_are_not_a_pdf_are_rejected(): void
    {
        $report = (new TcLibPdfArtifactValidator)->validate('not a pdf at all');

        $this->assertFalse($report->isValid());
        $this->assertSame(['The bytes are not a PDF document.'], $report->failures);
    }
}
