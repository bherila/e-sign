<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextExtractionException;
use Com\Tecnick\Pdf\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfBombFixtures;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * Reading one document is charged against one budget, whose limits are this deployment's.
 *
 * This is one property with several sites, which is why it is tested as an enumeration rather
 * than at whichever site last went wrong. The sites are not obvious from any one of them: three
 * classes parse a PDF, two of them walk its page tree, and each used to reach for a different
 * set of ceilings — the parser's built-in defaults, the page-tree reader's built-in 500, or the
 * configured limits — so a deployment that raised a ceiling could accept a document at upload
 * and then refuse the same bytes at the next step that touched it.
 *
 * Enumerating found a site no amount of reviewing the anchor work would have: `TcPdfAssembler`
 * re-reads geometry from bytes preflight has already inspected, with no budget and the built-in
 * page ceiling. Nothing had flagged it, because nothing named the property it belonged to.
 *
 * Two tests, and they answer different questions. The first asks whether each *known* site obeys
 * the configured ceiling. The second asks whether the list of known sites is still complete —
 * because the failure this file exists to prevent is a new reader added later that quietly
 * defaults, and no behavioural test of today's three sites can see that.
 */
final class DocumentReadBudgetTest extends TestCase
{
    /**
     * Every entry point that reads a document, with the ceiling it must honour.
     *
     * The assembler is built with preflight disabled on purpose. It runs preflight over its own
     * input first, so with a ceiling this low the refusal would come from *that* and the second
     * read — the one this cell is about — would never be reached. Measuring the wrong thing and
     * passing is exactly the failure mode being guarded against.
     *
     * @return iterable<string, array{callable(string): mixed}>
     */
    public static function documentReaders(): iterable
    {
        yield 'preflight' => [static function (string $bytes): void {
            $report = app(PdfPreflight::class)->inspect($bytes);

            if (! $report->isAccepted()) {
                throw new TextExtractionException(implode(',', $report->rejectionCodes()));
            }
        }];

        yield 'text extraction' => [static function (string $bytes): void {
            app(PdfTextLocator::class)->extract($bytes);
        }];

        yield 'assembly geometry' => [static function (string $bytes): void {
            try {
                (new TcPdfAssembler(null, resource_path('fonts'), app(PreflightLimits::class)))->assemble($bytes);
            } catch (AssemblyException $failure) {
                throw new TextExtractionException($failure->getMessage(), previous: $failure);
            }
        }];
    }

    #[DataProvider('documentReaders')]
    public function test_every_document_read_applies_the_configured_page_ceiling(callable $read): void
    {
        $bytes = PdfFixtures::bytes('multi-page-mixed-size');

        // Three pages, and a deployment that allows three. Every reader must accept it.
        config(['esign.documents.max_pages' => 3]);
        $this->forgetResolvedLimits();

        $read($bytes);

        // The same bytes, and a deployment that allows one. Every reader must refuse it — a
        // reader still holding a built-in ceiling of 500 accepts it here and says nothing.
        config(['esign.documents.max_pages' => 1]);
        $this->forgetResolvedLimits();

        $this->expectException(TextExtractionException::class);

        $read($bytes);
    }

    /**
     * Whose budget it was decides who is told the ceiling stopped them.
     *
     * A caller that supplies a budget is accounting for this document's cost and can act on the
     * difference between "too expensive for this deployment" — which a larger limit or a smaller
     * file fixes — and "this file is broken", which neither does. A caller that supplies none
     * never asked to be told, and handing it a `PreflightBudgetException` it does not catch turns
     * a bounded refusal into an unhandled error at whatever surface it is behind.
     *
     * The combination is *who owns the budget* against *what went wrong*, and every cell is
     * needed: keying only on the failure would tell every caller about ceilings, and keying only
     * on ownership would hide a broken document behind a budget the caller did supply.
     *
     * Two ceilings, because they are reached by different code. The object ceiling trips while
     * the document is parsed; the page ceiling trips while its page tree is walked, which used to
     * raise a page-tree error instead — so a caller that asked to hear about ceilings was told the
     * document was broken.
     *
     * @return iterable<string, array{bool, ?string, class-string<\Throwable>}>
     */
    public static function failureOwners(): iterable
    {
        // Caller supplied a budget, the ceiling crossed (null: none, a broken document), expected type.
        yield 'the object ceiling, on the caller\'s budget' => [true, 'objects', PreflightBudgetException::class];
        yield 'the object ceiling, on a budget the reader owns' => [false, 'objects', TextExtractionException::class];
        yield 'the page ceiling, on the caller\'s budget' => [true, 'pages', PreflightBudgetException::class];
        yield 'the page ceiling, on a budget the reader owns' => [false, 'pages', TextExtractionException::class];
        yield 'a broken document, caller has a budget' => [true, null, TextExtractionException::class];
        yield 'a broken document, no budget' => [false, null, TextExtractionException::class];
    }

    #[DataProvider('failureOwners')]
    public function test_a_ceiling_is_reported_to_whoever_asked_to_hear_about_it(
        bool $callerSupplies,
        ?string $ceiling,
        string $expected,
    ): void {
        $bytes = $ceiling !== null
            ? PdfFixtures::bytes('multi-page-mixed-size')
            : "%PDF-1.7\nthis is not a cross-reference table\n%%EOF\n";

        // Three pages, so either ceiling at one is crossed.
        $limits = match ($ceiling) {
            'objects' => new PreflightLimits(maxObjects: 1),
            'pages' => new PreflightLimits(maxPages: 1),
            default => new PreflightLimits,
        };
        $budget = $callerSupplies ? new PreflightBudget($limits) : null;

        // When the caller supplies none, the reader builds one from *its* limits, which is the
        // same ceiling — so the two cells differ only in who owns it, never in what is allowed.
        $locator = new TcPdfTextLocator($limits);

        $this->expectException($expected);

        $locator->extract($bytes, null, $budget);
    }

    /**
     * An impossible transform is an unreadable document, not an uncaught geometry error.
     *
     * Preflight reads the object graph and never the arithmetic inside a content stream, so a
     * document it accepted can still multiply its way to an infinite coordinate — at which point
     * the geometry types refuse to be built. That is an `InvalidArgumentException`, outside what
     * this port promises and caught by nothing above it.
     *
     * The premise is not assumed: the fixture is declared `accept` in the bomb corpus, so
     * `PdfPreflightLimitsTest` fails if preflight ever stops admitting it, at which point this
     * test would be proving something else.
     */
    public function test_geometry_that_cannot_exist_leaves_as_an_unreadable_document(): void
    {
        $this->expectException(TextExtractionException::class);
        $this->expectExceptionMessageMatches('/pages could not be read/');

        app(PdfTextLocator::class)->extract(PdfBombFixtures::bytes('overflowing-transform'));
    }

    /**
     * Work spent inside one operation, where a charge per operation cannot see it.
     *
     * Each fixture is one ordinary page, a megabyte decoded, accepted by every ceiling that
     * describes a document (the bomb corpus declares them `accept`). The cost is spent in a unit
     * the walk used to count once: one shown string, whose codes, text and advance cost several
     * times its length; a run of operands that yields no operation at all; a font's /ToUnicode map,
     * lexed before the page walk takes its first step.
     *
     * @return iterable<string, array{string}>
     */
    public static function workInsideOneOperation(): iterable
    {
        yield 'one shown string a megabyte long' => ['long-shown-string'];
        yield 'half a million operands and no operator' => ['operand-flood'];
        yield 'a font whose /ToUnicode map is a megabyte of entries' => ['cmap-flood'];
    }

    /**
     * The budget is consulted while that work happens, not only once it is over.
     *
     * The clock advances one second each time the budget looks at it, so the time ceiling below is
     * a ceiling on *looks*. That makes the property countable without a slow host or a large file:
     * an ordinary page is read in well under a hundred looks, and before this change each fixture
     * was read in as few — one look for the operation that did all the work.
     */
    #[DataProvider('workInsideOneOperation')]
    public function test_work_inside_one_operation_is_charged_while_it_happens(string $fixture): void
    {
        $looks = 0;
        $clock = static function () use (&$looks): float {
            return (float) $looks++;
        };
        $limits = new PreflightLimits(timeBudgetSeconds: 100.0);

        // The control: the same ceiling reads an ordinary page, so a refusal below is about the
        // fixture and not a ceiling too low to read anything.
        app(PdfTextLocator::class)->extract(PdfFixtures::bytes('single-page-letter'), null, new PreflightBudget($limits, $clock));

        $looks = 0;

        try {
            app(PdfTextLocator::class)->extract(PdfBombFixtures::bytes($fixture), null, new PreflightBudget($limits, $clock));
        } catch (PreflightBudgetException $stopped) {
            $this->assertSame(PreflightCode::TimeBudgetExceeded, $stopped->preflightCode);

            return;
        }

        $this->fail($fixture.' was read in '.$looks.' looks at the budget: the work inside one operation went uncharged.');
    }

    /**
     * A per-stream ceiling the import cannot keep is refused where it is configured.
     *
     * The import engine re-reads every document with its own parser, which this application cannot
     * hand a budget or a configuration, under a fixed per-stream ceiling. A deployment ceiling above
     * it — or 0, "no per-stream ceiling" — would accept a document at upload and have assembly refuse
     * the same bytes, with nothing on the upload to say why.
     *
     * @return iterable<string, array{int}>
     */
    public static function ceilingsTheImportCannotKeep(): iterable
    {
        yield 'one byte above the engine\'s ceiling' => [Parser::DEFAULT_MAX_STREAM_SIZE + 1];
        yield 'no per-stream ceiling at all' => [0];
    }

    #[DataProvider('ceilingsTheImportCannotKeep')]
    public function test_a_per_stream_ceiling_the_import_cannot_keep_is_refused_where_it_is_set(int $ceiling): void
    {
        // The engine's own ceiling is one assembly can keep, and a document still reads under it.
        config(['esign.documents.max_decoded_stream_bytes' => Parser::DEFAULT_MAX_STREAM_SIZE]);
        $this->forgetResolvedLimits();
        app(PdfAssembler::class)->assemble(PdfFixtures::bytes('multi-page-mixed-size'));

        config(['esign.documents.max_decoded_stream_bytes' => $ceiling]);
        $this->forgetResolvedLimits();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/max_decoded_stream_bytes is '.$ceiling.'/');

        app(PdfAssembler::class);
    }

    /**
     * The import is charged to the document it imports.
     *
     * The import engine's work cannot be metered from inside, so its pages are units the document's
     * backstops are consulted between. This clock reads late only while pages are being written, so
     * the budget can be exhausted there and nowhere else: a reader that never consults it during the
     * import assembles the document without noticing.
     */
    public function test_the_import_is_charged_to_the_document_it_imports(): void
    {
        $clock = static function (): float {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === TcPdfAssembler::class && $frame['function'] === 'writePages') {
                    return 1.0e9;
                }
            }

            return 0.0;
        };

        $assembler = new TcPdfAssembler(null, resource_path('fonts'), app(PreflightLimits::class), $clock);

        try {
            $assembler->assemble(PdfFixtures::bytes('multi-page-mixed-size'));
        } catch (AssemblyException $refused) {
            // Leaves as what the port promises, carrying the ceiling that stopped it.
            $stopped = $refused->getPrevious();
            $this->assertInstanceOf(PreflightBudgetException::class, $stopped);
            $this->assertSame(PreflightCode::TimeBudgetExceeded, $stopped->preflightCode);

            return;
        }

        $this->fail('The document was imported without its budget being consulted.');
    }

    /**
     * The list above is complete.
     *
     * A behavioural test proves the sites it knows about; it cannot prove there are no others,
     * and "another site that quietly defaults" is precisely how this property came apart. So the
     * shape is checked directly: reading a document without a budget, or walking a page tree
     * without a ceiling, is what a new site does by accident, and both are visible in the source.
     *
     * If this fails on a legitimately new reader, add it to `documentReaders()` above rather than
     * relaxing the check — that is the whole transaction this test is offering.
     */
    public function test_no_document_read_defaults_its_own_ceilings(): void
    {
        $offenders = [];

        foreach ($this->domainSources() as $path => $source) {
            // `parse($bytes)` with nothing else builds a budget carrying the built-in limits.
            if (preg_match('/PdfObjectGraph::parse\(\s*\$[A-Za-z]+\s*\)/', $source) === 1) {
                $offenders[] = $path.': parses without a budget';
            }

            // `pages()` with no argument walks to the page-tree reader's own default.
            if (preg_match('/->pages\(\s*\)/', $source) === 1) {
                $offenders[] = $path.': walks the page tree without a ceiling';
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** @return iterable<string, string> */
    private function domainSources(): iterable
    {
        $root = base_path('app');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen(base_path()) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    /** The limits are resolved from configuration, so a changed setting needs a fresh instance. */
    private function forgetResolvedLimits(): void
    {
        $this->app->forgetInstance(PreflightLimits::class);
        $this->app->forgetInstance(PdfPreflight::class);
        $this->app->forgetInstance(PdfTextLocator::class);
        $this->app->forgetInstance(PdfAssembler::class);
    }
}
