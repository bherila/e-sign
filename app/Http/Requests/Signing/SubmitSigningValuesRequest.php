<?php

declare(strict_types=1);

namespace App\Http\Requests\Signing;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Fields\FieldMateriality;
use App\Domain\Signing\Sessions\GuestSigningContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /sign/{envelope}/session/values — the recipient saves what they have filled in.
 *
 * What this checks is *whose fields these are*, and only that. Types are normalised by
 * `FieldValueValidator` inside the state machine, and signature images are decoded and
 * re-encoded by `SignatureImage`; duplicating either here would create a second definition
 * of what a valid value is, and the two would drift.
 *
 * The ownership check is duplicated on purpose, though, and the duplication is the point.
 * `EnvelopeStateMachine::submitValues()` enforces docs/ARCHITECTURE.md invariant 1 by itself
 * and refuses a foreign field with an exception. Checking it here as well turns that
 * exception into a field-level validation message on the right input, which is what a signer
 * needs, while the domain keeps the authority. If this rule were ever deleted the request
 * would still be refused — one layer down, with a worse message.
 *
 * The context comes from the container rather than from the route, so the recipient this
 * validates against is the same row the controller acts on. Re-resolving it here from the
 * URL would open a gap between the check and the write.
 */
class SubmitSigningValuesRequest extends FormRequest
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
            // Present rather than required: clearing the last value a recipient had typed is
            // a legitimate submission, and `[]` is how a client says it.
            'values' => ['present', 'array', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $context = app(GuestSigningContext::class);
            $writable = $this->writableFieldIds($context);

            /** @var array<string, mixed> $values */
            $values = $this->input('values', []);

            foreach (array_keys($values) as $fieldId) {
                if (! in_array((string) $fieldId, $writable, true)) {
                    // Same message for "no such field" and "somebody else's field". A
                    // signer has no use for the difference and a prober would.
                    $validator->errors()->add(
                        'values.'.$fieldId,
                        'This is not a field you can complete on this agreement.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        /** @var array<string, mixed> $values */
        $values = $this->validated()['values'] ?? [];

        return $values;
    }

    /**
     * Fields this recipient may write: theirs, not read-only, not service-supplied.
     *
     * `signing_date` is the service-supplied one, and it is excluded because its value is
     * the recipient's own `recipient_attestations.accepted_at` — derived from an immutable
     * attestation rather than typed into a mutable table where the two could disagree
     * (docs/signing/state-machine.md).
     *
     * @return list<string>
     */
    private function writableFieldIds(GuestSigningContext $context): array
    {
        $fields = $context->envelope->fieldSchema()->fieldsFor($context->recipient->schema_recipient_id);

        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->id,
            array_filter(
                $fields,
                static fn (FieldDefinition $field): bool => ! $field->readOnly
                    && ! FieldMateriality::isServiceSupplied($field->type),
            ),
        ));
    }
}
