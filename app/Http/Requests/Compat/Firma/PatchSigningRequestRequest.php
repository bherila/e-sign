<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaException;
use App\Domain\Integration\Firma\UnsupportedOptions;
use App\Domain\Signing\Models\Envelope;

/**
 * `PATCH /signing-requests/{id}`: the consumer's **singular** `field` payload.
 *
 * Upstream's body is a `oneOf` of exactly three mutually exclusive forms — properties only,
 * `{recipient: …}`, or `{field: …}` — and its own description says "cannot update multiple
 * entity types in one request". Two of the three are implemented and one is refused:
 *
 * - **`field`** is what the consumer sends, and it addresses the field by `variable_name`
 *   *or* by `id`. Both work, because the consumer patches prefills by name while its
 *   template field ids are hardcoded (`docs/HANDOFF.md` §2).
 * - **`recipient`** corrects a party's name or address before the request goes out.
 * - **properties** — `name`, `description`, `expiration_hours` — is a `501` naming them. A
 *   signing request's title, document and field schema are one immutable snapshot, which is
 *   what lets an executed agreement be proved against what the parties were shown; there is
 *   no way to change the title of an existing request without breaking that.
 *
 * `prefilled_editable` is accepted and checked rather than applied. Whether a field is
 * editable is a property of the copied field schema and the schema does not change after the
 * request is built, so a value that agrees with the field is honoured as the no-op it is and
 * one that disagrees is a `501` — never a silent acceptance of an instruction that did
 * nothing.
 */
class PatchSigningRequestRequest extends FirmaRequest
{
    /** Members of the properties-only form, which this build cannot honour. */
    private const PROPERTY_MEMBERS = [
        'name', 'description', 'template_description', 'expiration_hours',
        // Post-pin (reference v1.39.0/v1.40.0, issue #171): a settings change and a seal
        // participant. Each is refused by name rather than dropped, so neither can be a silent
        // no-op. `documents_layout_version` is honoured instead: see guardLayoutVersion().
        'settings', 'seal_participant',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'field' => ['nullable', 'array'],
            'field.id' => ['nullable', 'string', 'max:191'],
            'field.template_field_id' => ['nullable', 'string', 'max:191'],
            'field.variable_name' => ['nullable', 'string', 'max:255'],
            'field.value' => ['nullable'],
            'field.final_value' => ['nullable'],
            'field.read_only_value' => ['nullable'],
            'field.prefilled_editable' => ['nullable', 'boolean'],
            'field.read_only' => ['nullable', 'boolean'],

            'recipient' => ['nullable', 'array'],
            'recipient.id' => ['nullable', 'string', 'max:191'],
            'recipient.first_name' => ['nullable', 'string', 'max:191'],
            'recipient.last_name' => ['nullable', 'string', 'max:191'],
            'recipient.name' => ['nullable', 'string', 'max:255'],
            'recipient.email' => ['nullable', 'string', 'email', 'max:320'],

            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'template_description' => ['nullable', 'string', 'max:2000'],
            'expiration_hours' => ['nullable', 'integer'],
            'settings' => ['nullable', 'array'],
            'seal_participant' => ['nullable', 'array'],
            'documents_layout_version' => ['nullable'],
        ];
    }

    /**
     * Upstream's precondition on a field write (reference v1.39.0, issue #182).
     *
     * `documents_layout_version` is "the `documents_layout_version` of your last read": required
     * for a field write on a request with more than one document or whose layout has changed, and
     * refused when stale. A caller that numbered pages before a reorder is told its numbers are
     * out of date instead of writing against the wrong page. Checked on any PATCH that sends it.
     *
     * @throws FirmaException
     */
    public function guardLayoutVersion(Envelope $envelope, bool $fieldWrite): void
    {
        $sent = $this->input('documents_layout_version');

        if ($sent === null) {
            if ($fieldWrite && ($envelope->documentSet()->isMultiple() || $envelope->documents_layout_version > 1)) {
                throw FirmaException::of(
                    FirmaErrorCode::DocumentsLayoutVersionRequired,
                    'This signing request has more than one document, or its documents have changed, so a field write '
                    .'must say which layout it was made against: send `documents_layout_version` from your last read.',
                    ['documents_layout_version' => $envelope->documents_layout_version],
                );
            }

            return;
        }

        if (! is_int($sent) && ! (is_string($sent) && ctype_digit($sent))) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                '`documents_layout_version` must be a non-negative integer.',
                ['key' => 'documents_layout_version'],
            );
        }

        if ((int) $sent !== $envelope->documents_layout_version) {
            throw FirmaException::of(
                FirmaErrorCode::DocumentSetVersionConflict,
                'The documents of this signing request have changed since layout version '.(int) $sent.'; the current '
                .'one is '.$envelope->documents_layout_version.'. Read the request again before writing.',
                ['documents_layout_version' => $envelope->documents_layout_version],
            );
        }
    }

    /**
     * The one entity this request updates.
     *
     * @return array{0: 'field'|'recipient', 1: array<string, mixed>}
     *
     * @throws FirmaException
     */
    public function target(): array
    {
        $body = $this->validated();
        $field = is_array($body['field'] ?? null) ? $body['field'] : null;
        $recipient = is_array($body['recipient'] ?? null) ? $body['recipient'] : null;

        if ($field !== null && $recipient !== null) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'A PATCH updates one entity: send `field` or `recipient`, not both.',
            );
        }

        // Checked before the "nothing to do" case, so a caller who sent only `name` is told
        // the title cannot change rather than that they sent nothing.
        UnsupportedOptions::guardPatchedProperties(array_values(array_filter(
            self::PROPERTY_MEMBERS,
            fn (string $member): bool => ($body[$member] ?? null) !== null,
        )));

        if ($field !== null) {
            return ['field', $field];
        }

        if ($recipient !== null) {
            return ['recipient', $recipient];
        }

        throw FirmaException::of(
            FirmaErrorCode::InvalidRequest,
            'A PATCH needs a `field` or a `recipient` to update.',
        );
    }
}
