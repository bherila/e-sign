<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestDownloads;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /signing-requests/{id}/download` — the JSON body, not the bytes.
 *
 * `{status, is_partial, download_url, generated_at, expires_at}`, which is
 * `SigningRequestDownloadResponse` upstream. `status` here is a **string** enum
 * (`finished|in_progress|cancelled|declined|expired`) — the third status representation in
 * this profile, distinct from the create response's string and the detail response's object
 * of booleans. All three are intentional.
 *
 * What is behind `download_url`, when it is partial, and why it is an app-issued link rather
 * than a storage pre-sign: App\Domain\Integration\Firma\SigningRequestDownloads and
 * App\Domain\Integration\Firma\DownloadUrlIssuer.
 *
 * @mixin Envelope
 */
class SigningRequestDownloadResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Envelope $envelope,
        private readonly SigningRequestDownloads $downloads,
    ) {
        parent::__construct($envelope);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Envelope $envelope */
        return $this->downloads->describe($this->resource);
    }
}
