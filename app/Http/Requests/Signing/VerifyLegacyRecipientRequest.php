<?php

declare(strict_types=1);

namespace App\Http\Requests\Signing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /signing/{recipient}/verify — the compatibility resolver's second step.
 *
 * Only the shape is checked, and loosely. A code of the wrong length is still an attempt and
 * still has to cost one: rejecting it here as a validation error would let an attacker probe
 * the endpoint without ever touching the per-challenge attempt counter.
 */
class VerifyLegacyRecipientRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:16'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->trim()->value();
    }
}
