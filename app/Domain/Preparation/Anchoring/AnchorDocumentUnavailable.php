<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Anchoring;

use RuntimeException;
use Throwable;

/**
 * The document's bytes could not be read, so its anchors could not be resolved.
 *
 * Deliberately not an {@see AnchorResolutionFailed}. That type means the document was read and
 * the field set is wrong — a text that is not there, an anchor that matches twice — and both HTTP
 * surfaces report it as a 422 telling the caller to correct their input. A disk that is down is
 * not a field set that is wrong: answering 422 tells a sender to fix a document that is perfectly
 * valid, and it puts the failure in the client's non-retryable bucket, so nothing ever tries
 * again.
 *
 * So this is a server-side failure with its own code, and it is retryable in the ordinary sense:
 * the same request, unchanged, may well succeed once storage is back.
 *
 * The message is deliberately free of storage handles. The disk name and object path go to the
 * log with the original exception; a caller gets a sentence and a code
 * (docs/BLOB_STORAGE.md, and the same rule every other document response follows).
 */
final class AnchorDocumentUnavailable extends RuntimeException
{
    public static function forRevision(string $revisionPublicId, ?Throwable $previous = null): self
    {
        return new self(
            'The document could not be read, so its anchors could not be resolved. Nothing about the '
            .'request needs to change; this is a storage failure on the service side and the same call '
            .'may succeed on a retry.',
            previous: $previous,
        );
    }

    public function code(): string
    {
        return 'anchor_document_unavailable';
    }
}
