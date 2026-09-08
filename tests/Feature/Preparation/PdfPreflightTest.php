<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfFixtures;

/**
 * Preflight classification across the whole Stage 0 fixture matrix.
 *
 * These are plain PHPUnit tests: the preparation domain has no framework dependency,
 * and booting Laravel would only slow the matrix down.
 */
final class PdfPreflightTest extends TestCase
{
    /**
     * @param  array<int, int|float>  $values
     * @return array<int, float>
     */
    private static function floats(array $values): array
    {
        return array_map(static fn (int|float $v): float => (float) $v, $values);
    }

    #[DataProviderExternal(PdfFixtures::class, 'all')]
    public function test_every_fixture_is_classified_as_the_manifest_declares(string $name, array $entry): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes($name));

        $expected = $entry['expected_preflight'] === 'accept';

        $this->assertSame(
            $expected,
            $report->isAccepted(),
            $name.': expected '.$entry['expected_preflight'].', got '
            .($report->isAccepted() ? 'accept' : 'reject with ['.implode(', ', $report->rejectionCodes()).']'),
        );

        $this->assertSame(
            $entry['expected_rejections'],
            $report->rejectionCodes(),
            $name.' produced unexpected rejection codes.',
        );
    }

    #[DataProviderExternal(PdfFixtures::class, 'accepted')]
    public function test_accepted_fixtures_report_the_page_geometry_the_manifest_records(string $name, array $entry): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes($name));

        $this->assertCount(count($entry['pages']), $report->pages);

        foreach ($entry['pages'] as $expectedPage) {
            $page = $report->page($expectedPage['page']);
            $this->assertNotNull($page, $name.' is missing page '.$expectedPage['page'].'.');

            // json_decode gives ints for whole numbers, so compare as floats throughout.
            $this->assertSame(self::floats($expectedPage['media_box']), $page->mediaBox->toArray());
            $this->assertSame(self::floats($expectedPage['crop_box']), $page->cropBox->toArray());
            $this->assertSame($expectedPage['rotation'], $page->rotation->value);
            $this->assertSame((float) $expectedPage['user_unit'], $page->userUnit);
            $this->assertEqualsWithDelta($expectedPage['native_width'], $page->nativeWidth(), 1.0e-6);
            $this->assertEqualsWithDelta($expectedPage['native_height'], $page->nativeHeight(), 1.0e-6);
        }
    }

    public function test_every_rejection_carries_an_actionable_message(): void
    {
        $preflight = new TcPdfPreflight;

        foreach (PdfFixtures::manifest() as $entry) {
            if ($entry['expected_preflight'] !== 'reject') {
                continue;
            }

            $report = $preflight->inspect(PdfFixtures::bytes($entry['name']));

            foreach ($report->rejections() as $rejection) {
                $this->assertGreaterThan(
                    60,
                    strlen($rejection->message),
                    $entry['name'].' rejection "'.$rejection->code->value.'" needs a message that says what to do.',
                );
                $this->assertStringEndsWith('.', trim($rejection->message));
            }
        }
    }

    public function test_an_encrypted_document_is_rejected_before_anything_else_is_trusted(): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes('encrypted-aes128'));

        $this->assertFalse($report->isAccepted());
        $this->assertSame(['encrypted'], $report->rejectionCodes());
        $this->assertSame([], $report->pages, 'No geometry may be reported from a document we cannot decrypt.');
        $this->assertStringContainsString('Remove the password', $report->rejectionMessage());
    }

    public function test_an_already_signed_document_is_rejected_rather_than_silently_invalidated(): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes('already-signed'));

        $this->assertTrue($report->hasCode(PreflightCode::AlreadySigned));
        $this->assertStringContainsString('invalidate that signature', $report->rejectionMessage());
    }

    public function test_a_user_unit_page_is_accepted_with_an_explicit_warning(): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes('user-unit'));

        $this->assertTrue($report->isAccepted());
        $this->assertTrue($report->hasCode(PreflightCode::UserUnitNotPreserved));
        $this->assertSame(2.0, $report->page(1)?->userUnit);
    }

    public function test_an_offset_crop_box_is_accepted_with_an_explicit_warning(): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes('cropbox-offset'));

        $this->assertTrue($report->isAccepted());
        $this->assertTrue($report->hasCode(PreflightCode::NonZeroCropBoxOrigin));
    }

    public function test_annotations_are_reported_as_not_preserved(): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes('form-fields-annotations'));

        $this->assertTrue($report->isAccepted());
        $this->assertTrue($report->hasCode(PreflightCode::AnnotationsNotPreserved));
    }

    public function test_a_file_that_is_not_a_pdf_is_rejected_rather_than_throwing(): void
    {
        $report = (new TcPdfPreflight)->inspect('this is not a PDF at all');

        $this->assertFalse($report->isAccepted());
        $this->assertSame(['unparseable'], $report->rejectionCodes());
    }

    public function test_an_empty_upload_is_rejected(): void
    {
        $report = (new TcPdfPreflight)->inspect('');

        $this->assertFalse($report->isAccepted());
        $this->assertSame(['unparseable'], $report->rejectionCodes());
    }

    public function test_the_size_limit_is_enforced_before_parsing(): void
    {
        $preflight = new TcPdfPreflight(new PreflightLimits(maxBytes: 128));

        $report = $preflight->inspect(PdfFixtures::bytes('single-page-letter'));

        $this->assertSame(['size_limit_exceeded'], $report->rejectionCodes());
        $this->assertSame(0, $report->metrics->objectCount, 'Nothing should be parsed once the size limit trips.');
    }

    public function test_the_page_limit_is_enforced(): void
    {
        $preflight = new TcPdfPreflight(new PreflightLimits(maxPages: 2));

        $report = $preflight->inspect(PdfFixtures::bytes('multi-page-mixed-size'));

        $this->assertSame(['invalid_page_geometry'], $report->rejectionCodes());
        $this->assertStringContainsString('2-page limit', $report->rejectionMessage());
    }

    public function test_metrics_are_recorded_for_every_inspection(): void
    {
        $report = (new TcPdfPreflight)->inspect(PdfFixtures::bytes('multi-page-mixed-size'));

        $this->assertSame(strlen(PdfFixtures::bytes('multi-page-mixed-size')), $report->metrics->byteSize);
        $this->assertSame(3, $report->metrics->pageCount);
        $this->assertGreaterThan(0, $report->metrics->objectCount);
        $this->assertGreaterThan(0.0, $report->metrics->elapsedSeconds);
    }

    public function test_classification_is_deterministic(): void
    {
        $preflight = new TcPdfPreflight;

        foreach (PdfFixtures::manifest() as $entry) {
            $bytes = PdfFixtures::bytes($entry['name']);

            $first = $preflight->inspect($bytes);
            $second = $preflight->inspect($bytes);

            $this->assertSame(
                array_map(static fn ($f): array => $f->toArray(), $first->findings),
                array_map(static fn ($f): array => $f->toArray(), $second->findings),
                $entry['name'].' classified differently on a second pass.',
            );
        }
    }
}
