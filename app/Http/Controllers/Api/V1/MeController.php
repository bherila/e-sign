<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Http\Resources\Api\V1\PrincipalResource;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me`.
 *
 * Behind `service-credential` and no scope at all, deliberately. Every credential may ask
 * what it is; requiring a resource scope to answer "who am I and which workspace is this"
 * would mean a webhooks-only credential could not verify its own configuration, and the
 * answer contains nothing the caller did not already hold.
 */
class MeController extends ApiController
{
    public function show(ApiRequest $request): JsonResponse
    {
        return $this->item(
            PrincipalResource::make($request->credential())->resolve($request),
        );
    }
}
