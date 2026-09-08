<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\Credentials\ServiceCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/me`: what this credential is and what it may do.
 *
 * The first call every integrator makes, and the answer to "which workspace am I actually
 * talking to" — which on this API is never a parameter, so there is no other way to see it.
 *
 * `prefix` is the public half of the key: safe to print, safe to quote in a ticket, and the
 * identifier every console command uses. The secret is not here and is not derivable from
 * anything here (docs/operations/service-credentials.md).
 *
 * @mixin ServiceCredential
 */
class PrincipalResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ServiceCredential $credential */
        $credential = $this->resource;
        $workspace = $credential->workspace;

        return [
            'credential' => [
                'prefix' => $credential->prefix,
                'label' => $credential->label,
                'expires_at' => $credential->expires_at?->toIso8601String(),
                'last_used_at' => $credential->last_used_at?->toIso8601String(),
                'created_at' => $credential->created_at?->toIso8601String(),
            ],
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'scopes' => $credential->scopes ?? [],
        ];
    }
}
