<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/**
 * An anchored field placement: find this exact string, then place a box relative to it.
 *
 * Matching is an exact, case-sensitive substring match on decoded run text. There is
 * no fuzzy matching, no normalisation of whitespace, and no regular expression over
 * raw or compressed PDF bytes.
 */
final readonly class Anchor
{
    /**
     * @param  string  $text  Exact string to find. Must be non-empty.
     * @param  int|null  $page  1-based page to search, or null for every page.
     * @param  float  $offsetX  Native-unit offset from the chosen origin corner, x to the right.
     * @param  float  $offsetY  Native-unit offset from the chosen origin corner, y downwards.
     * @param  float  $width  Width of the placed box in native units.
     * @param  float  $height  Height of the placed box in native units.
     * @param  bool  $required  When false, zero matches resolves to an empty result instead of throwing.
     */
    public function __construct(
        public string $text,
        public AnchorOccurrence $occurrence,
        public ?int $page = null,
        public float $offsetX = 0.0,
        public float $offsetY = 0.0,
        public float $width = 0.0,
        public float $height = 0.0,
        public AnchorOrigin $origin = AnchorOrigin::TopLeft,
        public bool $required = true,
    ) {
        if ($text === '') {
            throw new \InvalidArgumentException('An anchor needs a non-empty search string.');
        }

        if ($page !== null && $page < 1) {
            throw new \InvalidArgumentException('Anchor page numbers are 1-based.');
        }

        if ($width < 0.0 || $height < 0.0) {
            throw new \InvalidArgumentException('Anchor box dimensions must not be negative.');
        }
    }

    public function describe(): string
    {
        return sprintf(
            'anchor "%s" (occurrence %s, page %s)',
            $this->text,
            $this->occurrence->describe(),
            $this->page === null ? 'any' : (string) $this->page,
        );
    }
}
