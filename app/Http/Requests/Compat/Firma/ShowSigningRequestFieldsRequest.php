<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use App\Domain\Integration\Firma\FirmaProfile;

/**
 * `GET /signing-requests/{id}/fields`, with the one query parameter that changes its body.
 *
 * `?include=images` returns a captured signature as its data URL instead of the marker
 * string. It is opt-in for the reason App\Domain\Integration\Native\FieldValueView gives: an
 * envelope carries one signature image per signer, and a response that ships them unasked
 * puts somebody's signature in the caller's request logs, proxy caches and error reports.
 */
class ShowSigningRequestFieldsRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include' => ['nullable', 'string', 'in:'.FirmaProfile::INCLUDE_IMAGES],
        ];
    }

    public function includeImages(): bool
    {
        return $this->validated('include') === FirmaProfile::INCLUDE_IMAGES;
    }
}
