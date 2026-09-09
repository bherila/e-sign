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
 * published and again when an envelope is sent — every time, for every anchored field, whether or
 * not one already carries a receipt — and a missing or ambiguous required anchor is an error at
 * both, not a guess (docs/HANDOFF.md section 7, docs/preparation/field-schema.md).
 */
final readonly class AnchorPlacement
{
    /** `occurrence` value meaning "the text must occur exactly once in scope". */
    public const OCCURRENCE_SOLE = 'sole';

    /**
     * Largest `tolerance` a document may state, in points: PDF's own maximum page side.
     *
     * The bound is not there because a larger number would be unreasonable — though 200 inches of
     * slack on a cross-check is not a check. It is there because **the canonical form has to be
     * total**, and above roughly 1e17 it is not: PHP's `json_encode()` writes such a value as
     * `1.0e+20` and JavaScript's `JSON.stringify()` writes it as `100000000000000000000`, so the
     * two projections would produce different canonical bytes for the same document, and
     * therefore different `field_schema_sha256` — the digest every attestation binds.
     *
     * A tolerance is a distance between two positions on one page, so PDF's 14400 pt (200 inch)
     * maximum page side is the largest distance that can mean anything here, and it is five
     * orders of magnitude below where the encoders start to disagree. Bounding the property was
     * chosen over trying to canonicalise across encoders because the second has no bottom: it
     * would mean owning a number-to-string routine in two languages forever
     * (docs/preparation/field-schema.md).
     */
    public const MAX_TOLERANCE = 14400.0;

    /** Origin corner assumed when a document does not declare one. */
    public const DEFAULT_ORIGIN = AnchorOrigin::TopLeft;

    /**
     * What an anchor does to the field's rectangle when the document does not say.
     *
     * `replace` is not a guess between two equally plausible readings, which is what
     * {@see AnchorOccurrence} refuses a default for. It is the only thing an anchor in this
     * schema has ever meant: before `cross_check` existed, resolution wrote the resolved
     * rectangle into the field and that was the whole behaviour. Defaulting to it is therefore
     * what an already-stored document *said*, and reading one back has to keep saying it —
     * an envelope's `field_schema_sha256` is bound by every attestation on it, so a canonical
     * form that grew a property would invalidate the evidence for every anchored agreement
     * already signed. `cross_check` is the new, narrower, opt-in mode and must be stated.
     */
    public const DEFAULT_PLACEMENT = AnchorPlacementMode::Replace;

    /**
     * Whether the anchor text must be present. Omitted means true: an unstated requirement
     * fails closed, exactly like `FieldDefinition::$required`.
     */
    public const DEFAULT_REQUIRED = true;

    /**
     * A placement equal to the default is stored as null, so an anchor that states `replace`
     * explicitly and one that leaves it out are the same value and canonicalise to the same
     * bytes. Without that, `toArray(fromArray($x))` would not be idempotent for a document that
     * spells out the default.
     */
    public readonly ?AnchorPlacementMode $placement;

    public function __construct(
        public string $text,
        public AnchorOccurrence $occurrence,
        ?AnchorPlacementMode $placement = null,
        public ?AnchorOrigin $origin = null,
        public ?AnchorOffset $offset = null,
        public bool $required = self::DEFAULT_REQUIRED,
        public ?float $tolerance = null,
        public ?ResolvedAnchorRecord $resolved = null,
    ) {
        $this->placement = $placement === self::DEFAULT_PLACEMENT ? null : $placement;

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

            if ($tolerance > self::MAX_TOLERANCE) {
                throw new InvalidArgumentException(
                    'anchor.tolerance must be at most '.self::MAX_TOLERANCE.' pt, PDF\'s largest page side.',
                );
            }

            if ($this->mode() !== AnchorPlacementMode::CrossCheck) {
                throw new InvalidArgumentException(
                    'anchor.tolerance only means something with anchor.placement "'
                    .AnchorPlacementMode::CrossCheck->value.'"; in "'.AnchorPlacementMode::Replace->value
                    .'" mode there is no declared rectangle to compare against.',
                );
            }
        }

        if (! $required && $this->mode() === AnchorPlacementMode::CrossCheck) {
            throw new InvalidArgumentException(
                'anchor.required false means an absent anchor omits the field, which contradicts '
                .'anchor.placement "'.AnchorPlacementMode::CrossCheck->value.'": there the rectangle is '
                .'authoritative and the anchor only checks it, so an absent anchor has nothing to omit.',
            );
        }
    }

    /** The placement mode, applying the documented default. */
    public function mode(): AnchorPlacementMode
    {
        return $this->placement ?? self::DEFAULT_PLACEMENT;
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

    public function replacesRect(): bool
    {
        return $this->mode()->replacesRect();
    }

    /**
     * The same request with its effective tolerance written down.
     *
     * A `cross_check` that names no tolerance is judged against the deployment's setting, and
     * that setting can change between the publish that resolved a template and the send that
     * copies it — or differ across instances. Recording the number the check actually used means
     * the stored document says what it was checked against, instead of leaving a receipt whose
     * standard has to be inferred from whatever the configuration happens to say later.
     */
    public function withTolerance(float $tolerance): self
    {
        if ($this->tolerance !== null) {
            return $this;
        }

        return new self(
            $this->text,
            $this->occurrence,
            $this->placement,
            $this->origin,
            $this->offset,
            $this->required,
            CanonicalNumber::round($tolerance),
            $this->resolved,
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
            isset($anchor['placement']) ? AnchorPlacementMode::from($anchor['placement']) : null,
            isset($anchor['origin']) ? AnchorOrigin::from($anchor['origin']) : null,
            isset($anchor['offset']) ? AnchorOffset::fromArray($anchor['offset']) : null,
            $anchor['required'] ?? self::DEFAULT_REQUIRED,
            isset($anchor['tolerance']) ? CanonicalNumber::round((float) $anchor['tolerance']) : null,
            isset($anchor['resolved']) ? ResolvedAnchorRecord::fromArray($anchor['resolved']) : null,
        );
    }

    /**
     * Canonical export order: the declared properties in schema order, with anything that equals
     * its default omitted.
     *
     * That is the anchor object's own convention — `origin` and `offset` have always been written
     * only when present — and here it is load-bearing rather than stylistic. An anchor written
     * before `placement` and `required` existed must canonicalise to exactly the bytes it
     * canonicalised to then, because an envelope's `field_schema_sha256` is bound by every
     * attestation on it. Emitting a defaulted property would change that digest for every
     * anchored agreement already signed.
     *
     * The field's own `required` and `read_only` are always stated instead, because they were
     * always in the schema; the two rules are the same rule applied to different histories.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $anchor = [
            'text' => $this->text,
            'occurrence' => $this->occurrence->isSole() ? self::OCCURRENCE_SOLE : (int) $this->occurrence->index,
        ];

        if ($this->placement instanceof AnchorPlacementMode) {
            $anchor['placement'] = $this->placement->value;
        }

        if ($this->origin instanceof AnchorOrigin) {
            $anchor['origin'] = $this->origin->value;
        }

        if ($this->offset instanceof AnchorOffset) {
            $anchor['offset'] = $this->offset->toArray();
        }

        if ($this->required !== self::DEFAULT_REQUIRED) {
            $anchor['required'] = $this->required;
        }

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
            $this->mode()->value,
        );
    }
}
