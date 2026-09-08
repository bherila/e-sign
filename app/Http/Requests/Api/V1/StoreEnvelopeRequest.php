<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Integration\Native\NewEnvelope;
use App\Domain\Signing\Envelopes\SigningMode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/v1/envelopes`.
 *
 * Two sources, exactly one of them: a published `template_version_id`, or a `document_id`
 * with the `field_schema` to place on it. They are mutually required and mutually exclusive
 * rather than "template wins if both are given", because a caller that sent both has a bug
 * and silently picking one hides it behind an envelope that is subtly not what was asked for.
 *
 * The field schema is not validated in detail here. Its rules are large, versioned, and
 * already implemented by App\Domain\Preparation\Schema\FieldSchemaDocument, which the
 * snapshot re-runs on the way in; duplicating a subset of them in a Form Request would give
 * two answers to "is this schema valid" and one of them would go stale.
 *
 * `assurance_level` and `signing_mode` are validated against their enums so an unrecognised
 * value is a 422 naming the alternatives, never a silent downgrade to the default
 * (docs/HANDOFF.md section 9: a requested assurance level that cannot be met is an error).
 */
class StoreEnvelopeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_version_id' => ['nullable', 'string', 'max:64'],
            'document_id' => ['nullable', 'string', 'max:64'],
            'field_schema' => ['nullable', 'array'],

            'title' => ['nullable', 'string', 'max:255'],
            'consent_policy_version' => ['nullable', 'string', 'max:64'],
            'assurance_level' => ['nullable', Rule::in(array_column(AssuranceLevel::cases(), 'value'))],
            'signing_mode' => ['nullable', Rule::in(SigningMode::values())],

            // Null is accepted and means "never expires", which is different from omitting
            // the key (which takes the seven-day default).
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:87600'],

            'recipients' => ['sometimes', 'array'],
            'recipients.*.id' => ['required', 'string', 'max:128'],
            'recipients.*.name' => ['nullable', 'string', 'max:255'],
            'recipients.*.email' => ['nullable', 'string', 'email', 'max:255'],

            'values' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fromTemplate = $this->filled('template_version_id');
            $fromDocument = $this->filled('document_id');

            if ($fromTemplate === $fromDocument) {
                $validator->errors()->add(
                    'template_version_id',
                    'Provide exactly one of template_version_id or document_id.',
                );
            }

            if ($fromDocument && ! is_array($this->input('field_schema'))) {
                $validator->errors()->add(
                    'field_schema',
                    'An envelope built from a document needs the field_schema to place on it.',
                );
            }

            if ($fromTemplate && $this->has('field_schema')) {
                $validator->errors()->add(
                    'field_schema',
                    'A template version already carries its field schema; sending another one would be ignored.',
                );
            }
        });
    }

    public function envelopeInput(): NewEnvelope
    {
        return NewEnvelope::fromValidated($this->validated());
    }
}
