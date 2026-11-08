<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\OverlayImage;
use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\OverlayText;
use App\Domain\Preparation\Assembly\PageOverlay;
use App\Domain\Preparation\Contracts\DocumentReadUnavailable;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Preparation\Schema\Rect;
use App\Domain\Preparation\TcPdf\CoreFontMetrics;
use App\Domain\Signing\Fields\FieldMateriality;

/**
 * Draws the executed document: the reviewed revision with every field's value on it, and the
 * completion report appended as its last page.
 *
 * ## Where a value goes
 *
 * At the field's own rectangle, in the declared native coordinate space, taken from the field
 * schema the envelope snapshotted. Nothing is inferred and nothing is nudged: the sender
 * placed the box in the editor and the value goes in that box (AGENTS.md, "Coordinates are
 * never guessed"). The renderer's only freedom is *within* the rectangle — how large the text
 * is and where it wraps.
 *
 * ## What a value looks like
 *
 * | Field type | Drawn as |
 * |---|---|
 * | `text`, `name`, `company`, `title` | text, wrapped inside the rectangle |
 * | `agreement_date` | text: the sender-supplied effective date |
 * | `signing_date` | text: the owning recipient's acceptance time, from their attestation |
 * | `checkbox` | a box with a cross through it when true, an empty box when false |
 * | `signature`, `initials` | the captured image, fitted; or the typed text when that is what was adopted |
 *
 * A value is never truncated. Text shrinks to fit and wraps; if it still does not fit it
 * spills within its own line box rather than being silently cut, because a clipped value
 * reads as a different value from the one the person entered.
 *
 * `signing_date` is derived rather than read from `envelope_field_values`: it is
 * `recipient_attestations.accepted_at`, which is immutable evidence, and copying it into a
 * mutable table would let the drawn date disagree with its own source
 * (App\Domain\Signing\Fields\FieldMateriality).
 *
 * ## The appended page
 *
 * The completion report is passed to the assembler as an appended document rather than drawn
 * here, so the bytes bound into the agreement are the same bytes published as the
 * `completion_report` artifact.
 */
final readonly class ExecutedDocumentRenderer
{
    /** Largest type used for a field value. Bigger reads as a heading, not as an entry. */
    private const MAX_FONT_SIZE = 10.0;

    /** Below this the value is not readable, so the box is used more aggressively instead. */
    private const MIN_FONT_SIZE = 5.0;

    /** Multiple of the font size between wrapped baselines. */
    private const LINE_SPACING = 1.2;

    public function __construct(private PdfAssembler $assembler) {}

    /**
     * @param  string  $reviewPdf  The reviewed revision's bytes, exactly as retained.
     * @param  string  $completionReportPdf  The rendered completion report, appended as-is.
     *
     * @throws FinalizationException When the document cannot be assembled.
     * @throws DocumentReadUnavailable When the read fails on the service side; the finalizer retries it.
     */
    public function render(FinalizationInput $input, string $reviewPdf, string $completionReportPdf, FieldSchemaDocument $schema): string
    {
        return $this->assemble($reviewPdf, $this->overlays($input, $schema), [$completionReportPdf]);
    }

    /**
     * A document set's executed outputs (issue #163, docs/preparation/document-sets.md).
     *
     * `combined` is the whole agreement: the first document with every other member appended in
     * order, then the completion report. A field's page is absolute across the set, and the
     * assembler numbers appended pages on from the source's, so every overlay lands where it was
     * placed without being moved. The other members are sender documents rather than generated
     * ones, and each passed intake preflight under the upload ceilings before it joined the set.
     *
     * `documents[position]` is each member on its own: its pages with only its fields, moved to
     * the document's own page numbers, then the same completion report.
     *
     * @param  array<int, string>  $reviewPdfs  Position => the member's review bytes, as retained.
     * @return array{combined: string, documents: array<int, string>}
     *
     * @throws FinalizationException|DocumentReadUnavailable
     */
    public function renderSet(FinalizationInput $input, DocumentSet $set, array $reviewPdfs, string $completionReportPdf, FieldSchemaDocument $schema): array
    {
        $overlays = $this->overlays($input, $schema);
        $ordered = [];

        foreach ($set->entries as $entry) {
            $ordered[] = $reviewPdfs[$entry->position] ?? throw new FinalizationException(
                'Document '.$entry->position.' of the set has no review bytes, so nothing is published.',
            );
        }

        $combined = $this->assemble(array_shift($ordered), $overlays, [...$ordered, $completionReportPdf]);
        $documents = [];

        foreach ($set->entries as $entry) {
            $own = [];

            foreach ($overlays as $overlay) {
                if ($entry->contains($overlay->pageNumber())) {
                    $own[] = self::onPage($overlay, $entry->localPage($overlay->pageNumber()));
                }
            }

            $documents[$entry->position] = $this->assemble($reviewPdfs[$entry->position], $own, [$completionReportPdf]);
        }

        return ['combined' => $combined, 'documents' => $documents];
    }

    /**
     * @return list<PageOverlay>
     */
    private function overlays(FinalizationInput $input, FieldSchemaDocument $schema): array
    {
        $overlays = [];

        foreach ($schema->fields as $field) {
            foreach ($this->overlaysFor($field, $input) as $overlay) {
                $overlays[] = $overlay;
            }
        }

        return $overlays;
    }

    /**
     * @param  list<PageOverlay>  $overlays
     * @param  list<string>  $appended
     */
    private function assemble(string $source, array $overlays, array $appended): string
    {
        try {
            $assembled = $this->assembler->assemble($source, $overlays, $appended);
        } catch (AssemblyException $exception) {
            throw new FinalizationException(
                'The executed document could not be assembled: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($assembled->bytes === '') {
            throw new FinalizationException('The document assembler produced no bytes.');
        }

        return $assembled->bytes;
    }

    /** The same mark on another page number: a set member's own numbering. */
    private static function onPage(PageOverlay $overlay, int $page): PageOverlay
    {
        return match (true) {
            $overlay instanceof OverlayText => new OverlayText($page, $overlay->rect, $overlay->text, $overlay->fontSize, $overlay->baselineOffset, $overlay->fillRgb),
            $overlay instanceof OverlayImage => new OverlayImage($page, $overlay->rect, $overlay->bytes),
            $overlay instanceof OverlayRectangle => new OverlayRectangle($page, $overlay->rect, $overlay->strokeRgb, $overlay->lineWidth, $overlay->label),
            default => throw new FinalizationException('An overlay of an unknown kind cannot be moved to a document of the set.'),
        };
    }

    /**
     * @return list<PageOverlay>
     */
    private function overlaysFor(FieldDefinition $field, FinalizationInput $input): array
    {
        $rect = $this->nativeRect($field->rect);

        if ($field->type === FieldType::Checkbox || $field->type === FieldType::ApprovalCheckmark) {
            return $this->checkboxOverlays($field, $rect, $input->values[$field->id] ?? null);
        }

        // Both service-supplied dates are the owner's own acceptance time: a signer's
        // `signing_date`, an approver's `approval_date`.
        $value = FieldMateriality::isServiceSupplied($field->type)
            ? $this->signingDateFor($field, $input)
            : $input->values[$field->id] ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        if ($field->type->capturesMark()) {
            return $this->markOverlays($field, $rect, $value);
        }

        return $this->textOverlays($field->page, $rect, $this->stringify($value));
    }

    /**
     * A cross inside a box, or an empty box.
     *
     * The empty box is drawn on purpose. A tick box that was offered and left unticked is a
     * fact about the agreement; leaving the page blank there would make "not ticked" and "no
     * such box" look identical to a reader.
     *
     * @return list<PageOverlay>
     */
    private function checkboxOverlays(FieldDefinition $field, NativeRect $rect, mixed $value): array
    {
        $overlays = [new OverlayRectangle($field->page, $rect, [0.0, 0.0, 0.0], 0.75)];

        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            $size = min($rect->width, $rect->height);
            $overlays[] = new OverlayText(
                page: $field->page,
                rect: $rect,
                text: 'X',
                fontSize: max(self::MIN_FONT_SIZE, $size),
            );
        }

        return $overlays;
    }

    /**
     * The captured mark: an image when one was adopted, the adopted text when it was typed.
     *
     * Only inline data of a raster image is accepted. docs/HANDOFF.md section 8 requires
     * signature submissions to be rendered through controlled assets — never an arbitrary
     * SVG, never a remote URL — so anything that is not recognisably inline image data is
     * treated as the typed alternative and drawn as text.
     *
     * @return list<PageOverlay>
     */
    private function markOverlays(FieldDefinition $field, NativeRect $rect, mixed $value): array
    {
        $text = $this->stringify($value);
        $bytes = $this->inlineImageBytes($text);

        if ($bytes !== null) {
            return [new OverlayImage($field->page, $rect, $bytes)];
        }

        return $this->textOverlays($field->page, $rect, $text);
    }

    /**
     * Decode a `data:image/...;base64,` value, or null when the value is not one.
     *
     * `data:` is the only accepted scheme, and only for a raster type this renderer can
     * measure. A remote URL is not fetched: a document that reached out to the network while
     * being sealed would let a third party decide what an executed agreement looks like.
     */
    private function inlineImageBytes(string $value): ?string
    {
        if (preg_match('#^data:image/(png|jpeg|jpg|gif);base64,([A-Za-z0-9+/=\s]+)$#iD', $value, $matches) !== 1) {
            return null;
        }

        $decoded = base64_decode((string) preg_replace('/\s+/', '', $matches[2]), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * Wrap and size a string so it uses the rectangle it was given and no more.
     *
     * @return list<PageOverlay>
     */
    private function textOverlays(int $page, NativeRect $rect, string $text): array
    {
        if ($text === '') {
            return [];
        }

        $size = min(self::MAX_FONT_SIZE, max(self::MIN_FONT_SIZE, $rect->height * 0.6));
        $lineHeight = $size * self::LINE_SPACING;
        $maxLines = max(1, (int) floor($rect->height / $lineHeight));

        if ($maxLines === 1) {
            return [new OverlayText(
                page: $page,
                rect: $rect,
                text: $text,
                fontSize: CoreFontMetrics::fittedSize($text, $rect->width, $size, self::MIN_FONT_SIZE),
            )];
        }

        // Shrink until the whole value fits the available lines, then stop at the floor and
        // let it run on rather than dropping any of it.
        $lines = CoreFontMetrics::wrap($text, $rect->width, $size);

        while (count($lines) > $maxLines && $size > self::MIN_FONT_SIZE) {
            $size = max(self::MIN_FONT_SIZE, $size - 0.5);
            $lineHeight = $size * self::LINE_SPACING;
            $maxLines = max(1, (int) floor($rect->height / $lineHeight));
            $lines = CoreFontMetrics::wrap($text, $rect->width, $size);
        }

        $overlays = [];

        foreach ($lines as $index => $line) {
            $overlays[] = new OverlayText(
                page: $page,
                rect: $rect,
                text: $line,
                fontSize: $size,
                baselineOffset: ($size * CoreFontMetrics::CAP_HEIGHT_RATIO) + ($index * $lineHeight),
            );
        }

        return $overlays;
    }

    /** The owning recipient's server-recorded acceptance time, as a date. */
    private function signingDateFor(FieldDefinition $field, FinalizationInput $input): ?string
    {
        foreach ($input->attestations as $attestation) {
            if ($attestation['schema_recipient_id'] === $field->recipientId) {
                return substr((string) $attestation['accepted_at'], 0, 10);
            }
        }

        return null;
    }

    /** Native space is the schema's own space, so this is a type change and not a transform. */
    private function nativeRect(Rect $rect): NativeRect
    {
        return new NativeRect($rect->x, $rect->y, $rect->width, $rect->height);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
