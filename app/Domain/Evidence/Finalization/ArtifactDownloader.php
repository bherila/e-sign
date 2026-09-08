<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a published artifact through the application.
 *
 * No route lives here on purpose: the HTTP surfaces own their own authorization, and this is
 * the shared body they call once a policy has already run. What it guarantees is the part
 * that must not be re-decided per surface:
 *
 *  - **Streamed, never presigned.** A presigned URL is a bearer token, the `local` driver
 *    cannot mint one at all, and an executed agreement must be re-authorized on every
 *    request (docs/BLOB_STORAGE.md rule 1; docs/HANDOFF.md section 12).
 *  - **A fixed content type per artifact kind**, taken from the kind and never from the
 *    stored object, a request parameter, or a filename. A content type a caller can
 *    influence is a way to have bytes interpreted as something they are not.
 *  - **A safe filename**, built from the envelope title reduced to an ASCII slug plus a
 *    digest prefix. Nothing in it comes from an uploader or a signer, so it cannot carry a
 *    quote, a path separator, a control character, or a right-to-left override.
 *  - **`X-Content-Type-Options: nosniff`**, so a browser cannot decide the bytes are
 *    something more interesting than a PDF.
 *
 * Unpublished artifacts are refused. A row without `published_at` describes bytes that exist
 * but that no completion has been asserted over, and handing those out would leak a document
 * the state machine has not accepted.
 */
final readonly class ArtifactDownloader
{
    public function __construct(private ArtifactStore $store) {}

    /**
     * @param  bool  $inline  True to display in place, false to download. Two different
     *                        dispositions are two different decisions; the caller makes it.
     *
     * @throws FinalizationException When the artifact is unpublished or unreadable.
     */
    public function stream(Artifact $artifact, bool $inline = false): StreamedResponse
    {
        if (! $artifact->isPublished()) {
            throw new FinalizationException(
                'That artifact has not been published, so it is not available for download.',
            );
        }

        $title = $artifact->relationLoaded('envelope')
            ? (string) $artifact->envelope?->title
            : (string) $artifact->envelope()->value('title');

        $filename = $artifact->downloadFilename($title);
        $stream = $this->store->readStream($artifact->disk, $artifact->path);

        return new StreamedResponse(
            function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            200,
            [
                'Content-Type' => $artifact->kind->contentType(),
                'Content-Length' => (string) $artifact->bytes,
                'Content-Disposition' => sprintf(
                    '%s; filename="%s"',
                    $inline ? 'inline' : 'attachment',
                    $filename,
                ),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
