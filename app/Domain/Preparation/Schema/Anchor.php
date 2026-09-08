<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * An anchor placement *request*: the text to find, which occurrence, and an offset from it.
 *
 * Version 1.0 stores the request and nothing more. Resolution is deterministic positioned-text
 * extraction that writes the resolved rectangle into the field's `rect` before send; a missing
 * or ambiguous required anchor is an error, not a guess, and there is no regex over compressed
 * PDF bytes and no fuzzy matching (docs/HANDOFF.md section 7). Because the resolved rectangle
 * lands in `rect`, an anchored field always carries a usable rectangle, and a document is
 * self-contained after send.
 *
 * The default occurrence is 1: "the first place this text appears".
 */
final readonly class Anchor
{
    public const DEFAULT_OCCURRENCE = 1;

    public function __construct(
        public string $text,
        public int $occurrence = self::DEFAULT_OCCURRENCE,
        public ?AnchorOffset $offset = null,
    ) {
        if ($text === '') {
            throw new InvalidArgumentException('anchor.text must not be empty.');
        }

        if ($occurrence < 1) {
            throw new InvalidArgumentException('anchor.occurrence is 1-based; got '.$occurrence.'.');
        }
    }

    /**
     * @param  array{text: string, occurrence?: int, offset?: array{dx: int|float, dy: int|float}}  $anchor
     */
    public static function fromArray(array $anchor): self
    {
        return new self(
            $anchor['text'],
            isset($anchor['occurrence']) ? (int) $anchor['occurrence'] : self::DEFAULT_OCCURRENCE,
            isset($anchor['offset']) ? AnchorOffset::fromArray($anchor['offset']) : null,
        );
    }

    /**
     * @return array{text: string, occurrence: int, offset?: array{dx: int|float, dy: int|float}}
     */
    public function toArray(): array
    {
        $anchor = [
            'text' => $this->text,
            'occurrence' => $this->occurrence,
        ];

        if ($this->offset instanceof AnchorOffset) {
            $anchor['offset'] = $this->offset->toArray();
        }

        return $anchor;
    }
}
