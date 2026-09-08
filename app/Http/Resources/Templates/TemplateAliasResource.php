<?php

declare(strict_types=1);

namespace App\Http\Resources\Templates;

use App\Domain\Preparation\Templates\Models\TemplateAlias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A foreign identifier mapped onto a template.
 *
 * `source` is echoed so a client can tell an imported provider id from an operator-assigned
 * handle without inferring it from the string's shape.
 *
 * @mixin TemplateAlias
 */
class TemplateAliasResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TemplateAlias $alias */
        $alias = $this->resource;

        return [
            'alias' => $alias->alias,
            'source' => $alias->source->value,
            'created_at' => $alias->created_at?->toIso8601String(),
        ];
    }
}
