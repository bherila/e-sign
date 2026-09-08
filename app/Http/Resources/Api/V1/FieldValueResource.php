<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Integration\Native\FieldValueView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One field's value.
 *
 * `value` holds the value for every field type except `signature` and `initials`, which are
 * described under `signature` instead — media type, digest, and byte length — and carry
 * their bytes only when the request asked for them with `?include=images`.
 * {@see FieldValueView} explains why that is the default.
 *
 * `source` says who supplied the value: the `sender` (a prefill or a correction made while
 * the content was open), the `recipient` who owns the field, or the `service` for
 * `signing_date`, which is derived from the owning recipient's attestation and never
 * submitted by anyone.
 *
 * A field with no value yet is present with `value: null`. Omitting it would be
 * indistinguishable from a field the schema does not have.
 *
 * @mixin FieldValueView
 */
class FieldValueResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FieldValueView $view */
        $view = $this->resource;

        return [
            'field_id' => $view->fieldId,
            'type' => $view->type->value,
            'recipient_id' => $view->recipientId,
            'required' => $view->required,
            'read_only' => $view->readOnly,
            'source' => $view->source,
            'value' => $view->value,
            'signature' => $view->signature,
            'frozen_at' => $view->frozenAt?->toIso8601String(),
        ];
    }
}
