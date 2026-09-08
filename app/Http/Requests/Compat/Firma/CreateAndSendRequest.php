<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/**
 * `POST /signing-requests/create-and-send`.
 *
 * The same body as create with two members promoted to required, which is upstream's own
 * difference between the routes: this call sends, so there has to be something to send and
 * somebody to send it to. Everything a `POST /signing-requests` draft could be fixed up
 * afterwards, this one cannot.
 */
class CreateAndSendRequest extends StoreSigningRequestRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'name' => ['required', 'string', 'max:255'],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
        ]);
    }
}
