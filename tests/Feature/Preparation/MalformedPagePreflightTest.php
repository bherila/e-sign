<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MalformedPagePdfs;

/**
 * A page whose /Contents is not drawing instructions is refused at intake (issue #167).
 *
 * Such a page renders blank and breaks everything downstream of preparation, so it is caught
 * where every other structural refusal is: by preflight, before anything is created, with the
 * damaged page named.
 */
final class MalformedPagePreflightTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function damaged(): array
    {
        return [
            'contents point at another page' => [MalformedPagePdfs::contentsPointAtAnotherPage()],
            'contents are a number' => [MalformedPagePdfs::contentsAreANumber()],
            'contents array holds a non-stream' => [MalformedPagePdfs::contentsArrayHoldsANonStream()],
        ];
    }

    #[DataProvider('damaged')]
    public function test_a_page_with_no_valid_content_is_refused_with_its_page_number(string $pdf): void
    {
        $report = (new TcPdfPreflight)->inspect($pdf);

        $this->assertFalse($report->isAccepted());
        $this->assertSame([PreflightCode::MalformedPage->value], $report->rejectionCodes());

        $finding = $report->toArray()['findings'][0];
        $this->assertSame(2, $finding['page']);
        $this->assertStringContainsString('page 2 has no valid content', $finding['message']);
    }

    /** @return array<string, array{string}> */
    public static function legitimate(): array
    {
        return [
            'every page has its own stream' => [MalformedPagePdfs::healthy()],
            'a page with no /Contents is blank, not damaged' => [MalformedPagePdfs::blankPage()],
            'two pages may share one stream' => [MalformedPagePdfs::sharedContentStream()],
            'a null /Contents is absent (7.3.9)' => [MalformedPagePdfs::contentsNull()],
            'a reference to an undefined object is null (7.3.10)' => [MalformedPagePdfs::contentsPointAtNothing()],
        ];
    }

    #[DataProvider('legitimate')]
    public function test_a_legitimate_page_is_not_refused(string $pdf): void
    {
        $report = (new TcPdfPreflight)->inspect($pdf);

        $this->assertTrue($report->isAccepted(), implode(', ', $report->rejectionCodes()));
        $this->assertCount(2, $report->pages);
    }
}
