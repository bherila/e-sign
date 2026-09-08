<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Contracts;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\OverlayRectangle;

/**
 * Imports a source PDF and writes a new document with additional marks on top.
 *
 * The source bytes are read only. Retaining the original byte-for-byte is the
 * caller's responsibility; an assembler always produces a new artifact.
 *
 * Overlay positions are in the native coordinate space
 * (see App\Domain\Preparation\Geometry\CoordinateSpace), never in the engine's units.
 */
interface PdfAssembler
{
    /**
     * @param  array<int, OverlayRectangle>  $overlays
     *
     * @throws AssemblyException When the source cannot be imported.
     */
    public function assemble(string $pdfBytes, array $overlays = []): AssembledDocument;
}
