<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

/**
 * Resource ceilings applied before and during parsing.
 *
 * Stage 0 numbers are placeholders informed by the fixture timings recorded in
 * docs/stage0/pdf-import.md; they are configuration, not an invariant.
 *
 * The defaults here and the defaults in `config/esign.php` are the same numbers written
 * twice: this constructor is what a plain-PHPUnit test and the assembler's internal
 * re-parse get, and the config file is what a deployment tunes.
 */
final readonly class PreflightLimits
{
    /**
     * @param  int  $maxBytes  Upload ceiling, re-checked against the bytes actually received.
     * @param  int  $maxPages  Page-tree ceiling.
     * @param  int  $maxObjects  Indirect objects, charged while the document is read.
     * @param  int  $maxDecodedStreamBytes  Decoded size of any single stream.
     * @param  int  $maxDecompressedBytes  Decoded size of *every* stream in one document, added up.
     * @param  float  $timeBudgetSeconds  Wall-clock backstop for one inspection. 0 disables it.
     * @param  int  $memoryBudgetBytes  Memory-growth backstop for one inspection. 0 disables it.
     */
    public function __construct(
        public int $maxBytes = 33_554_432,
        public int $maxPages = 500,
        public int $maxObjects = 100_000,
        public int $maxDecodedStreamBytes = 33_554_432,
        // 8x the 32 MiB upload ceiling. The multiple has to be large enough that a
        // legitimate document never reaches it — a text-dense PDF's Flate content streams
        // expand by roughly an order of magnitude, and an image-heavy one barely expands
        // at all because the filter layer hands DCT and JPX payloads back untouched — and
        // small enough that the whole budget still fits inside the process. 20x would be
        // 640 MiB against the 512 MB `memory_limit` the shipped php.ini sets, so it could
        // never bind before the process died, which is the failure this ceiling exists to
        // prevent. 8x leaves half the limit for the rest of the request.
        public int $maxDecompressedBytes = 268_435_456,
        public float $timeBudgetSeconds = 30.0,
        public int $memoryBudgetBytes = 268_435_456,
    ) {}

    /**
     * The ceilings for an artifact this application generated, derived from the ones it admits
     * uploads under.
     *
     * Three of the ceilings above are upload *policy*: how large a file someone may send, how
     * many pages, how many objects. They answer "should we accept this from outside", and
     * applying them to something we produced ourselves refuses our own work — after the signers
     * have assented, which is the worst possible moment. The completion report is the case: it
     * is appended at finalization, and its length follows the envelope's recipients and events,
     * so under `max_pages=1` every agreement failed on a report the sender never chose.
     *
     * The rest are resource ceilings: they bound the cost of *reading* whatever is in hand, and
     * they still apply. The aggregate decoded ceiling is multiplied by the number of admitted
     * documents the artifact was made from, since it holds all of them.
     *
     * Structure is bounded by construction rather than by policy. Pages: the caller passes what
     * it actually wrote, which is a self-check, not a limit on the deployment. Objects: none,
     * because the artifact holds its inputs' objects — each admitted under `max_objects` — plus
     * the fixed per-page overhead the engine adds (catalog, page tree, page, form, content),
     * which is why counting it against one upload's object ceiling rejected a source that was
     * admitted at that ceiling.
     *
     * @param  int  $documents  How many admitted documents the artifact was made from.
     * @param  int|null  $pages  Pages actually written, when the caller knows; null for none.
     */
    public function forGenerated(int $documents = 1, ?int $pages = null): self
    {
        return new self(
            maxBytes: 0,
            maxPages: $pages ?? 0,
            maxObjects: 0,
            maxDecodedStreamBytes: $this->maxDecodedStreamBytes,
            maxDecompressedBytes: $this->maxDecompressedBytes > 0
                ? $this->maxDecompressedBytes * max(1, $documents)
                : $this->maxDecompressedBytes,
            timeBudgetSeconds: $this->timeBudgetSeconds,
            memoryBudgetBytes: $this->memoryBudgetBytes,
        );
    }
}
