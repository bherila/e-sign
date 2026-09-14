<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation\Isolation;

use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Isolation\ChildProcessDocumentReader;
use App\Domain\Preparation\Isolation\ChildReadFailed;
use App\Domain\Preparation\Isolation\DocumentIsolation;
use App\Domain\Preparation\Isolation\DocumentIsolationUnavailable;
use App\Domain\Preparation\Isolation\IsolationMode;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextExtractionException;
use Illuminate\Process\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfBombFixtures;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * Document reads in a real child process (docs/adr/0006).
 *
 * Four questions, and each needs a real child rather than a double, because a double of
 * `proc_open` proves nothing about the boundary:
 *
 * 1. **Parity.** Every port returns in process mode exactly what it returns in-process.
 * 2. **The port contracts survive the boundary.** Who is told about a ceiling, and the caller's
 *    budget being charged for work done in the child.
 * 3. **The hard limits are hard.** A child that never answers is stopped at its deadline, and one
 *    that allocates without limit is stopped by its own `memory_limit` — each reported as the named
 *    ceiling, and neither depending on any code reporting its work.
 * 4. **Only a trustworthy child is used.** A binary that cannot run, runs the wrong PHP, or ignores
 *    `-d memory_limit` is not trusted: `auto` falls back and `process` refuses.
 */
final class ProcessIsolationTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/isolation';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------- parity

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        yield 'a plain page' => ['single-page-letter'];
        yield 'rotated pages' => ['rotated-pages'];
        yield 'mixed page sizes' => ['multi-page-mixed-size'];
    }

    #[DataProvider('documents')]
    public function test_preflight_in_a_child_reports_what_it_reports_in_process(string $fixture): void
    {
        $bytes = PdfFixtures::bytes($fixture);
        $inProcess = (new TcPdfPreflight(app(PreflightLimits::class)))->inspect($bytes);

        $this->useProcessIsolation();
        $isolated = app(PdfPreflight::class)->inspect($bytes);

        $this->assertSame($inProcess->rejectionCodes(), $isolated->rejectionCodes());
        $this->assertEquals($inProcess->pages, $isolated->pages);
        $this->assertEquals($inProcess->findings, $isolated->findings);
    }

    public function test_a_rejection_in_a_child_is_the_same_rejection(): void
    {
        $this->useProcessIsolation();

        $report = app(PdfPreflight::class)->inspect(PdfFixtures::bytes('encrypted-aes128'));

        $this->assertSame(['encrypted'], $report->rejectionCodes());
    }

    #[DataProvider('documents')]
    public function test_extraction_in_a_child_returns_what_it_returns_in_process(string $fixture): void
    {
        $bytes = PdfFixtures::bytes($fixture);
        $inProcess = (new TcPdfTextLocator(app(PreflightLimits::class)))->extract($bytes);

        $this->useProcessIsolation();

        $this->assertEquals($inProcess, app(PdfTextLocator::class)->extract($bytes));
        $this->assertEquals(
            (new TcPdfTextLocator(app(PreflightLimits::class)))->extract($bytes, 1),
            app(PdfTextLocator::class)->extract($bytes, 1),
        );
    }

    public function test_assembly_in_a_child_produces_what_it_produces_in_process(): void
    {
        $source = PdfFixtures::bytes('multi-page-mixed-size');
        $appended = PdfFixtures::bytes('single-page-letter');
        $limits = app(PreflightLimits::class);
        $inProcess = (new TcPdfAssembler(new TcPdfPreflight($limits), resource_path('fonts'), $limits))->assemble($source, [], [$appended]);

        $this->useProcessIsolation();
        $isolated = app(PdfAssembler::class)->assemble($source, [], [$appended]);

        $this->assertEquals($inProcess->sourcePages, $isolated->sourcePages);
        $this->assertEquals($inProcess->outputPages, $isolated->outputPages);
        $this->assertCount(4, $isolated->outputPages);
    }

    public function test_an_assembly_refusal_in_a_child_keeps_its_type_and_its_ceiling(): void
    {
        config(['esign.documents.max_pages' => 1]);
        $this->useProcessIsolation();

        try {
            app(PdfAssembler::class)->assemble(PdfFixtures::bytes('multi-page-mixed-size'));
            $this->fail('A document over the page ceiling was assembled.');
        } catch (AssemblyException $refused) {
            // The in-process assembler refuses this at its own preflight, as unsupported.
            $this->assertStringContainsString('page', strtolower($refused->getMessage()));
        }
    }

    // ------------------------------------------------------------------- the port contracts

    /**
     * Who is told about a ceiling is decided by who owns the budget, on both sides of the boundary.
     *
     * @return iterable<string, array{bool, class-string<\Throwable>}>
     */
    public static function ceilingOwners(): iterable
    {
        yield 'the caller owns the budget' => [true, PreflightBudgetException::class];
        yield 'the reader owns the budget' => [false, TextExtractionException::class];
    }

    #[DataProvider('ceilingOwners')]
    public function test_a_ceiling_reached_in_the_child_is_reported_to_whoever_owns_the_budget(bool $callerOwns, string $expected): void
    {
        config(['esign.documents.max_objects' => 1]);
        $this->useProcessIsolation();

        $budget = $callerOwns ? new PreflightBudget(app(PreflightLimits::class)) : null;

        try {
            app(PdfTextLocator::class)->extract(PdfFixtures::bytes('multi-page-mixed-size'), null, $budget);
            $this->fail('A document over the object ceiling was read.');
        } catch (\Throwable $refused) {
            $this->assertInstanceOf($expected, $refused);

            if ($refused instanceof PreflightBudgetException) {
                $this->assertSame(PreflightCode::ObjectLimitExceeded, $refused->preflightCode);
            }
        }
    }

    public function test_work_done_in_the_child_is_charged_to_the_callers_budget(): void
    {
        $this->useProcessIsolation();
        $budget = new PreflightBudget(app(PreflightLimits::class));

        app(PdfTextLocator::class)->extract(PdfFixtures::bytes('multi-page-mixed-size'), null, $budget);

        $this->assertGreaterThan(0, $budget->objectCount(), 'The child\'s objects were not charged back.');
        $this->assertGreaterThan(0, $budget->decodedBytes(), 'The child\'s decoded bytes were not charged back.');
    }

    /**
     * The child is stopped by what remains of the caller's allowance, before its work is done.
     *
     * Refusing *eventually* is not the property: a child handed a fresh copy of the limits reads the
     * whole document, and the charge-back afterwards still refuses — with the objects already
     * materialised in the child. So the caller's count is asserted unchanged, which only holds when
     * the child refused on the remainder and nothing was charged back. And the refusal names the
     * ceiling the deployment set (3), not the remainder the child was given (1).
     */
    public function test_the_child_is_held_to_what_is_left_of_the_callers_budget(): void
    {
        $this->useProcessIsolation();

        // A budget that has already spent all but one of its objects.
        $budget = new PreflightBudget(new PreflightLimits(maxObjects: 3));
        $budget->absorb(0, 2);

        try {
            app(PdfTextLocator::class)->extract(PdfFixtures::bytes('multi-page-mixed-size'), null, $budget);
            $this->fail('A document was read past what remained of the caller\'s object allowance.');
        } catch (PreflightBudgetException $refused) {
            $this->assertSame(PreflightCode::ObjectLimitExceeded, $refused->preflightCode);
            $this->assertStringContainsString('more than 3 indirect objects', $refused->getMessage());
            $this->assertSame(2, $budget->objectCount(), 'The child read the document and its work was charged back before refusing.');
        }
    }

    // ------------------------------------------------------------------------ the hard limits

    public function test_a_child_that_never_answers_is_stopped_at_its_deadline(): void
    {
        $reader = $this->readerRunning('sleep.php');
        $startedAt = microtime(true);

        try {
            $reader->read(['operation' => 'preflight', 'bytes' => ''], new PreflightLimits(timeBudgetSeconds: 0.5));
            $this->fail('A child that never answered was waited on.');
        } catch (PreflightBudgetException $stopped) {
            $this->assertSame(PreflightCode::TimeBudgetExceeded, $stopped->preflightCode);
        }

        $this->assertLessThan(10.0, microtime(true) - $startedAt, 'The child was not killed at its deadline.');
    }

    public function test_a_child_that_allocates_without_limit_is_stopped_by_its_own_memory_limit(): void
    {
        $reader = $this->readerRunning('exhaust-memory.php');

        try {
            $reader->read(['operation' => 'preflight', 'bytes' => ''], new PreflightLimits(memoryBudgetBytes: 32 * 1_048_576));
            $this->fail('A child that allocated without limit was not stopped.');
        } catch (PreflightBudgetException $stopped) {
            $this->assertSame(PreflightCode::MemoryBudgetExceeded, $stopped->preflightCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function brokenChildren(): iterable
    {
        yield 'it exits with a failure status' => ['exit-nonzero.php'];
        yield 'it answers with something that is not a message' => ['garbage-output.php'];
        yield 'it answers with a class outside the allowlist' => ['disallowed-class.php'];
    }

    #[DataProvider('brokenChildren')]
    public function test_a_child_that_fails_otherwise_is_a_read_failure_not_a_ceiling(string $entrypoint): void
    {
        $this->expectException(ChildReadFailed::class);

        $this->readerRunning($entrypoint)->read(['operation' => 'preflight', 'bytes' => ''], new PreflightLimits);
    }

    public function test_a_named_ceiling_in_the_child_still_trips_before_the_hard_limit(): void
    {
        $this->useProcessIsolation();
        config(['esign.documents.max_decompressed_bytes' => 2 * 1_048_576]);
        $this->forgetReaders();

        // Inflates to 12 MiB against a 2 MiB ceiling: the child's own budget names it long before
        // the hard memory limit (the backstop plus headroom) is anywhere near.
        $report = app(PdfPreflight::class)->inspect(PdfBombFixtures::bytes('single-stream-bomb'));

        $this->assertSame(['decompression_limit_exceeded'], $report->rejectionCodes());
    }

    // ------------------------------------------------------------------------- the resolution

    public function test_a_binary_that_cannot_run_falls_back_under_auto_and_refuses_under_process(): void
    {
        $missing = sys_get_temp_dir().'/esign-no-such-php-'.bin2hex(random_bytes(4));

        $auto = $this->isolation(IsolationMode::Auto, $missing);
        $this->assertNull($auto->reader());
        $this->assertSame(IsolationMode::InProcess, $auto->describe()['effective']);
        $this->assertStringContainsString('not an executable file', (string) $auto->describe()['reason']);

        $this->expectException(DocumentIsolationUnavailable::class);

        $this->isolation(IsolationMode::Process, $missing)->reader();
    }

    /**
     * A binary is trusted only if it runs this PHP and honours the memory limit it is given.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function untrustworthyBinaries(): iterable
    {
        yield 'it runs a different PHP' => ['7.4|64M', 'runs PHP 7.4'];
        yield 'it ignores -d memory_limit' => [PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'|128M', 'did not honour -d memory_limit'];
    }

    #[DataProvider('untrustworthyBinaries')]
    public function test_a_binary_that_cannot_be_trusted_is_not_used(string $answers, string $reason): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'esign-fake-php-');
        $this->temporaryFiles[] = $fake;
        file_put_contents($fake, "#!/bin/sh\necho '".$answers."'\n");
        chmod($fake, 0755);

        $isolation = $this->isolation(IsolationMode::Auto, $fake);

        $this->assertNull($isolation->reader());
        $this->assertStringContainsString($reason, (string) $isolation->describe()['reason']);
    }

    public function test_this_test_runs_own_php_is_trusted(): void
    {
        $isolation = $this->isolation(IsolationMode::Auto, PHP_BINARY);

        $this->assertInstanceOf(ChildProcessDocumentReader::class, $isolation->reader());
        $this->assertSame(IsolationMode::Process, $isolation->describe()['effective']);
    }

    public function test_in_process_mode_never_starts_a_child(): void
    {
        $isolation = $this->isolation(IsolationMode::InProcess, '/definitely/not/php');

        $this->assertNull($isolation->reader());
        $this->assertSame(IsolationMode::InProcess, $isolation->describe()['effective']);
    }

    // ------------------------------------------------------------------------------ helpers

    private function useProcessIsolation(): void
    {
        config([
            'esign.documents.isolation.mode' => 'process',
            'esign.documents.isolation.php_binary' => PHP_BINARY,
        ]);

        $this->forgetReaders();
    }

    private function forgetReaders(): void
    {
        foreach ([DocumentIsolation::class, PreflightLimits::class, PdfPreflight::class, PdfTextLocator::class, PdfAssembler::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function readerRunning(string $entrypoint): ChildProcessDocumentReader
    {
        return new ChildProcessDocumentReader(app(Factory::class), PHP_BINARY, 0, 0.0, self::FIXTURES.'/'.$entrypoint);
    }

    private function isolation(IsolationMode $mode, string $binary): DocumentIsolation
    {
        return new DocumentIsolation($mode, app(Factory::class), $binary, 0, 0.0);
    }
}
