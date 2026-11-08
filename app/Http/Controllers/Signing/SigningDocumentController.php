<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Signing\Sessions\GuestSigningContext;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The review revision's bytes, for the signer's own PDF.js viewer.
 *
 * A separate route from the administrative download endpoints, and that separation is the
 * whole point. `routes/documents.php` protects its two revision routes with `web` + `auth`
 * and a Form Request that resolves the workspace inside the caller's memberships. A guest
 * has no account, no membership, and no workspace, so they can never satisfy that stack —
 * and pointing the signing page at it would have meant loosening it for everybody.
 *
 * What this route serves is narrower in three ways at once:
 *
 * 1. **One revision.** Not "a revision named in the URL" — the URL names no revision at all.
 *    It streams `envelopes.document_revision_id`, the immutable snapshot the envelope was
 *    created against, so a guest cannot walk to the original upload, to a later revision, or
 *    to another document in the same workspace by editing an identifier.
 * 2. **One authorization.** The signing session, checked by `RequireSigningSession` against
 *    the envelope in the path.
 * 3. **Inline only.** There is no attachment variant. A signer downloading their copy gets
 *    the sealed PDF after completion, which is a different artifact with a different meaning;
 *    offering an "unexecuted agreement.pdf" download here would put a document that looks
 *    final and is not into people's downloads folders.
 *
 * Bytes stream through the application, never a presigned URL, on every driver
 * (docs/BLOB_STORAGE.md). Security headers come from the `signing` stack, which covers this
 * response exactly as it covers the HTML around it.
 */
class SigningDocumentController extends Controller
{
    /**
     * `$envelope` is the route's own binding, named so the router does not pass it in as the
     * position. The envelope this serves is always `$context->envelope`, the one the signing
     * session belongs to, and never a lookup of its own.
     */
    public function view(GuestSigningContext $context, DocumentBlobStore $blobs, mixed $envelope = null, ?string $position = null): StreamedResponse
    {
        // A document set's member by position (issue #163); with no position, the first — which
        // for a single-document envelope is the document.
        $entry = $context->envelope->documentSet()->entryAt($position === null ? 1 : (int) $position);

        if ($entry === null) {
            abort(404);
        }

        $revision = DocumentRevision::query()->with('document')->find($entry->revisionId);
        $document = $revision?->document;

        if ($revision === null || $document === null) {
            abort(404);
        }

        $stream = $blobs->disk($revision->disk)->readStream($revision->path);

        if (! is_resource($stream)) {
            // The row says the bytes are there and the disk says otherwise. A truncated 200
            // would look to PDF.js like a valid empty document, which is the one outcome a
            // signing page must not produce.
            throw new DocumentStorageException(
                "Revision {$revision->public_id} is recorded on disk [{$revision->disk}] but its object is not readable.",
            );
        }

        $filename = $revision->downloadFilename($document->title);

        return new StreamedResponse(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Length' => (string) $revision->bytes,
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_INLINE,
                    $filename,
                    $filename,
                ),
                // The digest of what is being sent, so the reviewed bytes can be checked
                // without a second request: against `envelopes.document_sha256` for a single
                // document, which the attestation binds; for a set, against this member's
                // digest, which the set digest covers (docs/preparation/document-sets.md).
                'X-Document-Sha256' => $revision->sha256,
            ],
        );
    }
}
