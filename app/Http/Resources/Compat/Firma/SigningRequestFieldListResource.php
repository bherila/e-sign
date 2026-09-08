<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestFields;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /signing-requests/{id}/fields` — `{"results": [...]}`.
 *
 * `SigningRequestFieldListResponse` upstream: `results` and no pagination. Every row carries
 * both coordinate spellings and both value aliases; see
 * App\Domain\Integration\Firma\SigningRequestFields for what each member reports and which
 * ones are null on purpose.
 *
 * @mixin Envelope
 */
class SigningRequestFieldListResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Envelope $envelope,
        private readonly SigningRequestFields $fields,
        private readonly bool $includeImages,
    ) {
        parent::__construct($envelope);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Envelope $envelope */
        $envelope = $this->resource;

        return ['results' => $this->fields->results($envelope, $this->includeImages)];
    }
}
