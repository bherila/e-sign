<?php

declare(strict_types=1);

namespace App\Http\Requests\Signing;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Capture\ConsentPolicy;
use App\Domain\Signing\Envelopes\AcceptanceRequest;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use App\Domain\Signing\Sessions\GuestSigningContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /sign/{envelope}/session/accept — the assent itself.
 *
 * Five things have to be stated, and none of them is a formality:
 *
 * | Input | What it is for |
 * |---|---|
 * | `consent_accepted` | The signer ticked the electronic-signature notice. |
 * | `consent_version` | *Which* notice. Compared by the state machine against the envelope's snapshot; a mismatch is `ConsentMismatch`, never a silent acceptance of the current text. |
 * | `intent_confirmed` | The separate "I agree to be bound" statement. docs/HANDOFF.md section 8: a signature is never recorded merely because a canvas is non-empty, so drawing a mark and intending to sign are two acts and this is the second one. |
 * | `reviewed_material_sha256` | The digest of the values the page displayed. |
 * | `reviewed_envelope_version` | The version it displayed them at. |
 *
 * The last two are docs/ARCHITECTURE.md invariant 2 made unavoidable. They are echoed from
 * what was rendered rather than recomputed here, because recomputing them would satisfy the
 * check and destroy its purpose — {@see AcceptanceRequest} says the same thing about the
 * same two values, one layer down.
 *
 * `signature_field_ids` is the client's account of which signature boxes it required. The
 * server re-derives the same list from the schema and refuses if they differ, then checks
 * each one actually holds a value. Both halves matter: the re-derivation stops a client
 * shortening the list, and the stored-value check stops an acceptance whose signature boxes
 * are empty because the values POST failed and the page carried on regardless.
 */
class AcceptAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'consent_accepted' => ['accepted'],
            'intent_confirmed' => ['accepted'],
            'consent_version' => ['required', 'string', 'max:'.AcceptanceRequest::MAX_CONSENT_POLICY_VERSION_LENGTH],
            'reviewed_material_sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'reviewed_envelope_version' => ['required', 'integer', 'min:1'],
            'signature_field_ids' => ['present', 'array', 'max:100'],
            'signature_field_ids.*' => ['string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent_accepted.accepted' => 'Please confirm you have read the electronic signature notice.',
            'intent_confirmed.accepted' => 'Please confirm you intend to sign this agreement.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $context = app(GuestSigningContext::class);

            // The state machine compares the *claimed* consent version against the envelope's
            // snapshot, and both sides of that comparison are the snapshot — so it can only
            // catch a client that lied. It cannot catch the case where the file on disk is a
            // different version from the one the envelope records, because the text rendered
            // never enters the check. The page has always warned about that and then let the
            // signer through, producing an attestation naming a version whose wording nobody
            // saw (docs/security/review-2026-09.md finding S-3). This is the refusal.
            if (! app(ConsentPolicy::class)
                ->forRecordedVersion($context->envelope->consent_policy_version)
                ->matchesRecordedVersion()) {
                $validator->errors()->add(
                    'consent_version',
                    'The consent notice on this installation is not the version this agreement '
                    .'records. Ask the sender to confirm which applies before signing.',
                );

                return;
            }

            $required = $this->requiredSignatureFieldIds($context);

            /** @var list<string> $claimed */
            $claimed = array_values(array_map('strval', (array) $this->input('signature_field_ids', [])));

            sort($claimed);
            $expected = $required;
            sort($expected);

            if ($claimed !== $expected) {
                // Not a message about which ids are missing: the page already knows the
                // list, so a disagreement means it is out of date and the fix is to reload.
                $validator->errors()->add(
                    'signature_field_ids',
                    'This page is out of date with the agreement. Reload it and review again before signing.',
                );

                return;
            }

            $filled = $this->filledFieldIds($context, $required);

            foreach ($required as $fieldId) {
                if (! in_array($fieldId, $filled, true)) {
                    $validator->errors()->add(
                        'signature_field_ids',
                        'Every signature and initials box has to be completed before you can sign.',
                    );

                    return;
                }
            }
        });
    }

    /**
     * The signature and initials fields this recipient must complete, from the schema.
     *
     * @return list<string>
     */
    private function requiredSignatureFieldIds(GuestSigningContext $context): array
    {
        $fields = $context->envelope->fieldSchema()->fieldsFor($context->recipient->schema_recipient_id);

        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->id,
            array_filter(
                $fields,
                static fn (FieldDefinition $field): bool => $field->required
                    && ! $field->readOnly
                    && in_array($field->type->value, ['signature', 'initials'], true),
            ),
        ));
    }

    /**
     * Which of those ids actually have a stored value.
     *
     * Read from `envelope_field_values`, never from the request. "This box has been signed"
     * is a fact about the database, and taking the client's word for it is precisely the
     * failure docs/HANDOFF.md section 8 warns about.
     *
     * @param  list<string>  $fieldIds
     * @return list<string>
     */
    private function filledFieldIds(GuestSigningContext $context, array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }

        return EnvelopeFieldValue::query()
            ->where('envelope_id', $context->envelope->getKey())
            ->whereIn('schema_field_id', $fieldIds)
            ->pluck('schema_field_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
