<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Preflight\PreflightCode;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfBombFixtures;

/**
 * Resource ceilings during preflight — security review U-1 and U-2, issue #88.
 *
 * Plain PHPUnit, like {@see PdfPreflightTest}: the preparation domain has no framework
 * dependency, and these tests inflate real data, so booting Laravel forty times would only
 * make them slower.
 *
 * Every test runs against the profile below rather than the shipped defaults. The
 * mechanism is what is under test, and proving it at 256 MiB would mean inflating 256 MiB
 * in CI to learn something a 8 MiB fixture already shows. That the shipped numbers are the
 * numbers the documentation claims is asserted in
 * {@see DocumentIntakeTest} against `config/esign.php`.
 */
final class PdfPreflightLimitsTest extends TestCase
{
    /** The whole-document decompression budget these fixtures are sized against. */
    private const AGGREGATE_BYTES = 4 * 1_048_576;

    /** A per-stream ceiling every bomb stream sits comfortably under. */
    private const PER_STREAM_BYTES = 2 * 1_048_576;

    private const MAX_OBJECTS = 1_000;

    private static function limits(): PreflightLimits
    {
        return new PreflightLimits(
            maxObjects: self::MAX_OBJECTS,
            maxDecodedStreamBytes: self::PER_STREAM_BYTES,
            maxDecompressedBytes: self::AGGREGATE_BYTES,
        );
    }

    #[DataProviderExternal(PdfBombFixtures::class, 'all')]
    public function test_every_fixture_is_classified_as_the_corpus_manifest_declares(string $name, array $entry): void
    {
        $report = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes($name));

        $this->assertSame(
            $entry['expected_preflight'] === 'accept',
            $report->isAccepted(),
            $name.': expected '.$entry['expected_preflight'].', got '
            .($report->isAccepted() ? 'accept' : 'reject with ['.implode(', ', $report->rejectionCodes()).']'),
        );

        $this->assertSame($entry['expected_rejections'], $report->rejectionCodes(), $name);
    }

    #[DataProviderExternal(PdfBombFixtures::class, 'all')]
    public function test_every_refusal_names_the_limit_and_says_what_to_do(string $name, array $entry): void
    {
        if ($entry['expected_preflight'] === 'accept') {
            $this->markTestSkipped($name.' is the control document; it is not refused.');
        }

        $message = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes($name))->rejectionMessage();

        $this->assertMatchesRegularExpression(
            '/\bupload it again\b/',
            $message,
            $name.' must tell the uploader what to do next, not only that it failed.',
        );
        $this->assertMatchesRegularExpression(
            '/\b(re-export|split)\b/i',
            $message,
            $name.' must name a remedy.',
        );
    }

    /**
     * The finding U-1 is about, stated as a test.
     *
     * The fixture's streams are 1 MiB each against a 2 MiB per-stream ceiling, so the
     * ceiling that existed before this change never binds and the document is accepted with
     * all 16 MiB of it retained. Only the running total refuses it.
     */
    public function test_the_aggregate_budget_is_what_rejects_many_small_streams(): void
    {
        $bytes = PdfBombFixtures::bytes('many-small-streams-bomb');

        $perStreamOnly = new PreflightLimits(
            maxObjects: self::MAX_OBJECTS,
            maxDecodedStreamBytes: self::PER_STREAM_BYTES,
            // 0 is "no aggregate budget": exactly the behaviour this change replaces.
            maxDecompressedBytes: 0,
            memoryBudgetBytes: 0,
        );

        $withoutAggregate = (new TcPdfPreflight($perStreamOnly))->inspect($bytes);

        $this->assertTrue(
            $withoutAggregate->isAccepted(),
            'The per-stream ceiling alone accepts this document. If it does not, the fixture no longer '
            .'demonstrates the finding and must be resized.',
        );

        $withAggregate = (new TcPdfPreflight(self::limits()))->inspect($bytes);

        $this->assertFalse($withAggregate->isAccepted());
        $this->assertSame(
            [PreflightCode::DecompressionLimitExceeded->value],
            $withAggregate->rejectionCodes(),
        );
        $this->assertStringContainsString((string) self::AGGREGATE_BYTES, $withAggregate->rejectionMessage());
    }

    /**
     * The budget bounds memory, not just the verdict.
     *
     * `memory_get_peak_usage(true)` is the allocator's high-water mark for the whole
     * process, so this compares two figures taken in the same process in a fixed order and
     * leaves a generous margin: the assertion is "the rejected parse stays within a small
     * multiple of its own budget", not a byte count.
     */
    public function test_a_rejected_parse_stays_within_a_bounded_amount_of_memory(): void
    {
        $bytes = PdfBombFixtures::bytes('many-small-streams-bomb');

        $before = memory_get_peak_usage(true);
        $report = (new TcPdfPreflight(self::limits()))->inspect($bytes);
        $delta = memory_get_peak_usage(true) - $before;

        $this->assertFalse($report->isAccepted());

        // Three times the budget: the decoded payloads, the parser's own token arrays, and
        // the allocator's 2 MB granularity. Measured on this fixture: 8 MiB bounded against
        // a 4 MiB budget, and 24 MiB with no aggregate budget at all — so the bound sits
        // halfway between what the fix costs and what it prevents, which is where a
        // non-flaky assertion belongs.
        $this->assertLessThan(
            3 * self::AGGREGATE_BYTES,
            $delta,
            sprintf('A rejected parse used %d bytes against a %d-byte budget.', $delta, self::AGGREGATE_BYTES),
        );
    }

    /**
     * A single stream cannot spend the budget it was refused.
     *
     * The fixture inflates to 12 MiB. If the cap were applied after decoding rather than by
     * the filter layer, the bytes would exist before anyone measured them.
     */
    public function test_a_single_stream_bomb_is_refused_without_being_inflated(): void
    {
        $before = memory_get_peak_usage(true);
        $report = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes('single-stream-bomb'));
        $delta = memory_get_peak_usage(true) - $before;

        $this->assertSame([PreflightCode::DecompressionLimitExceeded->value], $report->rejectionCodes());
        $this->assertLessThan(
            3 * self::AGGREGATE_BYTES,
            $delta,
            'A 12 MiB stream was materialised before it was refused.',
        );
    }

    /**
     * U-2: the object ceiling is consulted while the cross-reference data is read.
     *
     * The fixture declares five million entries in twenty bytes. A check that ran after the
     * parse would have built them first; the object count in the report proves nothing of
     * the kind happened.
     */
    public function test_the_object_ceiling_is_charged_during_parsing_not_after(): void
    {
        $report = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes('object-count-bomb'));

        $this->assertSame([PreflightCode::ObjectLimitExceeded->value], $report->rejectionCodes());
        $this->assertStringContainsString((string) self::MAX_OBJECTS, $report->rejectionMessage());
        $this->assertLessThan(
            100,
            $report->metrics->objectCount,
            'The parse should have stopped at the declaration, not after five million entries were built.',
        );
    }

    public function test_a_deeply_nested_object_graph_fails_closed(): void
    {
        $report = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes('deep-nesting-bomb'));

        $this->assertFalse($report->isAccepted());
        $this->assertSame([PreflightCode::Unparseable->value], $report->rejectionCodes());
        $this->assertStringContainsString('nesting depth', $report->rejectionMessage());
    }

    public function test_a_large_but_legitimate_document_is_still_accepted(): void
    {
        $report = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes('large-legitimate-control'));

        $this->assertTrue($report->isAccepted(), 'The control document must pass: '.$report->rejectionMessage());
        $this->assertCount(40, $report->pages);
        $this->assertSame([], $report->rejections());
    }

    public function test_the_aggregate_ceiling_is_configurable(): void
    {
        $bytes = PdfBombFixtures::bytes('large-legitimate-control');

        $generous = (new TcPdfPreflight(self::limits()))->inspect($bytes);
        $this->assertTrue($generous->isAccepted());

        $mean = new PreflightLimits(maxDecompressedBytes: 4_096);
        $this->assertSame(
            [PreflightCode::DecompressionLimitExceeded->value],
            (new TcPdfPreflight($mean))->inspect($bytes)->rejectionCodes(),
            'The same document must be refused once the ceiling is lowered below what it needs.',
        );
    }

    public function test_the_object_ceiling_is_configurable(): void
    {
        $bytes = PdfBombFixtures::bytes('large-legitimate-control');

        $this->assertTrue((new TcPdfPreflight(self::limits()))->inspect($bytes)->isAccepted());

        $this->assertSame(
            [PreflightCode::ObjectLimitExceeded->value],
            (new TcPdfPreflight(new PreflightLimits(maxObjects: 4)))->inspect($bytes)->rejectionCodes(),
        );
    }

    /**
     * The wall-clock backstop, with the clock injected rather than waited on.
     *
     * The clock advances a second per reading, so the budget trips a few units of work into
     * a parse that really does take milliseconds.
     */
    public function test_the_time_budget_aborts_a_slow_parse(): void
    {
        $tick = 0.0;
        $clock = static function () use (&$tick): float {
            $tick += 1.0;

            return $tick;
        };

        $report = (new TcPdfPreflight(new PreflightLimits(timeBudgetSeconds: 5.0), $clock))
            ->inspect(PdfBombFixtures::bytes('large-legitimate-control'));

        $this->assertFalse($report->isAccepted());
        $this->assertSame([PreflightCode::TimeBudgetExceeded->value], $report->rejectionCodes());
        $this->assertStringContainsString('5-second limit', $report->rejectionMessage());
    }

    public function test_the_time_budget_does_not_fire_on_a_document_that_finishes_inside_it(): void
    {
        $clock = static fn (): float => 100.0;

        $report = (new TcPdfPreflight(new PreflightLimits(timeBudgetSeconds: 1.0), $clock))
            ->inspect(PdfBombFixtures::bytes('large-legitimate-control'));

        $this->assertTrue($report->isAccepted());
    }

    /**
     * The memory backstop. Set to a figure any parse exceeds, to prove the gate is wired;
     * the shipped value is deliberately far above anything a real document needs.
     */
    public function test_the_memory_budget_is_a_backstop_that_can_abort_a_parse(): void
    {
        $report = (new TcPdfPreflight(new PreflightLimits(memoryBudgetBytes: 1)))
            ->inspect(PdfBombFixtures::bytes('many-small-streams-bomb'));

        $this->assertFalse($report->isAccepted());
        $this->assertSame([PreflightCode::MemoryBudgetExceeded->value], $report->rejectionCodes());
    }

    public function test_the_backstops_can_be_switched_off(): void
    {
        $report = (new TcPdfPreflight(new PreflightLimits(timeBudgetSeconds: 0.0, memoryBudgetBytes: 0)))
            ->inspect(PdfBombFixtures::bytes('large-legitimate-control'));

        $this->assertTrue($report->isAccepted());
    }

    public function test_a_budget_refusal_is_never_reported_as_a_generic_parse_failure(): void
    {
        foreach (['single-stream-bomb', 'many-small-streams-bomb', 'object-count-bomb'] as $name) {
            $codes = (new TcPdfPreflight(self::limits()))->inspect(PdfBombFixtures::bytes($name))->rejectionCodes();

            $this->assertNotContains(
                PreflightCode::Unparseable->value,
                $codes,
                $name.' must say which ceiling it hit, not "this file could not be parsed".',
            );
        }
    }
}
