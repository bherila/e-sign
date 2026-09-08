<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use Carbon\CarbonImmutable;
use Closure;

/**
 * One finished artifact, described in the terms an HTTP response needs and no others.
 *
 * There is no disk, no bucket, and no object path on this object, for the reason
 * App\Http\Resources\Documents\DocumentRevisionResource gives: a client that learns a
 * storage key is one presigning bug away from bypassing the workspace boundary. The bytes
 * are reached only through {@see open()}, which the application copies to the response.
 *
 * `sha256` is the digest finalization recorded and validated, so a caller can verify what it
 * downloaded against the digest in the completion event without trusting the transfer.
 */
final readonly class LocatedArtifact
{
    /**
     * @param  string  $id  Public identifier; appears in the download URL.
     * @param  string  $kind  What this artifact is, e.g. `sealed_pdf` or `evidence_summary`.
     * @param  string  $filename  ASCII download name. Never the uploaded filename.
     * @param  string  $contentType  Fixed by the producer, never sniffed from the bytes.
     * @param  string  $sha256  Lowercase hex digest recorded at finalization.
     * @param  int  $bytes  Exact length, used for `Content-Length`.
     * @param  Closure(): mixed  $stream  Opens a readable stream. Returns a resource, or
     *                                    false/null if the object has gone missing, which
     *                                    the caller turns into a loud failure rather than a
     *                                    short 200.
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $filename,
        public string $contentType,
        public string $sha256,
        public int $bytes,
        public ?CarbonImmutable $createdAt,
        private Closure $stream,
    ) {}

    /**
     * @return resource|false|null
     */
    public function open(): mixed
    {
        return ($this->stream)();
    }
}
