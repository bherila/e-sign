<?php

declare(strict_types=1);

namespace App\Http\Requests\Signing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /signing/{recipient} — the compatibility resolver's first step.
 *
 * The caller states the address they believe the invitation went to. The response is
 * identical whether they are right or wrong, so the shape rules here are the only thing that
 * can produce a visible difference — and they are about the string being an address at all,
 * never about it being *the* address.
 *
 * `email` is validated with `email:filter` rather than DNS-aware rules on purpose: a rule
 * that resolves MX records makes the response time depend on the domain, which is a side
 * channel in a flow whose whole design is to answer identically either way.
 */
class ResolveLegacyRecipientRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:filter', 'max:191'],
        ];
    }

    public function email(): string
    {
        return mb_strtolower($this->string('email')->trim()->value());
    }
}
