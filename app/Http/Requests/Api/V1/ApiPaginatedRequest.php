<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Integration\Native\Cursor;
use App\Domain\Integration\Native\Page;

/**
 * A list endpoint's `cursor` and `limit`.
 *
 * `cursor` is validated as a string here and decoded by
 * {@see Cursor}, which is what decides whether it is one this
 * API issued — a rule about the cursor's own encoding does not belong in a validation
 * message a client cannot act on.
 *
 * `limit` is clamped rather than rejected above the maximum, because a client asking for 500
 * wants as many as it can have, and failing the request teaches it nothing it could not have
 * been told by returning 100.
 */
class ApiPaginatedRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function cursor(): ?string
    {
        $cursor = $this->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function limit(): int
    {
        $limit = $this->query('limit');

        if (! is_numeric($limit)) {
            return Page::DEFAULT_LIMIT;
        }

        return max(1, min((int) $limit, Page::MAX_LIMIT));
    }
}
