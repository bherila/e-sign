<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DownloadRevisionRequest;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves revision bytes by streaming them through the application.
 *
 * Never a presigned URL, on any driver. docs/BLOB_STORAGE.md rule 1: authorization becomes
 * per-user and per-record instead of per-URL-possession, the storage driver stops leaking
 * into the application (the `local` driver cannot presign at all and throws), and one code
 * path serves both drivers because both implement `readStream()`.
 *
 * Two routes, not one that guesses. `download` sends `Content-Disposition: attachment`;
 * `view` sends `inline`, which is what an `<iframe>` or the PDF.js viewer needs — serve an
 * attachment into an iframe and the browser downloads the file instead of rendering it,
 * with no error anywhere.
 *
 * The filename comes from the document title, slugged, never from the uploaded filename,
 * and it is emitted through Symfony's header builder so it cannot break out of the header.
 * The content type is fixed at application/pdf with `X-Content-Type-Options: nosniff`
 * rather than taken from anything stored, so a mislabelled upload cannot cause a browser to
 * execute it as something else.
 */
class DocumentRevisionDownloadController extends Controller
{
    public function download(DownloadRevisionRequest $request, DocumentBlobStore $blobs): StreamedResponse
    {
        return $this->stream($request, $blobs, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    /**
     * Inline, for the locally served PDF.js viewer — but never for a document preflight
     * rejected.
     *
     * `DocumentIntake` stores the original *before* the accept/reject decision, deliberately,
     * so a rejected upload can be examined afterwards. The 422 that refuses it hands the
     * uploader the document and revision ids, so they get the exact inline URL for the bytes
     * policy has just called unsafe — JavaScript, XFA, a launch action, an embedded file —
     * and any workspace member with `View` renders them inline from this application's own
     * origin (docs/security/review-2026-09.md finding U-5).
     *
     * Forensics does not need an inline render. The attachment route still serves the bytes,
     * which is what an investigator wants anyway: a file to open deliberately, in a tool they
     * chose.
     */
    public function view(DownloadRevisionRequest $request, DocumentBlobStore $blobs): StreamedResponse
    {
        if ($request->document()->status === DocumentStatus::PreflightFailed) {
            abort(404);
        }

        return $this->stream($request, $blobs, HeaderUtils::DISPOSITION_INLINE);
    }

    private function stream(
        DownloadRevisionRequest $request,
        DocumentBlobStore $blobs,
        string $disposition,
    ): StreamedResponse {
        $revision = $request->revision();
        $filename = $revision->downloadFilename($request->document()->title);

        $stream = $blobs->disk($revision->disk)->readStream($revision->path);

        if (! is_resource($stream)) {
            // The row says the bytes are there and the disk says otherwise. Fail loudly:
            // a truncated or empty 200 would look to a client like a valid empty PDF.
            throw new DocumentStorageException(
                "Revision {$revision->public_id} is recorded on disk [{$revision->disk}] but its object is not readable.",
            );
        }

        return new StreamedResponse(
            function () use ($stream): void {
                // Copy straight from the storage stream to the output stream. Nothing
                // buffers the document, so a 200-page contract costs the same memory as a
                // one-page one on every driver.
                fpassthru($stream);
                fclose($stream);
            },
            200,
            $this->headers($revision, $filename, $disposition),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(DocumentRevision $revision, string $filename, string $disposition): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $revision->bytes,
            // The fallback is the same string: downloadFilename() is a slug, so it is
            // already pure ASCII and Symfony emits one plain `filename="..."` rather than a
            // filename* extension nobody needs.
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $filename),
            'X-Content-Type-Options' => 'nosniff',
            // Documents are private and per-request authorized; a shared cache must never
            // hold one, and a browser must not reuse it after the session changes.
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            // The digest of what is being sent, so a caller can verify the bytes it got
            // against the metadata endpoint without a second request.
            'X-Document-Sha256' => $revision->sha256,
        ];
    }
}
