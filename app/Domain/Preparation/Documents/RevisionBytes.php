<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use App\Domain\Preparation\Documents\Models\DocumentRevision;
use Throwable;

/**
 * Reads a revision's stored object and proves it is the object the row describes.
 *
 * {@see DocumentBlobStore::putVerified()} confirms the bytes at the moment they are written; this
 * confirms them at the moment they are used, which is a different question. Between the two the
 * object can be replaced, truncated, or restored from the wrong backup, and nothing about a
 * successful `get()` says otherwise.
 *
 * It matters most where a digest is about to be *asserted*. Anchor resolution measures text in
 * these bytes and then records `document_sha256` as the revision's digest, which is what lets
 * everything downstream skip re-resolving; and an attestation binds that same digest. Measuring
 * replacement bytes and labelling the result with the recorded digest would invite signers against
 * a document that is not the one the envelope is bound to, and finalization would only notice
 * afterwards — after signing. Reading through here makes that impossible rather than unlikely
 * (AGENTS.md, "Retain originals byte-for-byte").
 */
final readonly class RevisionBytes
{
    public function __construct(private DocumentBlobStore $blobs) {}

    /**
     * @throws DocumentStorageException When the object cannot be read, is empty, or is not the
     *                                  object the revision row records.
     */
    public function read(DocumentRevision $revision): string
    {
        try {
            $bytes = $this->blobs->disk((string) $revision->disk)->get((string) $revision->path);
        } catch (Throwable $exception) {
            throw new DocumentStorageException(
                'Document revision '.$revision->public_id.' could not be read from disk ['.$revision->disk.']: '
                .$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new DocumentStorageException(
                'Document revision '.$revision->public_id.' has no readable bytes on disk ['.$revision->disk.'].',
            );
        }

        $actual = hash('sha256', $bytes);

        if (! hash_equals((string) $revision->sha256, $actual)) {
            throw new DocumentStorageException(
                'Document revision '.$revision->public_id.' reads back as '.$actual.', and the row records '
                .$revision->sha256.'. The stored object is not the revision it claims to be, so nothing may be '
                .'measured in it and no digest may be asserted about it.',
            );
        }

        return $bytes;
    }
}
