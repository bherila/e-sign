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
 *
 * ## Proven once per revision, for the life of the request
 *
 * Bytes that have been read and proved are kept, keyed by the revision and the digest they were
 * proved against. That is a correctness property before it is a performance one, and the case it
 * exists for is `POST /signing-requests/create-and-send`: the facade reads the review revision to
 * resolve anchors, commits the envelope, and then `send()` resolves them again — because a receipt
 * is never a licence to skip resolution. Two reads mean a window between them, and an object that
 * becomes unavailable inside it produces the worst possible outcome for an operation whose whole
 * contract is that it is one call: the client gets an error, a draft exists anyway, and a retry
 * makes a second one. Carrying the proven bytes through the request removes the window rather
 * than compensating for it afterwards — a rollback would itself have to succeed while the object
 * store is the thing that is failing.
 *
 * The binding is `scoped`, so the memo lives exactly as long as one request or one queued job and
 * a worker never accumulates across them. What it holds is one document per revision touched,
 * which is bounded by the upload size limit and by how many revisions a single request can name —
 * in practice one.
 */
final class RevisionBytes
{
    /** @var array<string, string> Revision key and digest => bytes already proved to be theirs. */
    private array $proven = [];

    public function __construct(private readonly DocumentBlobStore $blobs) {}

    /**
     * @throws DocumentStorageException When the object cannot be read, is empty, or is not the
     *                                  object the revision row records.
     */
    public function read(DocumentRevision $revision): string
    {
        $memo = $revision->getKey().':'.$revision->sha256;

        if (array_key_exists($memo, $this->proven)) {
            return $this->proven[$memo];
        }

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

        return $this->proven[$memo] = $bytes;
    }
}
