<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\TcPdf;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextExtractionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Pdf\PdfFixtureWriter;
use Tests\Support\PdfFixtures;

/**
 * How extraction fails, which is a contract in its own right.
 *
 * Two kinds of failure leave this class and they must stay two: a ceiling was crossed, and the
 * bytes are not a document anybody can read. They call for different answers — the first is
 * "this file is too expensive for this deployment", which a larger limit or a smaller file fixes,
 * and the second is "this file is broken", which neither does — and the caller routes them
 * differently: `RevisionAnchorResolver` reports one per anchored field either way, but preflight
 * publishes the specific {@see PreflightCode} for a ceiling and never for a parse failure.
 *
 * Every test here is about the *pair*, not about one of them, because the two regressions this
 * file exists to catch are both collapses: a budget exhaustion flattened into an extraction
 * failure by a catch-all, and a geometry failure that is neither, escaping as an
 * `InvalidArgumentException` nobody catches.
 */
final class TcPdfTextLocatorFailuresTest extends TestCase
{
    /**
     * The budget the caller supplies is the budget the parse is charged against.
     *
     * `PdfObjectGraph::parse()` builds its own when given none, and a private one carries the
     * *default* limits. A deployment that raised its ceilings would then accept a document at
     * upload and refuse the same bytes here, and the parse would cost nothing against the
     * caller's running total — so the document's own budget would be spent by the walk alone.
     *
     * The combination is *whose limits* against *which ceiling*, and the two refusal cells are
     * what separate them: an object ceiling can only be crossed inside the parse, and the time
     * backstop is consulted throughout. A test that only proved "a spent budget refuses" would
     * pass while the parse still ran on defaults, because the walk that follows would trip the
     * clock anyway.
     *
     * @return iterable<string, array{PreflightLimits|null, PreflightCode|null}>
     */
    public static function budgets(): iterable
    {
        yield 'no budget at all' => [null, null];
        yield 'a budget with room' => [new PreflightLimits, null];
        yield 'limits this document\'s object count exceeds' => [
            new PreflightLimits(maxObjects: 1),
            PreflightCode::ObjectLimitExceeded,
        ];
        yield 'a budget with no time left' => [
            new PreflightLimits(timeBudgetSeconds: 0.0000001),
            PreflightCode::TimeBudgetExceeded,
        ];
    }

    #[DataProvider('budgets')]
    public function test_the_parse_is_charged_against_the_callers_budget(
        ?PreflightLimits $limits,
        ?PreflightCode $refusal,
    ): void {
        $budget = $limits instanceof PreflightLimits ? new PreflightBudget($limits) : null;

        if ($refusal === PreflightCode::TimeBudgetExceeded) {
            usleep(1000);
        }

        if ($refusal instanceof PreflightCode) {
            try {
                (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'), null, $budget);
                $this->fail('Expected the ceiling to refuse this document.');
            } catch (PreflightBudgetException $exhausted) {
                $this->assertSame($refusal, $exhausted->preflightCode);

                return;
            }
        }

        $this->assertNotSame([], (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'), null, $budget));
    }

    /**
     * A ceiling and a broken document stay two different answers.
     *
     * Passing the budget into the parse is what makes an exhaustion reachable from inside it, and
     * the catch-all beside it turns everything the parse throws into a
     * {@see TextExtractionException}. Ordering those two wrongly reports a decompression bomb as
     * a corrupt file, which sends whoever uploaded it to fix the wrong thing.
     *
     * The cells are *what is wrong with the document* against *whether a budget is in play*: with
     * the rethrow removed the ceiling cell answers `TextExtractionException` and the corrupt cell
     * does not change, so only one of them moves.
     *
     * @return iterable<string, array{bool, class-string<\Throwable>}>
     */
    public static function refusals(): iterable
    {
        yield 'a ceiling crossed while parsing' => [true, PreflightBudgetException::class];
        yield 'bytes that are not a document' => [false, TextExtractionException::class];
    }

    #[DataProvider('refusals')]
    public function test_a_ceiling_is_never_reported_as_a_broken_document(bool $exhausted, string $expected): void
    {
        $bytes = $exhausted
            ? PdfFixtures::bytes('single-page-letter')
            : "%PDF-1.7\nthis is not a cross-reference table\n%%EOF\n";

        $budget = new PreflightBudget($exhausted ? new PreflightLimits(maxObjects: 1) : new PreflightLimits);

        $this->expectException($expected);

        (new TcPdfTextLocator)->extract($bytes, null, $budget);
    }

    /**
     * Geometry that is not finite is this document being unreadable, and leaves as that.
     *
     * Preflight reads the object graph, not the instructions inside a content stream, so a
     * document it accepted can still multiply its way to an infinite coordinate — at which point
     * `UserSpacePoint` and `NativeRect` refuse to be built. That is an `InvalidArgumentException`,
     * which is not what this method promises and which nothing above it catches: it would reach
     * the sender as an unstructured server error in place of the per-field
     * `anchor_text_unreadable` they are owed.
     *
     * The pairing that matters is with the test above: normalising too widely would swallow a
     * budget exhaustion into the same answer, and that cell is what would catch it.
     */
    public function test_an_impossible_transform_is_reported_as_an_unreadable_document(): void
    {
        $this->expectException(TextExtractionException::class);
        $this->expectExceptionMessageMatches('/page geometry could not be interpreted/');

        (new TcPdfTextLocator)->extract(self::documentWithAnOverflowingTransform());
    }

    /**
     * A page whose CTM is multiplied past what a float can hold, then asked to show text.
     *
     * Two `cm` operators, each already at the top of the range, so the product is infinite rather
     * than merely enormous — a single one would still produce a finite, absurd coordinate, which
     * is a different case and not this one.
     */
    private static function documentWithAnOverflowingTransform(): string
    {
        $writer = new PdfFixtureWriter;

        $font = $writer->add(
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /FirstChar 32 /LastChar 126 '
            .'/Widths ['.trim(str_repeat('600 ', 95)).'] >>'
        );

        $enormous = str_repeat('9', 300);
        $transform = $enormous.' 0 0 '.$enormous.' 0 0 cm ';

        $contents = $writer->addStream('<< >>', 'q '.$transform.$transform.'BT /F1 12 Tf 10 10 Td (Hi) Tj ET Q');

        $pages = $writer->reserve();
        $page = $writer->add(
            '<< /Type /Page /Parent '.$pages.' 0 R /MediaBox [0 0 612 792] '
            .'/Resources << /Font << /F1 '.$font.' 0 R >> >> /Contents '.$contents.' 0 R >>'
        );
        $writer->put($pages, '<< /Type /Pages /Kids ['.$page.' 0 R] /Count 1 >>');

        return $writer->build($writer->add('<< /Type /Catalog /Pages '.$pages.' 0 R >>'));
    }
}
