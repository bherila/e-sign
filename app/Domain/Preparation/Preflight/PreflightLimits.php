<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

/**
 * Resource ceilings applied before and during parsing.
 *
 * Stage 0 numbers are placeholders informed by the fixture timings recorded in
 * docs/stage0/pdf-import.md; they are configuration, not an invariant.
 */
final readonly class PreflightLimits
{
    public function __construct(
        public int $maxBytes = 33_554_432,
        public int $maxPages = 500,
        public int $maxObjects = 100_000,
        public int $maxDecodedStreamBytes = 33_554_432,
    ) {}
}
