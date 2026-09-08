<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Contracts;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\PageOverlay;

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
     * @param  array<int, PageOverlay>  $overlays  Marks to draw, positioned in native space.
     * @param  array<int, string>  $appendedDocuments  Whole PDFs whose pages are appended after
     *                                                 the source's, in the order given. Their
     *                                                 pages are numbered after the source's, so
     *                                                 an overlay can address them too.
     *
     * @throws AssemblyException When the source cannot be imported.
     */
    public function assemble(string $pdfBytes, array $overlays = [], array $appendedDocuments = []): AssembledDocument;
}
