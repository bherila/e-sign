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
}
