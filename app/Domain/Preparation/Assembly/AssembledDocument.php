<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

use App\Domain\Preparation\Geometry\PageGeometry;

/**
 * The bytes produced by assembly, plus the geometry of the pages that were written.
 *
 * Output page geometry is reported separately from input page geometry so that a
 * caller can assert that preview geometry and final geometry agree instead of
 * assuming it.
 */
final readonly class AssembledDocument
{
    /**
     * @param  array<int, PageGeometry>  $sourcePages
     * @param  array<int, PageGeometry>  $outputPages
     * @param  array<int, string>  $engineWarnings
     */
    public function __construct(
        public string $bytes,
        public array $sourcePages,
        public array $outputPages,
        public array $engineWarnings,
        public float $elapsedSeconds,
        public int $peakMemoryBytes,
    ) {}
}
