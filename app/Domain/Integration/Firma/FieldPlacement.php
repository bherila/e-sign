<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Geometry\FacadeCoordinateTranslator;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\CanonicalNumber;
use App\Domain\Preparation\Schema\CoordinateSpaceDeclaration;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Preparation\Text\Anchor;
use App\Domain\Preparation\Text\AnchorOccurrence;
use App\Domain\Preparation\Text\AnchorOrigin;
use App\Domain\Preparation\Text\AnchorResolutionException;
use App\Domain\Preparation\Text\AnchorResolver;
use App\Domain\Preparation\Text\ResolvedAnchor;
use App\Domain\Preparation\Text\TextExtractionException;
use App\Domain\Preparation\Text\TextRun;
use Closure;
use Illuminate\Support\Str;

/**
 * Turns the profile's `recipients[]` and `fields[]` into a native field schema.
 *
 * This is the one place a percentage becomes a point. Three rules govern it and none of them
 * is negotiable:
 *
 * 1. **The convention is declared, not detected.** {@see FirmaProfile::COORDINATES} says the
 *    profile speaks percentages of the displayed page, and
 *    {@see FacadeCoordinateTranslator} performs that exact conversion. Nothing looks at
 *    whether a number happens to be under 100 — `{"x": 60}` is 60% here and 60 pt on a
 *    different profile, and the two are indistinguishable without the declaration
 *    (AGENTS.md; `docs/preparation/coordinate-space.md`).
 * 2. **Out of range is refused, never reinterpreted.** The upstream schema constrains
 *    `x`, `y`, `width` and `height` to `0..100` with `x + width <= 100` and
 *    `y + height <= 100`. Its own examples violate that and read as PDF points
 *    (disagreement D4). The schema wins: `{"x": 100, "y": 500}` is rejected with a message
 *    saying so, rather than quietly treated as points and placed 500 pt down a page that is
 *    792 pt tall.
 * 3. **Percentages are of the *displayed* page.** A `/Rotate 90` Letter page is 792 pt wide
 *    as displayed, so 50% of `x` is 396 pt and not 306 pt. {@see PageGeometry} already
 *    accounts for rotation and for a CropBox that is not at the origin.
 *
 * ## Anchors
 *
 * `fields[].anchor` places a field relative to text in the document instead of at a fixed
 * coordinate. The text is located with {@see PdfTextLocator} — a real content-stream parse,
 * not a regex over the file — and resolved by {@see AnchorResolver}, which returns the
 * rectangle in native space. The field's own `position.width` and `position.height` are
 * still required, in percent, because an anchor says *where* a field goes and never how big
 * it is.
 *
 * This is a **documented compatibility extension**, not upstream's shape. Upstream places
 * anchors through a separate `anchor_tags[]` collection with its own type enum and its own
 * `offset_units: percent|pixels`; that collection is unsupported
 * ({@see UnsupportedOptions}), because reproducing a second coordinate system with a second
 * unit switch is exactly how a signature ends up in the wrong place. Here there is one unit,
 * the profile's own: offsets are percentages of the page, like every other number in the
 * request.
 *
 * An anchor that matches nothing, or matches more than the caller allowed for, is an error.
 * A field placed at a default position because its anchor was not found is a field nobody
 * agreed to sign in that spot.
 *
 * Resolution happens here, on the way in, and the emitted field carries both halves of the
 * result: the rectangle in `rect`, and the request plus its receipt in `anchor`. That receipt
 * names the revision digest the text was located in, so the envelope's own send-time resolution
 * recognises the work as already done against those exact bytes and does not repeat it — and, if
 * the envelope were somehow pointed at different bytes, would notice. The offsets are converted
 * to native points on the way through, because the stored anchor is a native-schema anchor and
 * this profile's percentages stop at this boundary.
 */
final readonly class FieldPlacement
{
    /** Upstream's `designation` values. Only one of them means anything here. */
    public const DESIGNATIONS = ['Signer', 'Approver', 'CC'];

    public function __construct(
        private PdfTextLocator $text,
        private AnchorResolver $anchors,
        private FacadeCoordinateTranslator $coordinates,
    ) {}

    /**
     * Resolve the inbound recipients into schema parties.
     *
     * @param  list<array<string, mixed>>  $recipients  Validated request recipients.
     * @return list<PlannedRecipient>
     *
     * @throws FirmaException
     */
    public function recipients(array $recipients): array
    {
        $planned = [];

        foreach (array_values($recipients) as $index => $recipient) {
            $designation = (string) ($recipient['designation'] ?? FirmaProfile::DESIGNATION);

            if ($designation !== FirmaProfile::DESIGNATION) {
                // Upstream lets a party be an approver or a copy recipient. Neither is
                // modelled here, and accepting one would mean either treating an approver as
                // a signer — binding them to an agreement they only meant to review — or
                // dropping a party the caller listed.
                throw FirmaException::unsupported(
                    'recipients[].designation='.$designation,
                    'Only "'.FirmaProfile::DESIGNATION.'" recipients are implemented in this build. An '
                    .'approver or a copy recipient would have to be treated as a signer or silently '
                    .'dropped, and both are worse than refusing the request.',
                    ['supported_designations' => [FirmaProfile::DESIGNATION]],
                );
            }

            // Disagreement D3: upstream's prose says every recipient must have an explicit
            // order and its schema does not list `order` as required. Fail closed and
            // require it: an implied order is an implied signing sequence.
            $order = $recipient['order'] ?? null;

            if (! is_int($order) && ! (is_string($order) && ctype_digit($order))) {
                throw FirmaException::of(
                    FirmaErrorCode::InvalidRequest,
                    'Recipient '.($index + 1).' has no explicit `order`. Every recipient must have one: '
                    .'the signing sequence is never inferred from the order they appear in the request.',
                    ['recipient_index' => $index + 1],
                );
            }

            $order = (int) $order;

            if ($order < 1) {
                throw FirmaException::of(
                    FirmaErrorCode::InvalidRequest,
                    'Recipient '.($index + 1).' has `order` '.$order.'; signing order is 1-based.',
                    ['recipient_index' => $index + 1],
                );
            }

            $email = trim((string) ($recipient['email'] ?? ''));
            $schemaId = 'r'.($index + 1);
            $temporaryId = trim((string) ($recipient['id'] ?? $recipient['temp_id'] ?? ''));

            $planned[] = new PlannedRecipient(
                schemaId: $schemaId,
                name: self::displayName($recipient),
                email: $email,
                order: $order,
                reference: array_values(array_unique(array_filter([
                    mb_strtolower($schemaId),
                    mb_strtolower($temporaryId),
                    mb_strtolower($email),
                    (string) $order,
                ]))),
            );

        }

        if ($planned === []) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'A signing request needs at least one recipient.',
            );
        }

        return $planned;
    }

    /**
     * The checks that do not need the document, run before it is stored.
     *
     * `schema()` cannot run until the page sizes are known, and the page sizes are not known
     * until the bytes have been parsed — which, on `create-and-send`, means after
     * {@see DocumentIntake} has written them. Most bad
     * requests do not need the page, though: a percentage outside 0..100, a field type this
     * build cannot place, a recipient with no `order`, an approver. Refusing those first means
     * the common mistakes leave nothing behind at all, rather than an uploaded document
     * attached to a signing request that was never created.
     *
     * What is left over is genuinely document-dependent — a page the document does not have,
     * an anchor whose text is not in it — and for those the document is retained with its
     * preflight report, which is intake's own rule.
     *
     * @param  list<array<string, mixed>>  $fields
     *
     * @throws FirmaException
     */
    public function validateWithoutDocument(array $fields): void
    {
        if ($fields === []) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'A signing request needs at least one field: a document with nothing to sign cannot be signed.',
            );
        }

        foreach (array_values($fields) as $index => $field) {
            if (! is_array($field)) {
                throw FirmaException::of(
                    FirmaErrorCode::InvalidRequest,
                    'Field '.($index + 1).' is not an object.',
                    ['field_index' => $index + 1],
                );
            }

            // Throws 501 for a type upstream declares and this build cannot place.
            FirmaFieldType::inbound((string) ($field['type'] ?? ''));

            self::pageNumber($field, $index);
            self::assertPercentages(self::position($field, $index), $index);
        }
    }

    /**
     * Build the native field schema.
     *
     * @param  array<int, PageGeometry>  $pages  Keyed by 1-based page number.
     * @param  list<PlannedRecipient>  $recipients
     * @param  list<array<string, mixed>>  $fields  Validated request fields.
     * @param  Closure(): string  $documentBytes  Opens the PDF, lazily: only a request with
     *                                            at least one anchor pays for the parse.
     * @param  string|null  $documentSha256  Digest of the review revision the fields are placed
     *                                       on. Without it an anchored field still gets its
     *                                       resolved rectangle, but no receipt, so the envelope
     *                                       resolves the anchor again before sending rather than
     *                                       trusting coordinates it cannot tie to any bytes.
     * @return array<string, mixed> A native field schema document, declaring `SchemaVersion::CURRENT`.
     *
     * @throws FirmaException
     */
    public function schema(
        string $documentId,
        array $pages,
        array $recipients,
        array $fields,
        Closure $documentBytes,
        bool $useSigningOrder = true,
        ?string $documentSha256 = null,
    ): array {
        $runs = null;
        $ids = [];
        $placed = [];

        foreach (array_values($fields) as $index => $field) {
            $owner = $this->owner($recipients, $field, $index);
            $pageNumber = self::pageNumber($field, $index);
            $page = $pages[$pageNumber] ?? throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' is on page '.$pageNumber.' and the document has '.count($pages).'.',
                ['field_index' => $index + 1, 'page_number' => $pageNumber, 'page_count' => count($pages)],
            );

            $position = self::position($field, $index);
            $rect = $this->rect($page, $position, $index);
            $anchor = is_array($field['anchor'] ?? null) ? $field['anchor'] : null;

            $resolved = null;

            if ($anchor !== null) {
                $runs ??= $this->extract($documentBytes);
                $resolved = $this->anchoredRect($runs, $page, $anchor, $rect, $pageNumber, $index);
                $rect = $resolved->resolvedRect;
            }

            $id = self::identifier($field, $index, $ids);
            $ids[] = $id;
            $variableName = self::variableName($field);

            $definition = [
                'id' => $id,
                'recipient_id' => $owner->schemaId,
                'type' => FirmaFieldType::inbound((string) ($field['type'] ?? ''))->value,
                'page' => $pageNumber,
                'rect' => [
                    'x' => CanonicalNumber::encode($rect->x),
                    'y' => CanonicalNumber::encode($rect->y),
                    'width' => CanonicalNumber::encode($rect->width),
                    'height' => CanonicalNumber::encode($rect->height),
                ],
                'required' => (bool) ($field['required'] ?? true),
                'read_only' => (bool) ($field['read_only'] ?? false),
            ];

            if ($variableName !== null) {
                // The caller's `variable_name` verbatim in `label`, and a normalised copy in
                // `alias`. The native schema's `alias` is an identifier — no spaces, no
                // slashes — and real consumer variable names have both ("Company/Individual
                // Name"). Keeping the original is what lets `/fields` echo back the exact
                // string the caller sent and lets a PATCH address it by that string.
                $definition['label'] = Str::limit($variableName, 200, '');
                $definition['alias'] = self::normaliseVariableName($variableName);
            }

            if ($anchor !== null && $resolved instanceof ResolvedAnchor) {
                $definition['anchor'] = self::nativeAnchor($anchor, $resolved, $documentSha256);
            }

            $placed[] = $definition;
        }

        if ($placed === []) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'A signing request needs at least one field: a document with nothing to sign cannot be signed.',
            );
        }

        return [
            // The version this build writes, not a literal. An anchored field carries a
            // `resolved` receipt, which is a 1.1 member, and a generated document that called
            // itself 1.0 while containing one would violate the unchanged 1.0 contract's
            // `additionalProperties: false` the moment a consumer validated it.
            'schema_version' => SchemaVersion::CURRENT,
            'document_id' => $documentId,
            // Always the native tuple. The schema records the space its numbers are in, and
            // by the time a definition reaches here they are native points.
            'coordinate_space' => CoordinateSpaceDeclaration::native()->toArray(),
            'recipients' => array_map(
                static fn (PlannedRecipient $recipient): array => $recipient->toSchemaRecipient(),
                $recipients,
            ),
            'signing_order' => self::signingOrder($recipients, $useSigningOrder),
            'fields' => $placed,
        ];
    }

    /**
     * The signing stages.
     *
     * Grouped by the recipients' own `order`, ascending, so two parties given the same order
     * sign in parallel and a party given a later one waits — which is what `order` means
     * upstream. With `settings.use_signing_order: false` everyone is in one stage, because
     * that is what turning the order off asks for.
     *
     * @param  list<PlannedRecipient>  $recipients
     * @return list<list<string>>
     */
    public static function signingOrder(array $recipients, bool $useSigningOrder = true): array
    {
        if (! $useSigningOrder) {
            return [array_map(static fn (PlannedRecipient $r): string => $r->schemaId, $recipients)];
        }

        $stages = [];

        foreach ($recipients as $recipient) {
            $stages[$recipient->order][] = $recipient->schemaId;
        }

        ksort($stages);

        return array_values($stages);
    }

    /**
     * @param  list<PlannedRecipient>  $recipients
     * @param  array<string, mixed>  $field
     *
     * @throws FirmaException
     */
    private function owner(array $recipients, array $field, int $index): PlannedRecipient
    {
        $references = array_filter([
            $field['recipient_id'] ?? null,
            $field['recipient'] ?? null,
            $field['recipient_email'] ?? null,
            $field['signer_order'] ?? null,
            $field['order'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) || is_int($value));

        foreach ($references as $reference) {
            foreach ($recipients as $recipient) {
                if ($recipient->answersTo((string) $reference)) {
                    return $recipient;
                }
            }
        }

        if ($references === [] && count($recipients) === 1) {
            // One party, one possible owner. Not a guess: there is nothing to choose between.
            return $recipients[0];
        }

        throw FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'Field '.($index + 1).' does not name a recipient this request declares. A field belongs to a '
            .'recipient, never to a position in the signing order, so it cannot be assigned by elimination.',
            [
                'field_index' => $index + 1,
                'declared_recipients' => array_map(
                    static fn (PlannedRecipient $r): array => ['order' => $r->order, 'email' => $r->email],
                    $recipients,
                ),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $position
     *
     * @throws FirmaException
     */
    private function rect(PageGeometry $page, array $position, int $index): NativeRect
    {
        [$x, $y, $width, $height] = self::assertPercentages($position, $index);

        return $this->coordinates->toNative(
            FirmaProfile::COORDINATES,
            $page,
            $x,
            $y,
            $width,
            $height,
            FirmaProfile::NAME,
        );
    }

    /**
     * Every rule the profile's own schema states about a `position`, and nothing about the page.
     *
     * `0..100` on each member, `x + width <= 100`, `y + height <= 100`, and a positive area.
     * Out of range is refused rather than reinterpreted as points: the unit is declared by the
     * profile and is never guessed from a value's magnitude (disagreement D4, `AGENTS.md`).
     *
     * @param  array<string, mixed>  $position
     * @return array{float, float, float, float}
     *
     * @throws FirmaException
     */
    private static function assertPercentages(array $position, int $index): array
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $value = $position[$key] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                throw FirmaException::of(
                    FirmaErrorCode::InvalidRequest,
                    'Field '.($index + 1).' has no numeric `position.'.$key.'`.',
                    ['field_index' => $index + 1],
                );
            }

            if ($value < 0.0 || $value > 100.0) {
                throw self::outOfRange($index, $key, (float) $value);
            }
        }

        $x = (float) $position['x'];
        $y = (float) $position['y'];
        $width = (float) $position['width'];
        $height = (float) $position['height'];

        if ($width <= 0.0 || $height <= 0.0) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' has a zero or negative size. A field with no area cannot be filled in.',
                ['field_index' => $index + 1],
            );
        }

        if ($x + $width > 100.0 || $y + $height > 100.0) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' extends past the edge of its page. This profile requires '
                .'`x + width <= 100` and `y + height <= 100`, both as percentages of the page.',
                ['field_index' => $index + 1],
            );
        }

        return [$x, $y, $width, $height];
    }

    /**
     * The native-schema anchor for a resolved facade anchor: the request, in native units, plus
     * the receipt.
     *
     * `placement` is always `replace` because that is what this profile's anchor means — the
     * caller's `position.x` and `position.y` are the placeholder the anchor overrides, and
     * `position.width`/`position.height` are the size it never supplies. `required` is always
     * true: the profile has no way to say an anchor may be absent, and inventing one here would
     * be a compatibility extension nobody asked for.
     *
     * @param  array<string, mixed>  $anchor
     * @return array<string, mixed>
     */
    private static function nativeAnchor(array $anchor, ResolvedAnchor $resolved, ?string $documentSha256): array
    {
        $request = $resolved->anchor;

        $native = [
            // The *resolved* request's text, not the caller's: `anchoredRect()` trims what
            // arrives, and send resolves this document again from scratch. Storing the untrimmed
            // string would have the facade find " Signature: " on create and the native resolver
            // fail to find it on send, on the same bytes.
            'text' => $request->text,
            'occurrence' => self::occurrenceValue($anchor),
            'placement' => AnchorPlacementMode::Replace->value,
            'origin' => $request->origin->value,
            'offset' => [
                'dx' => CanonicalNumber::encode($request->offsetX),
                'dy' => CanonicalNumber::encode($request->offsetY),
            ],
            'required' => true,
        ];

        if ($documentSha256 !== null) {
            $native['resolved'] = [
                'document_sha256' => $documentSha256,
                'page' => $resolved->page,
                'occurrence_index' => $resolved->occurrenceIndex,
                'anchor_rect' => self::rectArray($resolved->anchorRect),
                'rect' => self::rectArray($resolved->resolvedRect),
            ];
        }

        return $native;
    }

    /**
     * @return array{x: int|float, y: int|float, width: int|float, height: int|float}
     */
    private static function rectArray(NativeRect $rect): array
    {
        return [
            'x' => CanonicalNumber::encode($rect->x),
            'y' => CanonicalNumber::encode($rect->y),
            'width' => CanonicalNumber::encode($rect->width),
            'height' => CanonicalNumber::encode($rect->height),
        ];
    }

    /**
     * @param  array<int, TextRun>  $runs
     * @param  array<string, mixed>  $anchor
     *
     * @throws FirmaException
     */
    private function anchoredRect(
        array $runs,
        PageGeometry $page,
        array $anchor,
        NativeRect $sized,
        int $pageNumber,
        int $index,
    ): ResolvedAnchor {
        $text = trim((string) ($anchor['text'] ?? ''));

        if ($text === '') {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' has an `anchor` with no `text` to look for.',
                ['field_index' => $index + 1],
            );
        }

        // Offsets are percentages of the page, like every other number in this profile.
        // A second unit switch is how a signature lands in the wrong place.
        $offsetPercentX = self::offset($anchor, 'offset_x', $index);
        $offsetPercentY = self::offset($anchor, 'offset_y', $index);

        $resolved = $this->resolve($runs, new Anchor(
            text: $text,
            occurrence: self::occurrence($anchor, $index),
            page: $pageNumber,
            offsetX: $offsetPercentX / 100.0 * $page->nativeWidth(),
            offsetY: $offsetPercentY / 100.0 * $page->nativeHeight(),
            width: $sized->width,
            height: $sized->height,
            origin: self::origin($anchor, $index),
        ), $index);

        self::assertOnPage($resolved->resolvedRect, $page, $index);

        return $resolved;
    }

    /**
     * One anchor offset, in percent of the page.
     *
     * Read and checked rather than cast. `(float) "not-a-number"` is `0.0`, which would place
     * the field at the bare anchor origin and report success — the same silent
     * reinterpretation `assertPercentages()` refuses for `position.x`, and there is no reason
     * for the two coordinate inputs to fail differently.
     *
     * The range is `-100..100` rather than `0..100`: an offset is a displacement, and nudging
     * a field *above* the text it is anchored to is an ordinary thing to ask for. Where the
     * field ends up is then checked against the page by {@see assertOnPage()}.
     *
     * @param  array<string, mixed>  $anchor
     *
     * @throws FirmaException
     */
    private static function offset(array $anchor, string $key, int $index): float
    {
        if (! array_key_exists($key, $anchor) || $anchor[$key] === null) {
            return 0.0;
        }

        $value = $anchor[$key];

        if (! is_int($value) && ! is_float($value)) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' has a non-numeric `anchor.'.$key.'`. An anchor offset is a '
                .'percentage of the page and is never guessed from a value this service cannot read.',
                ['field_index' => $index + 1, 'property' => 'anchor.'.$key],
            );
        }

        if ($value < -100.0 || $value > 100.0) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' has `anchor.'.$key.'` = '.$value.'. An anchor offset is a '
                .'percentage of the page and must be between -100 and 100.',
                ['field_index' => $index + 1, 'property' => 'anchor.'.$key, 'value' => (float) $value],
            );
        }

        return (float) $value;
    }

    /**
     * An anchored field still has to land on the page.
     *
     * The percent path is bounded by {@see assertPercentages()}, but an anchored rectangle is
     * built from where the text turned out to be plus the caller's offset, so nothing upstream
     * of here knows whether it fits. And nothing downstream checks either: the native schema
     * validator's page-fit rule needs `PageSizes`, which
     * `FieldSchemaDocument::fromArray()` does not supply, so a field placed past the edge
     * would be stored, drawn nowhere a signer can reach, and then reported by `/fields` as a
     * percentage over 100 — a value this same facade refuses on the way in.
     *
     * @throws FirmaException
     */
    private static function assertOnPage(NativeRect $rect, PageGeometry $page, int $index): NativeRect
    {
        $width = $page->nativeWidth();
        $height = $page->nativeHeight();
        $tolerance = 1.0e-6;

        if ($rect->x < -$tolerance
            || $rect->y < -$tolerance
            || $rect->right() > $width + $tolerance
            || $rect->bottom() > $height + $tolerance) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' is anchored to text on page '.$page->pageNumber.' but its offset '
                .'puts it off the page. A field a signer cannot reach is refused rather than stored.',
                [
                    'field_index' => $index + 1,
                    'page_number' => $page->pageNumber,
                    'resolved_rect' => $rect->toArray(),
                    'page_size' => [$width, $height],
                ],
            );
        }

        return $rect;
    }

    /**
     * @param  array<int, TextRun>  $runs
     *
     * @throws FirmaException
     */
    private function resolve(array $runs, Anchor $anchor, int $index): ResolvedAnchor
    {
        try {
            $resolved = $this->anchors->resolve($runs, $anchor);
        } catch (AnchorResolutionException $failure) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' could not be anchored: '.$failure->getMessage().' A field placed at a '
                .'fallback position is a field nobody agreed to sign there, so the request is refused.',
                ['field_index' => $index + 1, 'anchor_text' => $anchor->text],
            );
        }

        return $resolved[0];
    }

    /**
     * @param  Closure(): string  $documentBytes
     * @return array<int, TextRun>
     *
     * @throws FirmaException
     */
    private function extract(Closure $documentBytes): array
    {
        try {
            return $this->text->extract($documentBytes());
        } catch (TextExtractionException $failure) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The document\'s text could not be read, so no anchor in this request can be resolved: '
                .$failure->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    private static function position(array $field, int $index): array
    {
        if (is_array($field['position'] ?? null)) {
            return $field['position'];
        }

        // The flat spelling upstream deprecates but still documents on the create body.
        $flat = array_filter([
            'x' => $field['x_position'] ?? $field['x_postion'] ?? null,
            'y' => $field['y_position'] ?? null,
            'width' => $field['width'] ?? null,
            'height' => $field['height'] ?? $field['heigh'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($flat !== []) {
            return $flat;
        }

        throw FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'Field '.($index + 1).' has no `position`. Even an anchored field needs `position.width` and '
            .'`position.height`: an anchor says where a field goes, never how big it is.',
            ['field_index' => $index + 1],
        );
    }

    /**
     * @param  array<string, mixed>  $field
     *
     * @throws FirmaException
     */
    private static function pageNumber(array $field, int $index): int
    {
        $page = $field['page_number'] ?? $field['page'] ?? null;

        if ((! is_int($page) && ! (is_string($page) && ctype_digit($page))) || (int) $page < 1) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' has no 1-based `page_number`.',
                ['field_index' => $index + 1],
            );
        }

        return (int) $page;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function variableName(array $field): ?string
    {
        foreach (['variable_name', 'variable_defined_name', 'name'] as $key) {
            $value = $field[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * A native schema identifier derived from the caller's variable name.
     *
     * Lower-cased, with every character the identifier pattern does not allow collapsed to an
     * underscore. Deterministic, so the same variable name always normalises to the same
     * alias and a PATCH addressing either spelling resolves.
     */
    public static function normaliseVariableName(string $variableName): string
    {
        $normalised = preg_replace('/[^a-z0-9._-]+/', '_', mb_strtolower(trim($variableName))) ?? '';
        $normalised = trim($normalised, '_');

        // The pattern requires an alphanumeric first character.
        if ($normalised === '' || preg_match('/^[a-z0-9]/', $normalised) !== 1) {
            $normalised = 'v'.$normalised;
        }

        return substr($normalised, 0, 64);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $taken
     */
    private static function identifier(array $field, int $index, array $taken): string
    {
        $variableName = self::variableName($field);
        $base = $variableName === null
            ? 'field_'.($index + 1)
            : self::normaliseVariableName($variableName);

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        // Two fields with the same variable name are legitimate — the fixtures show
        // `full_name` twice on one agreement, once read-only and once editable — so the id is
        // suffixed rather than the request refused.
        $candidate = $base.'_'.($index + 1);

        return in_array($candidate, $taken, true) ? $base.'_f'.($index + 1) : $candidate;
    }

    /**
     * @param  array<string, mixed>  $anchor
     *
     * @throws FirmaException
     */
    private static function occurrence(array $anchor, int $index): AnchorOccurrence
    {
        $value = self::occurrenceValue($anchor);

        if ($value === 'sole') {
            return AnchorOccurrence::sole();
        }

        if (is_int($value) && $value >= 1) {
            return AnchorOccurrence::index($value);
        }

        throw FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'Field '.($index + 1).' has an `anchor.occurrence` of "'.(string) $value.'". Use "sole" for text '
            .'that appears exactly once, or a 1-based number. "all" is not accepted: one field cannot be in '
            .'two places.',
            ['field_index' => $index + 1],
        );
    }

    /**
     * @param  array<string, mixed>  $anchor
     */
    private static function occurrenceValue(array $anchor): string|int
    {
        $value = $anchor['occurrence'] ?? 'sole';

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_string($value) ? mb_strtolower(trim($value)) : 'sole';
    }

    /**
     * @param  array<string, mixed>  $anchor
     *
     * @throws FirmaException
     */
    private static function origin(array $anchor, int $index): AnchorOrigin
    {
        $value = $anchor['origin'] ?? AnchorOrigin::TopLeft->value;
        $origin = is_string($value) ? AnchorOrigin::tryFrom(mb_strtolower(trim($value))) : null;

        if ($origin === null) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Field '.($index + 1).' has an unknown `anchor.origin`.',
                [
                    'field_index' => $index + 1,
                    'accepted' => array_map(static fn (AnchorOrigin $o): string => $o->value, AnchorOrigin::cases()),
                ],
            );
        }

        return $origin;
    }

    /**
     * @param  array<string, mixed>  $recipient
     */
    private static function displayName(array $recipient): string
    {
        $joined = trim(implode(' ', array_filter([
            trim((string) ($recipient['first_name'] ?? '')),
            trim((string) ($recipient['last_name'] ?? '')),
        ])));

        if ($joined !== '') {
            return $joined;
        }

        $supplied = trim((string) ($recipient['name'] ?? ''));

        return $supplied !== '' ? $supplied : trim((string) ($recipient['email'] ?? ''));
    }

    private static function outOfRange(int $index, string $key, float $value): FirmaException
    {
        return FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'Field '.($index + 1).' has `position.'.$key.'` = '.$value.'. This profile\'s coordinates are '
            .'percentages of the page and must be between 0 and 100. The value is refused rather than '
            .'reinterpreted as points: a coordinate\'s unit is declared by the profile and is never guessed '
            .'from its magnitude.',
            ['field_index' => $index + 1, 'property' => 'position.'.$key, 'value' => $value],
        );
    }
}
