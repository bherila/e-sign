<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/**
 * `POST /signing-requests/{id}/cancel`. Body optional, as upstream's is.
 */
class CancelSigningRequestRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
            'notify_signers' => ['nullable', 'boolean'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * Whether the caller asked to suppress the notice, or said nothing.
     *
     * Null and true are the same instruction — notify, which is what this service does
     * anyway. Only an explicit `false` is a request this build cannot honour, and it is
     * refused with a 501 rather than accepted and disregarded.
     */
    public function notifySigners(): ?bool
    {
        $value = $this->validated('notify_signers');

        return is_bool($value) ? $value : null;
    }
}
