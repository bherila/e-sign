<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One page of a native list response.
 *
 * Every list endpoint answers `{"data": [...], "meta": {"next_cursor": ...}}`, and a null
 * `next_cursor` means there is nothing after this page. That is the whole pagination
 * contract: there is no total, because counting a tenant's rows on every request buys a
 * number that is stale before it is rendered, and no page number, because there are no
 * pages to number in a keyset scheme.
 *
 * {@see complete()} exists for the collections that are bounded by an envelope's own field
 * schema — recipients, values, artifacts. They are returned whole rather than paged, and
 * they still carry `meta.next_cursor: null` so a client writes one loop for every list.
 *
 * @template TItem
 */
final readonly class Page
{
    /**
     * @param  list<TItem>  $items
     */
    private function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}

    /** The default page size, and the largest one a caller may ask for. */
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /**
     * A collection small enough that paging it would be theatre.
     *
     * @param  list<TItem>  $items
     * @return self<TItem>
     */
    public static function complete(array $items): self
    {
        return new self($items, null);
    }

    /**
     * Read one keyset page from a query ordered by its primary key.
     *
     * The query is asked for one row more than the limit; the extra row is what proves
     * there is a next page without a second `count(*)`, and it is dropped before the page
     * is returned.
     *
     * @param  Builder<covariant Model>  $query  Already constrained to the caller's workspace.
     * @return self<Model>
     */
    public static function keyset(Builder $query, ?string $cursor, int $limit): self
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $table = $query->getModel()->getTable();
        $key = $table.'.'.$query->getModel()->getKeyName();

        if ($cursor !== null && $cursor !== '') {
            $query->where($key, '>', Cursor::decode($cursor));
        }

        $rows = $query->orderBy($key)->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return new self(
            array_values($page->all()),
            $hasMore && $last instanceof Model ? Cursor::encode((int) $last->getKey()) : null,
        );
    }

    /**
     * @template TMapped
     *
     * @param  callable(TItem): TMapped  $map
     * @return array{data: list<TMapped>, meta: array{next_cursor: string|null}}
     */
    public function toResponse(callable $map): array
    {
        return [
            'data' => array_values(array_map($map, $this->items)),
            'meta' => ['next_cursor' => $this->nextCursor],
        ];
    }
}
