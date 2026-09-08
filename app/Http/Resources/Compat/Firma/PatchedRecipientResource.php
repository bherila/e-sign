<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `200` for `PATCH /signing-requests/{id}` in its `recipient` form.
 *
 * Untyped upstream for the same reason as the field form (disagreement D6), so the facade
 * returns the party as `GET /users` renders them, plus the singular `warning`. A consumer
 * that can read `/users` can read this.
 */
class PatchedRecipientResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<string, mixed>  $recipient  A row as `/users` renders it.
     */
    public function __construct(array $recipient, private readonly ?string $warning = null)
    {
        parent::__construct($recipient);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $recipient */
        $recipient = $this->resource;

        return $recipient + ['warning' => $this->warning];
    }
}
