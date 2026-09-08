<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Text\Anchor;
use App\Domain\Preparation\Text\AnchorOccurrence;
use App\Domain\Preparation\Text\AnchorOrigin;
use InvalidArgumentException;

/**
 * An anchor placement *request* carried in a field document: find this text, then place the
 * field's rectangle relative to it — plus the receipt for what was found.
 *
 * This is the serialised form of the module's anchor semantics, not a second set of rules. The
 * occurrence and origin are `App\Domain\Preparation\Text`'s own value objects, so the document
 * cannot express a placement the resolver does not implement, and there is exactly one
 * definition of what "second occurrence" or "measured from the top-right corner" means.
 *
 * Three consequences of that, all inherited deliberately:
 *
 * - **`occurrence` is required and has no default.** `Text\AnchorOccurrence` refuses a
 *   "first match wins" fallback, because silently taking the first match moves a signature box
 *   the moment the contract text changes. A document says `"sole"` (must match exactly once) or
 *   a 1-based index.
 * - **`all` is not a document value.** The resolver can place one box per occurrence, but a
 *   field in this schema is one box with one id, so a document that wants several says so with
 *   several fields.
 * - **`placement` is required and has no default.** A field always carries a rectangle, so a
 *   field that also carries an anchor holds two statements about where it goes;
 *   {@see AnchorPlacementMode} is the document saying which one wins, and an unstated
 *   precedence is refused rather than assumed.
 *
 * Resolution writes {@see ResolvedAnchorRecord} into `resolved` and, in `replace` mode, writes
 * the resolved rectangle into the field's `rect`. It happens when a template version is
 * published and again when an envelope is sent; a missing or ambiguous required anchor is an
 * error at both, not a guess (docs/HANDOFF.md section 7, docs/preparation/anchors.md).
 */
final readonly class AnchorPlacement
{
    /** `occurrence` value meaning "the text must occur exactly once in scope". */
    public const OCCURRENCE_SOLE = 'sole';

    /** Origin corner assumed when a document does not declare one. */
    public const DEFAULT_ORIGIN = AnchorOrigin::TopLeft;

    /**
     * Whether the anchor text must be present. Omitted means true: an unstated requirement
     * fails closed, exactly like `FieldDefinition::$required`.
     */
    public const DEFAULT_REQUIRED = true;

    public function __construct(
        public string $text,
        public AnchorOccurrence $occurrence,
        public AnchorPlacementMode $placement,
        public ?AnchorOrigin $origin = null,
        public ?AnchorOffset $offset = null,
        public bool $required = self::DEFAULT_REQUIRED,
        public ?float $tolerance = null,
        public ?ResolvedAnchorRecord $resolved = null,
    ) {
        if ($text === '') {
            throw new InvalidArgumentException('anchor.text must not be empty.');
        }

        if ($occurrence->isAll()) {
            throw new InvalidArgumentException(
                'anchor.occurrence "all" places one box per match, which a single field cannot represent.',
            );
        }

        if ($tolerance !== null) {
            if (! is_finite($tolerance) || $tolerance < 0.0) {
                throw new InvalidArgumentException('anchor.tolerance must be a finite, non-negative number of points.');
            }

            if ($placement !== AnchorPlacementMode::CrossCheck) {
                throw new InvalidArgumentException(
                    'anchor.tolerance only means something with anchor.placement "'
                    .AnchorPlacementMode::CrossCheck->value.'"; in "'.AnchorPlacementMode::Replace->value
                    .'" mode there is no declared rectangle to compare against.',
                );
            }
        }
    }

    /** The corner the offset is measured from, applying the documented default. */
    public function originCorner(): AnchorOrigin
    {
        return $this->origin ?? self::DEFAULT_ORIGIN;
    }

    public function offsetX(): float
    {
        return $this->offset?->dx ?? 0.0;
    }

    public function offsetY(): float
    {
        return $this->offset?->dy ?? 0.0;
    }

    /**
     * The resolver's own value object for this request, sized and scoped by its field.
     *
     * The search is scoped to the field's declared page. A field says which page it is on, the
     * page is validated against the document's page count, and an anchor that could move a field
     * to a different page would make that declaration a suggestion. Determinism is the point:
     * the same document and the same request always select the same run.
     */
    public function toAnchor(int $page, float $width, float $height): Anchor
    {
        return new Anchor(
            text: $this->text,
            occurrence: $this->occurrence,
            page: $page,
            offsetX: $this->offsetX(),
            offsetY: $this->offsetY(),
            width: $width,
            height: $height,
            origin: $this->originCorner(),
            required: $this->required,
        );
    }

    /** The same request with a resolution receipt attached. */
    public function resolvedAs(ResolvedAnchorRecord $record): self
    {
        return new self(
            $this->text,
            $this->occurrence,
            $this->placement,
            $this->origin,
            $this->offset,
            $this->required,
            $this->tolerance,
            $record,
        );
    }

    /** True when this anchor already carries a receipt written against these exact bytes. */
    public function isResolvedAgainst(string $documentSha256): bool
    {
        return $this->resolved instanceof ResolvedAnchorRecord
            && $this->resolved->describes($documentSha256);
    }

    /**
     * @param  array<string, mixed>  $anchor
     */
    public static function fromArray(array $anchor): self
    {
        /** @var array{text: string, occurrence: string|int|float, placement: string, origin?: string, offset?: array{dx: int|float, dy: int|float}, required?: bool, tolerance?: int|float, resolved?: array{document_sha256: string, page: int, occurrence_index: int, anchor_rect: array{x: int|float, y: int|float, width: int|float, height: int|float}, rect: array{x: int|float, y: int|float, width: int|float, height: int|float}}} $anchor */
        $occurrence = $anchor['occurrence'];

        return new self(
            $anchor['text'],
            $occurrence === self::OCCURRENCE_SOLE
                ? AnchorOccurrence::sole()
                : AnchorOccurrence::index((int) $occurrence),
            AnchorPlacementMode::from($anchor['placement']),
            isset($anchor['origin']) ? AnchorOrigin::from($anchor['origin']) : null,
            isset($anchor['offset']) ? AnchorOffset::fromArray($anchor['offset']) : null,
            $anchor['required'] ?? self::DEFAULT_REQUIRED,
            isset($anchor['tolerance']) ? CanonicalNumber::round((float) $anchor['tolerance']) : null,
            isset($anchor['resolved']) ? ResolvedAnchorRecord::fromArray($anchor['resolved']) : null,
        );
    }

    /**
     * Canonical export order: the required properties in schema declaration order, then the
     * optional ones. `required` is always stated, like the field's own flag of the same name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $anchor = [
            'text' => $this->text,
            'occurrence' => $this->occurrence->isSole() ? self::OCCURRENCE_SOLE : (int) $this->occurrence->index,
            'placement' => $this->placement->value,
        ];

        if ($this->origin instanceof AnchorOrigin) {
            $anchor['origin'] = $this->origin->value;
        }

        if ($this->offset instanceof AnchorOffset) {
            $anchor['offset'] = $this->offset->toArray();
        }

        $anchor['required'] = $this->required;

        if ($this->tolerance !== null) {
            $anchor['tolerance'] = CanonicalNumber::encode($this->tolerance);
        }

        if ($this->resolved instanceof ResolvedAnchorRecord) {
            $anchor['resolved'] = $this->resolved->toArray();
        }

        return $anchor;
    }

    /** A one-line description for an error message: what was asked for, in the caller's words. */
    public function describe(): string
    {
        return sprintf(
            'anchor "%s" (occurrence %s, placement %s)',
            $this->text,
            $this->occurrence->describe(),
            $this->placement->value,
        );
    }
}
