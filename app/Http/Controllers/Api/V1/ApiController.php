<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\Page;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared response shaping for the native API.
 *
 * One list envelope, `{"data": [...], "meta": {"next_cursor": ...}}`, for every collection
 * this API returns, so a client writes one paging loop instead of one per endpoint. That
 * uniformity is a native-API decision and stops here: the Firma facade owes somebody else's
 * shapes on its own routes and must not inherit these (docs/HANDOFF.md section 10).
 *
 * Single resources are returned unwrapped — the object itself, at the top level. Wrapping a
 * single resource in `data` buys nothing a client can use and costs an indirection on every
 * read.
 */
abstract class ApiController extends Controller
{
    /**
     * @template TModel
     *
     * @param  Page<TModel>  $page
     * @param  class-string<JsonResource>  $resource
     */
    protected function page(Request $request, Page $page, string $resource): JsonResponse
    {
        return response()->json($page->toResponse(
            static fn (mixed $item): array => $resource::make($item)->resolve($request),
        ));
    }

    /**
     * A collection small enough to return whole. Still carries `meta.next_cursor: null`, so
     * a client's paging loop does not have to know which lists are bounded.
     *
     * @param  list<mixed>  $items
     * @param  class-string<JsonResource>  $resource
     */
    protected function collection(Request $request, array $items, string $resource): JsonResponse
    {
        return $this->page($request, Page::complete($items), $resource);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function item(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }
}
