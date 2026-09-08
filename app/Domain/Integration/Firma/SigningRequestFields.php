<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Integration\Native\EnvelopeValueReader;
use App\Domain\Integration\Native\FieldValueView;
use App\Domain\Preparation\Geometry\FacadeCoordinateTranslator;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use stdClass;

/**
 * `GET /signing-requests/{id}/fields`, and the field rows the create responses carry.
 *
 * Values come from App\Domain\Integration\Native\EnvelopeValueReader — the same reader the
 * native API's `/values` endpoint uses — so a caller polling this facade and a caller polling
 * `/api/v1` cannot be told two different things about the same field.
 *
 * ## Coordinates, in both spellings and both shapes
 *
 * Every row carries the same rectangle four times, because the profile does:
 * `x_postion`, `y_position`, `width`, `heigh` at the top level, and `position.{x,y,width,
 * height}` nested. The recorded fixtures populate both with identical numbers, and
 * `tests/Feature/Compatibility/FirmaFixtureShapeTest` asserts they agree.
 *
 * The two misspellings — `x_postion` without its `i`, `heigh` without its `t` — are upstream's
 * and are part of the contract (disagreement D9). They are reproduced here exactly and go no
 * further: the create response spells the same concepts `x_position` and `height` correctly,
 * because that is what upstream's *other* schema does, and nothing native ever sees either
 * spelling.
 *
 * The numbers are **percentages of the displayed page**, converted from native points by
 * {@see FacadeCoordinateTranslator} against the geometry
 * {@see PageGeometryReader} measured. The convention is declared once, in
 * {@see FirmaProfile::COORDINATES}, and never inferred from a value's magnitude
 * (AGENTS.md; `docs/preparation/coordinate-space.md`).
 *
 * ## What is null on purpose
 *
 * `tl_position`, `tr_position`, `bl_position` and `br_position` are bare numbers upstream
 * with no unit, no origin, and no description beyond "Top-left corner position". There is no
 * way to emit a value that is right, so they are null — which the recorded fixtures also
 * show for every field of every workflow. Guessing a unit here would misplace a corner by
 * the width of a page.
 *
 * `dropdown_options`, `format_rules`, `validation_rules`, `multi_group_id`, `date_default`,
 * `calculated_font_size`, `required_conditions`, `visibility_conditions` and
 * `background_color` describe features this build does not have. Each is emitted with the
 * empty value of its own type so the key set matches, and requesting any of them on input is
 * refused rather than accepted and dropped ({@see FirmaFieldType}, {@see UnsupportedOptions}).
 */
final readonly class SigningRequestFields
{
    public function __construct(
        private EnvelopeValueReader $values,
        private PageGeometryReader $pages,
        private FacadeCoordinateTranslator $coordinates,
    ) {}

    /**
     * The `results[]` of `GET /signing-requests/{id}/fields`.
     *
     * @param  bool  $includeImages  `?include=images`. See {@see finalValue()}.
     * @return list<array<string, mixed>>
     */
    public function results(Envelope $envelope, bool $includeImages): array
    {
        $recipients = self::recipientsBySchemaId($envelope);
        $views = $this->viewsByFieldId($envelope, $includeImages);
        $rows = [];

        foreach ($envelope->fieldSchema()->fields as $field) {
            $view = $views[$field->id] ?? null;
            $recipient = $recipients[$field->recipientId] ?? null;
            $percent = $this->percentRect($envelope, $field);
            $value = $view instanceof FieldValueView ? self::finalValue($view, $includeImages) : null;
            $readOnly = $field->readOnly;

            $rows[] = [
                'id' => $field->id,
                'companies_workspaces_signing_requests_id' => $envelope->public_id,
                'field_type' => FirmaFieldType::outbound($field->type),
                'required' => $field->required,
                'companies_workspaces_signing_requests_users_id' => $recipient?->public_id,
                'dropdown_options' => new stdClass,
                'multi_group_id' => null,
                'format_rules' => null,
                'validation_rules' => null,
                'variable_name' => self::variableName($field),
                'date_default' => null,
                'tl_position' => null,
                'tr_position' => null,
                'bl_position' => null,
                'br_position' => null,
                'final_value' => $value,
                // True for the one field type the service fills in from a recipient's own
                // attestation instant rather than from anything anybody typed.
                'date_signing_default' => $field->type === FieldType::SigningDate,
                'x_postion' => $percent->x,
                'y_position' => $percent->y,
                'width' => $percent->width,
                'heigh' => $percent->height,
                // Nothing is ever soft-deleted from a copied field schema: it is immutable,
                // which is what lets an executed agreement be proved against what the
                // parties were shown.
                'deleted' => 0,
                'page_number' => $field->page,
                'calculated_font_size' => null,
                'read_only' => $readOnly,
                'read_only_value' => $readOnly ? $value : null,
                'required_conditions' => null,
                'visibility_conditions' => null,
                'background_color' => null,
                'type' => FirmaFieldType::outbound($field->type),
                'recipient_id' => $recipient?->public_id,
                'prefilled_editable' => self::prefilledEditable($view, $readOnly),
                // Upstream calls `value` the "clean alias" for the deprecated `final_value`.
                // Both are emitted, identical, because both are read in the wild.
                'value' => $value,
                'position' => [
                    'x' => $percent->x,
                    'y' => $percent->y,
                    'width' => $percent->width,
                    'height' => $percent->height,
                ],
                'variable_defined_name' => null,
            ];
        }

        return $rows;
    }

    /**
     * The field rows the **create** responses carry.
     *
     * A different schema upstream (`SigningRequestCreateField`), with fewer members and with
     * `x_position` and `height` spelled correctly. Emitting the read shape here instead
     * would propagate two typos into a response upstream does not put them in.
     *
     * @return list<array<string, mixed>>
     */
    public function createResults(Envelope $envelope): array
    {
        $recipients = self::recipientsBySchemaId($envelope);
        $views = $this->viewsByFieldId($envelope, includeImages: false);
        $rows = [];

        foreach ($envelope->fieldSchema()->fields as $field) {
            $view = $views[$field->id] ?? null;
            $percent = $this->percentRect($envelope, $field);

            $rows[] = [
                'id' => $field->id,
                'type' => FirmaFieldType::outbound($field->type),
                'recipient_id' => ($recipients[$field->recipientId] ?? null)?->public_id,
                'page_number' => $field->page,
                'x_position' => $percent->x,
                'y_position' => $percent->y,
                'width' => $percent->width,
                'height' => $percent->height,
                'required' => $field->required,
                'read_only' => $field->readOnly,
                'variable_name' => self::variableName($field),
                'final_value' => $view instanceof FieldValueView ? self::finalValue($view, false) : null,
            ];
        }

        return $rows;
    }

    /**
     * One field row, for the PATCH response.
     *
     * Disagreement D6: the PATCH response's field variant is `{"type": "object"}` upstream
     * with no properties at all, so the document cannot pin it. The facade returns the same
     * object `/fields` returns for that field, which is the one shape a consumer already
     * knows how to read.
     *
     * @return array<string, mixed>
     *
     * @throws FirmaException When the field is not in this request's schema.
     */
    public function one(Envelope $envelope, string $fieldId, bool $includeImages = false): array
    {
        foreach ($this->results($envelope, $includeImages) as $row) {
            if ($row['id'] === $fieldId) {
                return $row;
            }
        }

        throw FirmaException::notFound('field');
    }

    /**
     * The profile's `variable_name`: the stable handle a caller addresses a field by.
     *
     * The native schema's `label` first, then `alias`, then a prefill variable, then null.
     *
     * That order looks odd until you know why `label` is there. The native schema's `alias`
     * is an *identifier* — `^[A-Za-z0-9][A-Za-z0-9._-]*$` — and real consumer variable names
     * are not: the recorded fixtures carry `Company/Individual Name` and `Signing Date`,
     * spaces and slash included. So a field placed through this facade keeps the caller's
     * string verbatim in `label` and a normalised copy in `alias`
     * ({@see FieldPlacement::normaliseVariableName()}), which is what lets `/fields` echo
     * back exactly what was sent and lets a `PATCH` address the field by either spelling.
     *
     * Never the field's own id: `id` is already a separate member, and collapsing the two
     * would make an unnamed field look as if it had a variable name a template could rely on.
     */
    public static function variableName(FieldDefinition $field): ?string
    {
        return $field->label ?? $field->alias ?? $field->prefill?->variable;
    }

    /**
     * What a caller reads as the field's value.
     *
     * A signature or initials mark is **not** dumped by default. It is described by
     * `FieldValueView` as a media type, a digest and a length, and here it becomes the marker
     * string {@see FirmaProfile::SIGNATURE_MARKER} — the exact value the recorded fixtures
     * carry for a signature rendered as text. `?include=images` returns the captured data URL
     * instead, which is what the fixtures show for a drawn one.
     *
     * The default withholds it for the reason `FieldValueView` gives: an envelope carries one
     * signature image per signer, and a response that ships them unasked puts them in the
     * caller's request logs, proxy caches and error reports. Asking for them is a decision.
     */
    public static function finalValue(FieldValueView $view, bool $includeImages): mixed
    {
        if ($view->signature === null) {
            return $view->value;
        }

        // `bytes` is the length of the decoded mark. Zero means the field exists and nobody
        // has signed it, which is a null value and not an empty image.
        if ((int) ($view->signature['bytes'] ?? 0) === 0) {
            return null;
        }

        if ($includeImages) {
            return $view->signature['data'] ?? FirmaProfile::SIGNATURE_MARKER;
        }

        return FirmaProfile::SIGNATURE_MARKER;
    }

    /**
     * Upstream's `prefilled_editable`: the sender put a value here and the recipient may
     * still change it.
     *
     * Read off the two facts that decide it rather than stored: who set the value
     * (`FieldValueView::$source`) and whether the schema froze the field. A read-only field
     * is never editable however it was filled, and a field nobody prefilled has nothing to
     * be editable about.
     */
    public static function prefilledEditable(?FieldValueView $view, bool $readOnly): bool
    {
        if ($readOnly || ! $view instanceof FieldValueView) {
            return false;
        }

        return $view->source === 'sender' && self::finalValue($view, false) !== null;
    }

    /**
     * @return array<string, EnvelopeRecipient> Schema recipient id => row.
     */
    public static function recipientsBySchemaId(Envelope $envelope): array
    {
        if (! $envelope->relationLoaded('recipients')) {
            $envelope->setRelation('recipients', $envelope->recipients()->get());
        }

        $byId = [];

        foreach ($envelope->getRelation('recipients') as $recipient) {
            $byId[$recipient->schema_recipient_id] = $recipient;
        }

        return $byId;
    }

    /**
     * @return array<string, FieldValueView>
     */
    public function viewsByFieldId(Envelope $envelope, bool $includeImages): array
    {
        $views = [];

        foreach ($this->values->read($envelope, $includeImages) as $view) {
            $views[$view->fieldId] = $view;
        }

        return $views;
    }

    /**
     * The field's rectangle in the profile's declared convention.
     *
     * @throws FirmaException When the document has no such page.
     */
    private function percentRect(Envelope $envelope, FieldDefinition $field): NativeRect
    {
        return $this->coordinates->fromNative(
            FirmaProfile::COORDINATES,
            $this->pages->pageOf($envelope, $field->page),
            new NativeRect($field->rect->x, $field->rect->y, $field->rect->width, $field->rect->height),
            FirmaProfile::NAME,
        );
    }
}
