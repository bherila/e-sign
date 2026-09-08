<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestFields;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compat\Firma\ShowSigningRequestFieldsRequest;
use App\Http\Resources\Compat\Firma\SigningRequestFieldListResource;
use Illuminate\Http\JsonResponse;

/**
 * `GET /signing-requests/{id}/fields`.
 */
class SigningRequestFieldController extends Controller
{
    public function __construct(private readonly SigningRequestFields $fields) {}

    public function index(ShowSigningRequestFieldsRequest $request): JsonResponse
    {
        return response()->json((new SigningRequestFieldListResource(
            $request->signingRequest(),
            $this->fields,
            $request->includeImages(),
        ))->resolve($request));
    }
}
