<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestUsers;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /signing-requests/{id}/users` — `{"results": [...]}`.
 *
 * `results` and nothing else: `SigningRequestUserListResponse` has no pagination object, and
 * adding the native API's `{data, meta}` envelope here would break every consumer of this
 * profile (`docs/HANDOFF.md` section 10 — endpoint-specific shapes are preserved).
 *
 * The array is in signing order, but a consumer must key on `id` rather than on position:
 * upstream specifies no order for this array, so anything that depends on ours would be
 * depending on an accident.
 *
 * @mixin Envelope
 */
class SigningRequestUserListResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Envelope $envelope,
        private readonly SigningRequestUsers $users,
    ) {
        parent::__construct($envelope);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Envelope $envelope */
        $envelope = $this->resource;

        return ['results' => $this->users->results($envelope)];
    }
}
