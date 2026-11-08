<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Evidence\Finalization\ArtifactDownloader;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Integration\Native\FinalizedArtifactLocator;
use App\Domain\Preparation\Documents\DocumentBlobStore;
use App\Domain\Preparation\Documents\DocumentSetEntry;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What `GET /signing-requests/{id}/download` reports, and the bytes behind it.
 *
 * ## Two documents, and which one a caller gets
 *
 * A signing request has at most two sets of retained bytes, and the profile's `is_partial`
 * flag is exactly the question of which one is being served:
 *
 * - **Completed** → the executed PDF: the agreement with every value drawn on it, sealed
 *   under the service certificate. `status: "finished"`, `is_partial: false`.
 * - **Anything else that has been sent** → the reviewed revision: the bytes every party was
 *   actually shown, with nobody's signature on them. `is_partial: true`, and `status` says
 *   why (`in_progress`, `cancelled`, `declined`, `expired`).
 *
 * The recorded fixtures confirm this is upstream's behaviour rather than an error: an
 * unfinished or cancelled request answers HTTP 200 with `is_partial: true` and a
 * document-only URL (`tests/Fixtures/firma/firma-compat-v1/README.md`). Disagreement D11
 * notes upstream gates partial downloads on an `allow_partial_download` setting that does
 * not exist in its own schema; there is nothing to gate on, so the flag is simply reported
 * truthfully.
 *
 * **A draft is a 409.** Nothing has been shown to anybody, so there is no reviewed document
 * a caller is entitled to as "the signing request's document" — and answering with the
 * upload would hand out bytes before anyone consented to see them. Upstream's own token,
 * `no_document_available`, is reproduced verbatim.
 *
 * ## Never re-rendered
 *
 * Both objects are streamed exactly as retained. The reviewed revision is the immutable
 * `document_revisions` row every acceptance is bound to, and the executed PDF is the sealed
 * artifact whose digest was recorded and re-verified at publication. Neither is rebuilt to
 * serve a download (AGENTS.md, "Retain originals byte-for-byte").
 *
 * `FinalizedArtifactLocator` is named concretely here, rather than the
 * App\Domain\Integration\Native\ArtifactLocator interface, for one reason: this route needs
 * "the executed PDF of this agreement" as a question, which the interface's opaque-id lookup
 * cannot ask, and it needs the row itself so that {@see ArtifactDownloader} — the one shared
 * body that fixes content type by artifact kind, refuses an unpublished row, and builds a
 * safe filename — produces the response.
 */
final readonly class SigningRequestDownloads
{
    public function __construct(
        private FinalizedArtifactLocator $artifacts,
        private ArtifactDownloader $downloader,
        private DocumentBlobStore $blobs,
        private DownloadUrlIssuer $urls,
    ) {}

    /**
     * The `/download` body.
     *
     * @return array{status: string, is_partial: bool, download_url: string, generated_at: string|null, expires_at: string}
     *
     * @throws FirmaException
     */
    public function describe(Envelope $envelope, ?CarbonImmutable $now = null): array
    {
        if ($envelope->state === EnvelopeState::Draft) {
            throw FirmaException::of(
                FirmaErrorCode::NoDocumentAvailable,
                'Signing request has not been sent yet, so there is no document to download.',
            );
        }

        $expiresAt = $this->urls->expiresAt($now);
        $finished = $envelope->state === EnvelopeState::Completed;

        if ($finished) {
            $artifact = $this->executed($envelope);

            return [
                'status' => 'finished',
                'is_partial' => false,
                'download_url' => $this->urls->urlFor($envelope, DownloadUrlIssuer::EXECUTED, $expiresAt),
                'generated_at' => $artifact->published_at?->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }

        $revision = $this->reviewRevision($envelope);

        return [
            'status' => self::status($envelope),
            // True in the profile's own sense: these are the bytes the parties saw, not the
            // executed agreement, and a caller must not file them as one.
            'is_partial' => true,
            'download_url' => $this->urls->urlFor($envelope, DownloadUrlIssuer::REVIEW, $expiresAt),
            'generated_at' => $revision->created_at?->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * The profile's third status representation: a plain string enum.
     *
     * Distinct from the create response's `["draft"]`/`["sent"]` strings and from
     * `SigningRequestDetail`'s object of booleans. All three coexist upstream by design and
     * none may be collapsed into another (`docs/HANDOFF.md` section 10).
     */
    public static function status(Envelope $envelope): string
    {
        return match ($envelope->state) {
            EnvelopeState::Completed => 'finished',
            EnvelopeState::Cancelled => 'cancelled',
            EnvelopeState::Declined => 'declined',
            EnvelopeState::Expired => 'expired',
            default => 'in_progress',
        };
    }

    /**
     * A link to the reviewed revision: the bytes the parties are shown.
     *
     * Upstream's `document_url` on both the detail and the create responses. Issued in every
     * state, including a draft — the caller is the sender, and a sender reading back the
     * document they just uploaded is not a disclosure. What a *signer* may see is decided by
     * the signing session, not by this link.
     */
    public function documentUrl(Envelope $envelope, CarbonImmutable $expiresAt): string
    {
        return $this->urls->urlFor($envelope, DownloadUrlIssuer::REVIEW, $expiresAt);
    }

    /**
     * When the completion report was published, or null if there is none.
     *
     * Drives `certificate.generated` on the detail response. Published in the same
     * transaction that completes the request, so it is non-null exactly when the request is
     * finished and its evidence is retrievable.
     */
    public function reportGeneratedAt(Envelope $envelope): ?CarbonImmutable
    {
        if ($envelope->state !== EnvelopeState::Completed) {
            return null;
        }

        return $this->artifacts->ofKind($envelope, ArtifactKind::CompletionReport)?->published_at;
    }

    /**
     * The three nullable `*_download_url` members of `SigningRequestDetail`, with their
     * paired `*_download_error` members.
     *
     * All null until the agreement is executed, which is what the recorded fixtures show:
     * a sent-but-unfinished request has every one of them null, and a finished one has all
     * three. The `/download` route is the endpoint that serves a partial document, and
     * duplicating that here would give a consumer two different answers to "is this
     * finished" in one response.
     *
     * `*_download_error` is upstream's `file_not_accessible|null`. It stays null: an
     * artifact row exists only after its bytes were written and read back through the
     * storage adapter, so "recorded but inaccessible" is a fault to be reported loudly at
     * download time rather than a field on a polling response.
     *
     * @return array<string, string|null>
     */
    public function detailUrls(Envelope $envelope, CarbonImmutable $expiresAt): array
    {
        $executed = $envelope->state === EnvelopeState::Completed
            ? $this->artifacts->ofKind($envelope, ArtifactKind::ExecutedPdf)
            : null;

        if (! $executed instanceof Artifact) {
            return [
                'final_document_download_url' => null,
                'final_document_download_error' => null,
                'document_only_download_url' => null,
                'document_only_download_error' => null,
                'certificate_only_download_url' => null,
                'certificate_only_download_error' => null,
            ];
        }

        $report = $this->artifacts->ofKind($envelope, ArtifactKind::CompletionReport);

        return [
            'final_document_download_url' => $this->urls->urlFor($envelope, DownloadUrlIssuer::EXECUTED, $expiresAt),
            'final_document_download_error' => null,
            'document_only_download_url' => $this->urls->urlFor($envelope, DownloadUrlIssuer::REVIEW, $expiresAt),
            'document_only_download_error' => null,
            // Upstream calls it a certificate. This is the completion report: a statement of
            // what happened, deliberately not an X.509 credential belonging to any signer
            // (AGENTS.md, "Honest language"). The field name is upstream's and is kept so a
            // consumer can read it; the document it points at is described honestly
            // everywhere a human sees it.
            'certificate_only_download_url' => $report instanceof Artifact
                ? $this->urls->urlFor($envelope, DownloadUrlIssuer::REPORT, $expiresAt)
                : null,
            'certificate_only_download_error' => null,
        ];
    }

    /**
     * Stream one of the retained objects behind this signing request.
     *
     * @param  string  $kind  One of {@see DownloadUrlIssuer::KINDS}.
     *
     * @throws FirmaException
     */
    public function stream(Envelope $envelope, string $kind): StreamedResponse
    {
        $member = DownloadUrlIssuer::parseMember($kind);

        if ($member !== null) {
            return $this->streamMember($envelope, $member['position'], $member['type']);
        }

        return match ($kind) {
            DownloadUrlIssuer::EXECUTED => $this->downloader->stream($this->published($envelope, ArtifactKind::ExecutedPdf)),
            DownloadUrlIssuer::REPORT => $this->downloader->stream($this->published($envelope, ArtifactKind::CompletionReport)),
            default => $this->streamRevision($envelope, $this->reviewRevision($envelope)),
        };
    }

    /**
     * @throws FirmaException
     */
    private function executed(Envelope $envelope): Artifact
    {
        return $this->published($envelope, ArtifactKind::ExecutedPdf);
    }

    /**
     * @throws FirmaException
     */
    private function published(Envelope $envelope, ArtifactKind $kind): Artifact
    {
        if ($envelope->state !== EnvelopeState::Completed) {
            throw FirmaException::of(
                FirmaErrorCode::NoDocumentAvailable,
                'This signing request is '.self::status($envelope).', so no executed document exists for it.',
            );
        }

        $artifact = $this->artifacts->ofKind($envelope, $kind);

        if (! $artifact instanceof Artifact) {
            // The envelope completed and this build published nothing for it. Not a 409:
            // telling a caller their finished agreement is unfinished would be false.
            throw FirmaException::unsupported(
                'download.'.$kind->value,
                'This signing request has finished and no executed document was published for it in this '
                .'build, so there are no bytes to return.',
            );
        }

        return $artifact;
    }

    /**
     * @throws FirmaException
     */
    /**
     * `GET /signing-requests/{id}/documents/{document_id}/download` (issue #163): one document of
     * the set, by its public id, as a signed URL that expires with the others.
     *
     * @return array{download_url: string, type: string, expires_at: string}
     *
     * @throws FirmaException
     */
    public function describeMember(Envelope $envelope, string $documentId, string $type, ?CarbonImmutable $now = null): array
    {
        $entry = $this->memberById($envelope, $documentId);

        if ($type === 'document') {
            $this->memberArtifact($envelope, $entry->position);
        }

        $expiresAt = $this->urls->expiresAt($now);

        return [
            'download_url' => $this->urls->urlFor($envelope, DownloadUrlIssuer::member($entry->position, $type), $expiresAt),
            'type' => $type,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Upstream's `documents[]` (`DocumentSummary`), in position order (issue #163).
     *
     * A single document answers its one document with `document_id` null, as upstream's does for
     * a set that has never been changed. `document_url` names the member's own sealed copy once it
     * is published (`signed`), and its reviewed revision before that (`stored`).
     *
     * @return list<array<string, mixed>>
     */
    public function documentSummaries(Envelope $envelope, CarbonImmutable $expiresAt): array
    {
        $set = $envelope->documentSet();
        $summaries = [];

        foreach ($set->entries as $entry) {
            $sealed = $set->isMultiple() && $envelope->state === EnvelopeState::Completed
                ? $this->artifacts->ofDocument($envelope, $entry->position)
                : null;

            $url = match (true) {
                ! $set->isMultiple() => $this->documentUrl($envelope, $expiresAt),
                $sealed instanceof Artifact => $this->urls->urlFor($envelope, DownloadUrlIssuer::member($entry->position, 'document'), $expiresAt),
                default => $this->urls->urlFor($envelope, DownloadUrlIssuer::member($entry->position, 'original'), $expiresAt),
            };

            $summaries[] = [
                'document_id' => $entry->publicId,
                'position' => $entry->position,
                'label' => $entry->title === '' ? null : $entry->title,
                'original_filename' => null,
                'page_count' => $entry->pageCount,
                'document_url' => $url,
                'document_url_kind' => $sealed instanceof Artifact ? 'signed' : 'stored',
                'document_only_hash' => $sealed?->sha256,
            ];
        }

        return $summaries;
    }

    /** @throws FirmaException */
    private function memberById(Envelope $envelope, string $documentId): DocumentSetEntry
    {
        foreach ($envelope->documentSet()->entries as $entry) {
            if ($entry->publicId !== null && hash_equals($entry->publicId, $documentId)) {
                return $entry;
            }
        }

        throw FirmaException::notFound('document');
    }

    /** @throws FirmaException */
    private function streamMember(Envelope $envelope, int $position, string $type): StreamedResponse
    {
        $entry = $envelope->documentSet()->entryAt($position);

        if ($entry === null || $entry->publicId === null) {
            throw FirmaException::notFound('download');
        }

        if ($type === 'document') {
            return $this->downloader->stream($this->memberArtifact($envelope, $position));
        }

        $revision = DocumentRevision::query()->with('document')->find($entry->revisionId);

        if (! $revision instanceof DocumentRevision) {
            throw FirmaException::of(FirmaErrorCode::NoDocumentAvailable, 'That document of the set is no longer available.');
        }

        return $this->streamRevision($envelope, $revision);
    }

    /**
     * A member's own sealed copy. Upstream answers `404 DOCUMENT_SIGNED_NOT_AVAILABLE` before
     * completion; this surface's vocabulary for the same fact is `no_document_available`.
     *
     * @throws FirmaException
     */
    private function memberArtifact(Envelope $envelope, int $position): Artifact
    {
        $artifact = $envelope->state === EnvelopeState::Completed ? $this->artifacts->ofDocument($envelope, $position) : null;

        if (! $artifact instanceof Artifact) {
            throw FirmaException::of(
                FirmaErrorCode::NoDocumentAvailable,
                'Document '.$position.' of this signing request is sealed on its own only once everyone has signed and '
                .'the executed agreement is published.',
            );
        }

        return $artifact;
    }

    private function reviewRevision(Envelope $envelope): DocumentRevision
    {
        // A document set's review copy is not one file: each member is downloaded on its own
        // (`/documents/{document_id}/download?type=original`). Returning the first member here
        // would hand a caller one document of several as if it were the agreement.
        if ($envelope->documentSet()->isMultiple()) {
            throw FirmaException::of(
                FirmaErrorCode::NoDocumentAvailable,
                'This signing request has several documents. Download each with '
                .'GET /signing-requests/{id}/documents/{document_id}/download.',
            );
        }

        $revision = $envelope->relationLoaded('documentRevision')
            ? $envelope->documentRevision
            : $envelope->documentRevision()->with('document')->first();

        if (! $revision instanceof DocumentRevision) {
            throw FirmaException::of(
                FirmaErrorCode::NoDocumentAvailable,
                'The document this signing request was built from is no longer available.',
            );
        }

        return $revision;
    }

    private function streamRevision(Envelope $envelope, DocumentRevision $revision): StreamedResponse
    {
        // The envelope's title rather than the document's: this is the agreement as the
        // parties were asked to sign it, and nothing in the name comes from an uploader.
        $filename = $revision->downloadFilename((string) $envelope->title);
        $stream = $this->blobs->disk($revision->disk)->readStream($revision->path);

        if (! is_resource($stream)) {
            // The row says the bytes are there and the disk says otherwise. Fail loudly: a
            // truncated 200 looks to a client like a valid short PDF.
            throw FirmaException::of(
                FirmaErrorCode::InternalError,
                'The document behind this signing request is recorded but not readable.',
            );
        }

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
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $filename,
                    $filename,
                ),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
                // The digest recorded when the revision was written and read back, so a
                // caller can verify the transfer without a second request.
                'X-Document-Sha256' => $revision->sha256,
            ],
        );
    }
}
