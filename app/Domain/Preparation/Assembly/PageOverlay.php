<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

use App\Domain\Preparation\Geometry\NativeRect;

/**
 * One mark to draw on one page of an assembled document.
 *
 * Every overlay carries a 1-based page number and a rectangle in the declared native
 * coordinate space (`pt`, top-left origin, CropBox, displayed rotation). Nothing here is in
 * the engine's units, and no implementation may infer a unit from a value's magnitude
 * (AGENTS.md, "Coordinates are never guessed").
 *
 * The interface exists so the assembler can accept a heterogeneous list — a probe rectangle,
 * a field's text value, a captured signature image — without a union type that has to be
 * widened every time a new mark is needed.
 */
interface PageOverlay
{
    /** 1-based page number in the assembled output. */
    public function pageNumber(): int;

    /** Where the mark goes, in native coordinates. */
    public function rectangle(): NativeRect;
}
