<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use App\Domain\Preparation\Geometry\PageGeometry;

/** One page leaf with its inherited attributes already resolved. */
final readonly class FlattenedPage
{
    /**
     * @param  array<string, array<int, mixed>>  $dictionary  The page dictionary itself.
     * @param  array<string, array<int, mixed>>  $resources  Effective /Resources.
     * @param  array<int, array<int, mixed>>  $annotations  Raw /Annots entries.
     */
    public function __construct(
        public PageGeometry $geometry,
        public array $dictionary,
        public array $resources,
        public array $annotations,
    ) {}
}
