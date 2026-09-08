<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\ErrorCode;
use App\Http\Controllers\Controller;

/**
 * Anything under `/api/v1` that no route serves.
 *
 * Without it, an unmatched path would be rendered by the application's global handler in the
 * flat `{message}` shape, and a client's error parser would break on exactly the responses it
 * is most likely to hit while it is being written — a typo in a path, a version that moved.
 * Fail closed, and in this API's own shape.
 *
 * A controller rather than a closure because `php artisan route:cache` runs at container
 * start (`.docker/scripts/entrypoint.sh`) and refuses to serialise a route backed by a
 * closure. A closure here would work in every test and fail on the first production boot.
 */
class UnknownEndpointController extends Controller
{
    public function __invoke(): never
    {
        throw ApiException::of(
            ErrorCode::NotFound,
            'No such endpoint on the native API. GET /api/v1/openapi.json lists everything that exists.',
        );
    }
}
