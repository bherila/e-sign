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
use App\Domain\Preparation\TcPdf\DocumentRead;
use App\Domain\Preparation\TcPdf\Parsing\ContentStreamTokenizer;
use App\Domain\Preparation\TcPdf\Parsing\ToUnicodeCMapReader;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextExtractionException;
use Com\Tecnick\Pdf\Parser\Parser;
use Com\Tecnick\Pdf\Tcpdf;
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

        yield 'page geometry' => [static function (string $bytes): void {
            app(PdfTextLocator::class)->pages($bytes);
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
        yield 'a font whose /W array expands to a million widths' => ['cid-width-flood'];
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
     * Every loop in the tokenizer that scans bytes, each given a megabyte to scan as one token.
     *
     * Counting tokens could not see these: each is one token or none, so a tokenizer that looked
     * at the budget once per thousand tokens scanned the whole megabyte without looking at all.
     * Enumerated by loop rather than by whichever shape was reported, because the property is
     * "no loop scans unmetered", and a loop left out of this list is the next report.
     *
     * @return iterable<string, array{string}>
     */
    public static function oneTokenScans(): iterable
    {
        yield 'a run of whitespace' => [str_repeat(' ', 1_048_576)];
        yield 'one comment' => ['%'.str_repeat('x', 1_048_576)];
        yield 'one literal string' => ['('.str_repeat('x', 1_048_576).')'];
        yield 'one hexadecimal string' => ['<'.str_repeat('4', 1_048_576).'>'];
        yield 'one name' => ['/'.str_repeat('x', 1_048_576)];
        yield 'one keyword' => [str_repeat('x', 1_048_576)];
        yield 'an inline image of nothing but end-marker candidates' => ["BI\nID\n".str_repeat('EI', 524_288)];
    }

    /**
     * The same clock as above — one second per look — so the ceiling is a ceiling on looks.
     *
     * The control is a hundred kilobytes of ordinary text-showing content, read well inside the
     * ceiling: the refusals below are about bytes scanned without a look, not a ceiling too low to
     * read a page.
     */
    #[DataProvider('oneTokenScans')]
    public function test_every_byte_the_tokenizer_scans_is_charged(string $stream): void
    {
        $looks = 0;
        $clock = static function () use (&$looks): float {
            return (float) $looks++;
        };
        $limits = new PreflightLimits(timeBudgetSeconds: 100.0);

        $ordinary = str_repeat("BT /F1 12 Tf 72 720 Td (An ordinary line of text.) Tj ET\n", 1_800);
        foreach ((new ContentStreamTokenizer($ordinary, new PreflightBudget($limits, $clock)))->operations() as $operation) {
            // Drained for its cost only.
        }

        $looks = 0;

        try {
            foreach ((new ContentStreamTokenizer($stream, new PreflightBudget($limits, $clock)))->operations() as $operation) {
                // Drained for its cost only.
            }
        } catch (PreflightBudgetException $stopped) {
            $this->assertSame(PreflightCode::TimeBudgetExceeded, $stopped->preflightCode);

            return;
        }

        $this->fail('A megabyte was scanned in '.$looks.' looks at the budget: the loop that scanned it went uncharged.');
    }

    /**
     * An inline image's search is counted however it ends, including on the last operation.
     *
     * `skipInlineImage()` finds its terminator with native scans, and reported bytes only when it
     * rejected a candidate. A far-away valid terminator, or none at all, at the very end of a stream
     * left nothing after it to notice the offset had moved — so a megabyte was passed and never
     * counted.
     *
     * @return iterable<string, array{string}>
     */
    public static function inlineImageEndings(): iterable
    {
        yield 'a valid terminator a megabyte away, ending the stream' => ["BI\nID\n".str_repeat('x', 1_048_576).' EI'];
        yield 'no terminator at all' => ["BI\nID\n".str_repeat('x', 1_048_576)];
    }

    #[DataProvider('inlineImageEndings')]
    public function test_an_inline_image_search_is_charged_however_it_ends(string $stream): void
    {
        $looks = 0;
        $clock = static function () use (&$looks): float {
            return (float) $looks++;
        };
        $budget = new PreflightBudget(new PreflightLimits(timeBudgetSeconds: 1.0e9), $clock);
        $looks = 0;

        foreach ((new ContentStreamTokenizer($stream, $budget))->operations() as $operation) {
            // Drained for its cost only.
        }

        $this->assertGreaterThan(0, $looks, 'A megabyte of inline image was passed without being reported to the budget.');
    }

    /**
     * One large CMap destination is charged while it is decoded, not only once it is recorded.
     *
     * The clock counts the looks made while `utf16BeToUtf8()` is running, which isolates this site
     * from the tokenizer's own charge for reading the hex string. A megabyte of UTF-16 destination is
     * 256 of the budget's scan intervals, so decoding it reported must look at the budget a hundred
     * times; decoding it whole, as one unpack, looked none.
     */
    public function test_one_large_cmap_destination_is_charged_while_it_is_decoded(): void
    {
        $decodingLooks = 0;
        $clock = static function () use (&$decodingLooks): float {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === ToUnicodeCMapReader::class && $frame['function'] === 'utf16BeToUtf8') {
                    $decodingLooks++;

                    break;
                }
            }

            return 0.0;
        };

        $cmap = "1 beginbfchar\n<41> <".str_repeat('0041', 524_288).">\nendbfchar\n";

        (new ToUnicodeCMapReader(new PreflightBudget(new PreflightLimits, $clock)))->parse($cmap);

        $this->assertGreaterThan(100, $decodingLooks, 'A megabyte of CMap destination was decoded in '.$decodingLooks.' looks at the budget.');
    }

    /**
     * Decoding in slices changes nothing about the text a destination decodes to.
     *
     * A surrogate pair is the one shape a slice boundary could split, so one is placed exactly
     * across it: the high surrogate as the last unit of the first 4 KiB, the low one as the first
     * unit of the next. A lone high surrogate still stands alone, as it did before.
     */
    public function test_slice_decoding_keeps_a_surrogate_pair_that_straddles_a_boundary(): void
    {
        $filler = str_repeat('0041', (PreflightBudget::SCAN_BYTES_PER_TICK / 2) - 1);
        $cmap = "2 beginbfchar\n<41> <".$filler."D83DDE00>\n<42> <D83D0042>\nendbfchar\n";

        $map = (new ToUnicodeCMapReader(new PreflightBudget(new PreflightLimits)))->parse($cmap);

        $this->assertSame(str_repeat('A', (PreflightBudget::SCAN_BYTES_PER_TICK / 2) - 1)."\u{1F600}", $map[0x41]);
        $this->assertSame("\u{FFFD}B", $map[0x42]);
    }

    /**
     * A font's /ToUnicode map is charged for the entries it expands to, not the bytes it is lexed from.
     *
     * Sixteen full-width `bfrange` entries are a few hundred bytes — nothing the tokenizer's per-byte
     * charge can see — and a million map entries once expanded. The control is a map of ordinary
     * size, the full single-byte range, read under the same ceiling.
     */
    public function test_a_cmap_is_charged_for_what_it_expands_to(): void
    {
        $looks = 0;
        $clock = static function () use (&$looks): float {
            return (float) $looks++;
        };
        $limits = new PreflightLimits(timeBudgetSeconds: 100.0);

        $ordinary = (new ToUnicodeCMapReader(new PreflightBudget($limits, $clock)))
            ->parse("1 beginbfrange\n<00> <FF> <0000>\nendbfrange\n");
        $this->assertCount(256, $ordinary);

        $looks = 0;
        $wide = "16 beginbfrange\n".str_repeat("<0000> <FFFF> <0041>\n", 16)."endbfrange\n";

        try {
            (new ToUnicodeCMapReader(new PreflightBudget($limits, $clock)))->parse($wide);
        } catch (PreflightBudgetException $stopped) {
            $this->assertSame(PreflightCode::TimeBudgetExceeded, $stopped->preflightCode);

            return;
        }

        $this->fail('A '.strlen($wide).'-byte map expanded to a million entries in '.$looks.' looks at the budget.');
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
     * A page that runs past a backstop is refused, the last page included.
     *
     * This clock reads late only once the output holds an imported page, which is a budget that ran
     * out *during* the import of the one page of a one-page document. A look before each page never
     * sees that: it happens before the page, and there is no page after the last.
     */
    public function test_the_last_imported_page_is_charged_after_it_is_imported(): void
    {
        $clock = static function (): float {
            foreach (debug_backtrace() as $frame) {
                if (($frame['class'] ?? null) === TcPdfAssembler::class && $frame['function'] === 'writePages') {
                    /** @var Tcpdf $pdf */
                    $pdf = $frame['args'][0];

                    return $pdf->page->getPages() === [] ? 0.0 : 1.0e9;
                }
            }

            return 0.0;
        };

        $assembler = new TcPdfAssembler(null, resource_path('fonts'), app(PreflightLimits::class), $clock);

        try {
            $assembler->assemble(PdfFixtures::bytes('single-page-letter'));
        } catch (AssemblyException $refused) {
            $stopped = $refused->getPrevious();
            $this->assertInstanceOf(PreflightBudgetException::class, $stopped);
            $this->assertSame(PreflightCode::TimeBudgetExceeded, $stopped->preflightCode);

            return;
        }

        $this->fail('The only page ran past the time backstop during its import and was assembled anyway.');
    }

    /**
     * A document's budget starts with that document's work, not before it.
     *
     * The time and memory backstops measure from the moment a budget is built, so a budget built
     * early is charged for whatever happens in between. Assembly used to read every appended
     * document's geometry — building each one's budget — before importing the source, so in
     * finalization the completion report's budget carried the whole source import, and a review
     * PDF near the backstop could make the small report exceed a ceiling of its own. Both
     * documents fit; together, charged to each other, they did not.
     *
     * The clock records what the call stack was doing at each look, which makes the lifetime
     * visible: between building one document's budget and the next, that document's pages must
     * have been imported.
     */
    public function test_each_document_budget_starts_with_its_own_work(): void
    {
        /** @var list<string> $events */
        $events = [];
        $clock = static function () use (&$events): float {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === PreflightBudget::class && $frame['function'] === '__construct') {
                    $events[] = 'budget';

                    return 0.0;
                }

                if (($frame['class'] ?? null) === TcPdfAssembler::class && $frame['function'] === 'writePages') {
                    $events[] = 'import';

                    return 0.0;
                }
            }

            return 0.0;
        };

        $assembler = new TcPdfAssembler(null, resource_path('fonts'), app(PreflightLimits::class), $clock);
        $assembler->assemble(
            PdfFixtures::bytes('multi-page-mixed-size'),
            [],
            [PdfFixtures::bytes('single-page-letter')],
        );

        $budgets = array_keys($events, 'budget', true);
        $this->assertGreaterThan(1, count($budgets), 'The appended document did not get a budget of its own.');

        for ($i = 1; $i < count($budgets); $i++) {
            $between = array_slice($events, $budgets[$i - 1], $budgets[$i] - $budgets[$i - 1]);
            $this->assertContains(
                'import',
                $between,
                'A document\'s budget was built before the previous document had been imported, so it is '
                .'charged for work that is not its own.',
            );
        }
    }

    /**
     * A generated artifact is admitted as one, however long it is.
     *
     * The completion report is appended at finalization, after every signer has assented, and its
     * length follows the envelope's recipients and events. Admitting it through the ceilings that
     * say what a *sender* may upload made a long report refuse an agreement nobody could fix: at
     * `max_pages=1`, the multi-page report failed every envelope. Here a one-page upload — at the
     * ceiling, and legitimately so — takes a three-page appended artifact.
     */
    public function test_a_generated_artifact_is_not_held_to_the_upload_page_ceiling(): void
    {
        config(['esign.documents.max_pages' => 1]);
        $this->forgetResolvedLimits();

        $assembled = app(PdfAssembler::class)->assemble(
            PdfFixtures::bytes('single-page-letter'),
            [],
            [PdfFixtures::bytes('multi-page-mixed-size')],
        );

        $this->assertCount(1, $assembled->sourcePages);
        $this->assertCount(4, $assembled->outputPages);
    }

    /**
     * Rebuilding a document adds the engine's own objects, and they are not the uploader's.
     *
     * This is the review path — `ReviewNormalizer` assembles with nothing appended — so the output
     * was made from exactly one admitted document. tc-lib-pdf still writes its own catalog, page
     * tree, page, form and content objects around the imported ones, so holding the output to the
     * ceiling its single input was admitted under rejects a source that was accepted minutes
     * earlier. The ceiling here is the source's own object count, which is the boundary case.
     */
    public function test_a_source_at_the_object_ceiling_can_still_be_rebuilt(): void
    {
        $bytes = PdfFixtures::bytes('multi-page-mixed-size');
        $objects = app(PdfPreflight::class)->inspect($bytes)->metrics->objectCount;

        config(['esign.documents.max_objects' => $objects]);
        $this->forgetResolvedLimits();

        $assembled = app(PdfAssembler::class)->assemble($bytes);

        $this->assertCount(3, $assembled->outputPages);
    }

    /**
     * A generated artifact is admitted under the generated limits, not an upload report filtered.
     *
     * Admission used to run the ordinary upload preflight and then ignore the rejections that
     * looked like policy — page count and size — which was a second definition of "generated"
     * that had already missed one. A report with more objects than a lowered `max_objects` was
     * still refused after assent. The appended artifact here has more objects than the ceiling;
     * the source is under it.
     */
    public function test_a_generated_artifact_is_not_held_to_the_upload_object_ceiling(): void
    {
        $source = PdfFixtures::bytes('single-page-letter');
        $appended = PdfFixtures::bytes('multi-page-mixed-size');

        $sourceObjects = app(PdfPreflight::class)->inspect($source)->metrics->objectCount;
        $appendedObjects = app(PdfPreflight::class)->inspect($appended)->metrics->objectCount;
        $this->assertGreaterThan($sourceObjects, $appendedObjects, 'The premise needs an appended artifact larger than the source.');

        config(['esign.documents.max_objects' => $sourceObjects]);
        $this->forgetResolvedLimits();

        $assembled = app(PdfAssembler::class)->assemble($source, [], [$appended]);

        $this->assertCount(4, $assembled->outputPages);
    }

    /**
     * Rebuilding a document writes streams of its own, and they are not the sender's to pay for.
     *
     * Page placement and form wrappers are decoded on read-back alongside the imported content, so
     * an output allowance of "the inputs' decoded ceilings added up" refused a source admitted
     * exactly at the ceiling. The ceiling here is the source's own decoded total, on the review path.
     */
    public function test_a_source_at_the_decoded_ceiling_can_still_be_rebuilt(): void
    {
        $bytes = PdfFixtures::bytes('multi-page-mixed-size');

        $read = DocumentRead::under($bytes, new PreflightLimits);
        $read->pages();
        $decoded = $read->budget->decodedBytes();
        $this->assertGreaterThan(0, $decoded, 'The premise needs a source with decoded streams.');

        config(['esign.documents.max_decompressed_bytes' => $decoded]);
        $this->forgetResolvedLimits();

        $assembled = app(PdfAssembler::class)->assemble($bytes);

        $this->assertCount(3, $assembled->outputPages);
    }

    /**
     * The assembled output is held to what it was made from, not to one upload's ceilings.
     *
     * Finalization appends the completion report to a document that was admitted on its own. A
     * document at the page ceiling is a valid upload, so the report's page must not be what refuses
     * it — at that point every signer has already assented. The deployment path is used on purpose:
     * preflight admits both inputs, and only the read-back of the combined output is under test.
     */
    public function test_a_document_at_the_page_ceiling_still_takes_its_completion_report(): void
    {
        config(['esign.documents.max_pages' => 3]);
        $this->forgetResolvedLimits();

        $assembled = app(PdfAssembler::class)->assemble(
            PdfFixtures::bytes('multi-page-mixed-size'),
            [],
            [PdfFixtures::bytes('single-page-letter')],
        );

        $this->assertCount(3, $assembled->sourcePages);
        $this->assertCount(4, $assembled->outputPages);
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

        }

        $this->assertSame([], $offenders, implode("\n", $offenders));

        // The page-tree walk is reached through one call, in the boundary below, and it must hand
        // over that read's budget: `pages()` with no argument would walk to the page-tree reader's
        // own built-in ceiling instead of this deployment's.
        $this->assertStringContainsString(
            '->pages($this->budget)',
            (string) file_get_contents(base_path('app/Domain/Preparation/TcPdf/DocumentRead.php')),
        );
    }

    /**
     * Reading a document happens at one boundary, so admission cannot be skipped by not asking.
     *
     * The byte ceiling used to live inside preflight, which made it a ceiling on *callers who ran
     * preflight*. `DocumentRead` is where a document is admitted and parsed, so a reader that
     * bypasses preflight — the facade's placement path does — is held to it too. A parse outside
     * that boundary is how the next ceiling goes missing, so it is refused in the source.
     */
    public function test_only_the_document_read_boundary_parses_a_document(): void
    {
        $offenders = [];

        foreach ($this->domainSources() as $path => $source) {
            if (str_ends_with($path, 'TcPdf/DocumentRead.php')) {
                continue;
            }

            if (str_contains($source, 'PdfObjectGraph::parse(')) {
                $offenders[] = $path.': parses outside DocumentRead';
            }

            if (preg_match('/new PageTreeReader\(/', $source) === 1) {
                $offenders[] = $path.': walks a page tree outside DocumentRead';
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * The byte ceiling applies to whoever reads the document, not to whoever ran preflight.
     *
     * Both cells matter: the caller with a budget is told which ceiling stopped it, and the one
     * without gets the ordinary unreadable-document answer. Neither gets a full parse of a
     * document the deployment says is too large to read.
     */
    public function test_a_document_over_the_byte_ceiling_is_refused_by_extraction(): void
    {
        $bytes = PdfFixtures::bytes('multi-page-mixed-size');
        $limits = new PreflightLimits(maxBytes: strlen($bytes) - 1);
        $locator = new TcPdfTextLocator($limits);

        try {
            $locator->extract($bytes, null, new PreflightBudget($limits));
            $this->fail('A document over the byte ceiling was parsed for a caller with a budget.');
        } catch (PreflightBudgetException $refused) {
            $this->assertSame(PreflightCode::SizeLimitExceeded, $refused->preflightCode);
        }

        $this->expectException(TextExtractionException::class);

        $locator->extract($bytes);
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
