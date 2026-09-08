<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `200` for `PATCH /signing-requests/{id}` in its `field` form.
 *
 * **Disagreement D6**: two of the three `oneOf` entries for this response are bare
 * `{"type": "object"}` upstream, with a description and no properties at all, and there is no
 * captured fixture for a PATCH. The document simply cannot pin this body.
 *
 * So the facade returns the field object `GET /fields` returns for that field — the one shape
 * a consumer of this profile already knows how to read — plus `warning`.
 *
 * `warning` is **singular** here. `warnings` (plural, an array) belongs to the create
 * response, and disagreement D7 says to preserve the difference rather than normalise one
 * into the other. It is null unless there is something to say.
 */
class PatchedFieldResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<string, mixed>  $field  A row as `/fields` renders it.
     */
    public function __construct(array $field, private readonly ?string $warning = null)
    {
        parent::__construct($field);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $field */
        $field = $this->resource;

        return $field + ['warning' => $this->warning];
    }
}
