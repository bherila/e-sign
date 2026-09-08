<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestUsers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compat\Firma\FirmaRequest;
use App\Http\Resources\Compat\Firma\SigningRequestUserListResource;
use Illuminate\Http\JsonResponse;

/**
 * `GET /signing-requests/{id}/users`.
 */
class SigningRequestUserController extends Controller
{
    public function __construct(private readonly SigningRequestUsers $users) {}

    public function index(FirmaRequest $request): JsonResponse
    {
        return response()->json(
            (new SigningRequestUserListResource($request->signingRequest(), $this->users))->resolve($request),
        );
    }
}
