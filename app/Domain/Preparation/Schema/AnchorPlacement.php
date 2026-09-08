<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Text\AnchorOccurrence;
use App\Domain\Preparation\Text\AnchorOrigin;
use InvalidArgumentException;

/**
 * An anchor placement *request* carried in a field document: find this text, then place the
 * field's rectangle relative to it.
 *
 * This is the serialised form of the module's anchor semantics, not a second set of rules. The
 * occurrence and origin are `App\Domain\Preparation\Text`'s own value objects, so the document
 * cannot express a placement the resolver does not implement, and there is exactly one
 * definition of what "second occurrence" or "measured from the top-right corner" means.
 *
 * Two consequences of that, both inherited deliberately:
 *
 * - **`occurrence` is required and has no default.** `Text\AnchorOccurrence` refuses a
 *   "first match wins" fallback, because silently taking the first match moves a signature box
 *   the moment the contract text changes. A document says `"sole"` (must match exactly once) or
 *   a 1-based index.
 * - **`all` is not a document value.** The resolver can place one box per occurrence, but a
 *   field in this schema is one box with one id, so a document that wants several says so with
 *   several fields.
 *
 * Version 1.0 stores the request. Resolution writes the resolved rectangle into the field's
 * `rect` before send, and a missing or ambiguous required anchor is an error, not a guess
 * (docs/HANDOFF.md section 7).
 */
final readonly class AnchorPlacement
{
    /** `occurrence` value meaning "the text must occur exactly once in scope". */
    public const OCCURRENCE_SOLE = 'sole';

    /** Origin corner assumed when a document does not declare one. */
    public const DEFAULT_ORIGIN = AnchorOrigin::TopLeft;

    public function __construct(
        public string $text,
        public AnchorOccurrence $occurrence,
        public ?AnchorOrigin $origin = null,
        public ?AnchorOffset $offset = null,
    ) {
        if ($text === '') {
            throw new InvalidArgumentException('anchor.text must not be empty.');
        }

        if ($occurrence->isAll()) {
            throw new InvalidArgumentException(
                'anchor.occurrence "all" places one box per match, which a single field cannot represent.',
            );
        }
    }

    /** The corner the offset is measured from, applying the documented default. */
    public function originCorner(): AnchorOrigin
    {
        return $this->origin ?? self::DEFAULT_ORIGIN;
    }

    /**
     * @param  array{text: string, occurrence: string|int|float, origin?: string, offset?: array{dx: int|float, dy: int|float}}  $anchor
     */
    public static function fromArray(array $anchor): self
    {
        $occurrence = $anchor['occurrence'];

        return new self(
            $anchor['text'],
            $occurrence === self::OCCURRENCE_SOLE
                ? AnchorOccurrence::sole()
                : AnchorOccurrence::index((int) $occurrence),
            isset($anchor['origin']) ? AnchorOrigin::from($anchor['origin']) : null,
            isset($anchor['offset']) ? AnchorOffset::fromArray($anchor['offset']) : null,
        );
    }

    /**
     * @return array{text: string, occurrence: string|int, origin?: string, offset?: array{dx: int|float, dy: int|float}}
     */
    public function toArray(): array
    {
        $anchor = [
            'text' => $this->text,
            'occurrence' => $this->occurrence->isSole() ? self::OCCURRENCE_SOLE : (int) $this->occurrence->index,
        ];

        if ($this->origin instanceof AnchorOrigin) {
            $anchor['origin'] = $this->origin->value;
        }

        if ($this->offset instanceof AnchorOffset) {
            $anchor['offset'] = $this->offset->toArray();
        }

        return $anchor;
    }
}
