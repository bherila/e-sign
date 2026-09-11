<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

use App\Domain\Preparation\TcPdf\Parsing\BoundedPdfParser;

/**
 * The running cost of inspecting one document, checked while it is being read.
 *
 * One instance per `inspect()` call. It is deliberately mutable and deliberately not a
 * value object: it is the shared counter that the parser subclass
 * ({@see BoundedPdfParser}) charges as it decodes
 * streams and materialises objects, and that the preflight loops tick between units of
 * work.
 *
 * Four ceilings, in the order they should bind:
 *
 *  1. **Decoded bytes, aggregated over the whole document.** The primary control. A
 *     per-stream ceiling bounds one stream and nothing else, so sixteen streams each
 *     just under it are sixteen times the ceiling of retained memory from one small
 *     upload. This budget is what that costs against.
 *  2. **Object count, charged as objects are read.** Checked while the cross-reference
 *     data is being walked and again as each indirect object materialises, so a document
 *     that *declares* millions of objects stops before it allocates them.
 *  3. **Wall clock.** A backstop.
 *  4. **Memory.** A backstop.
 *
 * The last two are backstops on purpose: they catch shapes the byte and object budgets
 * do not model — pathological tokenizer input, a filter chain that is slow rather than
 * large, an amplification inside the library that never reaches this class's counters.
 * They are not the control, because a symptom makes a poor gate: memory usage depends on
 * everything else the request has already done, and a time limit rejects a legitimate
 * document on a loaded host. Tune 1 and 2 to reject bombs; leave 3 and 4 generous.
 */
final class PreflightBudget
{
    private int $decodedBytes = 0;

    /** @var array<string, true> Object references already charged, so a re-resolve is not double counted. */
    private array $countedObjects = [];

    private int $objectCount = 0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly float $startedAt;

    private readonly int $memoryAtStart;

    /**
     * @param  (\Closure(): float)|null  $clock  Monotonic-ish seconds. Injected so a test can
     *                                           make the time budget trip without sleeping.
     */
    public function __construct(
        public readonly PreflightLimits $limits = new PreflightLimits,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->startedAt = ($this->clock)();
        $this->memoryAtStart = memory_get_usage(true);
    }

    public function elapsedSeconds(): float
    {
        return ($this->clock)() - $this->startedAt;
    }

    public function memoryDeltaBytes(): int
    {
        return max(0, memory_get_usage(true) - $this->memoryAtStart);
    }

    public function decodedBytes(): int
    {
        return $this->decodedBytes;
    }

    public function objectCount(): int
    {
        return $this->objectCount;
    }

    /**
     * How many more decoded bytes this document may produce.
     *
     * The caller passes this to the filter layer as its output cap, so an over-budget
     * stream is refused by zlib rather than decompressed and then measured.
     */
    public function remainingDecodedBytes(): int
    {
        if ($this->limits->maxDecompressedBytes <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limits->maxDecompressedBytes - $this->decodedBytes);
    }

    /**
     * @throws PreflightBudgetException
     */
    public function chargeDecodedBytes(int $bytes): void
    {
        $this->decodedBytes += max(0, $bytes);

        if ($this->limits->maxDecompressedBytes > 0 && $this->decodedBytes > $this->limits->maxDecompressedBytes) {
            $this->exhaustDecodedBytes();
        }
    }

    /**
     * Abort because the aggregate decompression ceiling has been reached.
     *
     * Called directly when the filter layer refuses a stream at the cap this budget set,
     * which is the normal path: the bytes were never produced, so there is nothing to
     * charge.
     *
     * @throws PreflightBudgetException
     */
    public function exhaustDecodedBytes(): never
    {
        throw new PreflightBudgetException(
            PreflightCode::DecompressionLimitExceeded,
            sprintf(
                'Decompressing this PDF would produce more than %d bytes of data, which is the limit for '
                .'one document. A file this small that expands this far is usually a compression bomb '
                .'rather than a real agreement. Re-export the document from the application that produced '
                .'it, or split it into smaller files, and upload it again.',
                $this->limits->maxDecompressedBytes,
            ),
        );
    }

    /**
     * Abort because the filter layer refused a stream at the cap this budget set.
     *
     * Which ceiling was the binding one is decided by the caller; the message names it, so
     * the person who uploaded the file is told the number they are up against rather than
     * how far the parser got.
     *
     * The message also says the stream may simply be damaged, and that is not hedging: a
     * capped inflate reports "output exceeded the cap" and "this is not valid deflate data"
     * identically, and the only way to tell them apart is to decode past the cap — which is
     * the exact work the cap exists to refuse. The two possibilities have the same remedy,
     * so the rejection states both instead of guessing.
     *
     * @param  bool  $aggregate  True when the whole-document budget was lower than the
     *                           per-stream ceiling and therefore the one that bound.
     *
     * @throws PreflightBudgetException
     */
    public function refuseUndecodableStream(bool $aggregate): never
    {
        throw new PreflightBudgetException(
            PreflightCode::DecompressionLimitExceeded,
            sprintf(
                'A compressed stream in this PDF could not be decompressed within the %d bytes allowed '
                .'%s: it either expands past that limit or is damaged. Re-export the document from the '
                .'application that produced it, or split it into smaller files, and upload it again.',
                $aggregate ? $this->limits->maxDecompressedBytes : $this->limits->maxDecodedStreamBytes,
                $aggregate ? 'for one document' : 'for a single stream',
            ),
        );
    }

    /**
     * Charge one indirect object as it is read.
     *
     * @throws PreflightBudgetException
     */
    public function countObject(string $reference): void
    {
        if (isset($this->countedObjects[$reference])) {
            return;
        }

        $this->countedObjects[$reference] = true;
        $this->objectCount++;

        if ($this->limits->maxObjects > 0 && $this->objectCount > $this->limits->maxObjects) {
            $this->exhaustObjects();
        }
    }

    /**
     * Refuse a cross-reference section that *declares* more objects than the ceiling
     * allows, before the entries behind it are built.
     *
     * @throws PreflightBudgetException
     */
    public function assertDeclaredObjects(int $declared): void
    {
        if ($this->limits->maxObjects > 0 && $declared > $this->limits->maxObjects) {
            $this->exhaustObjects();
        }
    }

    /**
     * @throws PreflightBudgetException
     */
    public function exhaustObjects(): never
    {
        throw new PreflightBudgetException(
            PreflightCode::ObjectLimitExceeded,
            sprintf(
                'This PDF contains or declares more than %d indirect objects, which is the limit for one '
                .'document. Split the document into smaller files, or re-export it from the application '
                .'that produced it, and upload it again.',
                $this->limits->maxObjects,
            ),
        );
    }

    /**
     * Refuse a page tree that reaches more pages than the ceiling allows.
     *
     * Raised by the page-tree walk as it reaches the page past the ceiling, so a long document
     * is refused as long rather than as a page tree that could not be read — which sends
     * whoever uploaded it to fix the wrong thing.
     *
     * @throws PreflightBudgetException
     */
    public function exhaustPages(): never
    {
        throw new PreflightBudgetException(
            PreflightCode::PageLimitExceeded,
            sprintf(
                'This PDF has more pages than the %d-page limit for one document. Split the document into '
                .'smaller files and upload it again.',
                $this->limits->maxPages,
            ),
        );
    }

    /**
     * The backstops, checked between units of work.
     *
     * @throws PreflightBudgetException
     */
    public function tick(): void
    {
        if ($this->limits->timeBudgetSeconds > 0.0 && $this->elapsedSeconds() > $this->limits->timeBudgetSeconds) {
            throw new PreflightBudgetException(
                PreflightCode::TimeBudgetExceeded,
                sprintf(
                    'Inspecting this PDF took longer than the %.0F-second limit for one document and was '
                    .'stopped. Split the document into smaller files, or re-export it from the application '
                    .'that produced it, and upload it again.',
                    $this->limits->timeBudgetSeconds,
                ),
            );
        }

        if ($this->limits->memoryBudgetBytes > 0 && $this->memoryDeltaBytes() > $this->limits->memoryBudgetBytes) {
            throw new PreflightBudgetException(
                PreflightCode::MemoryBudgetExceeded,
                sprintf(
                    'Inspecting this PDF needed more than the %d bytes of memory allowed for one document '
                    .'and was stopped. Split the document into smaller files, or re-export it from the '
                    .'application that produced it, and upload it again.',
                    $this->limits->memoryBudgetBytes,
                ),
            );
        }
    }
}
