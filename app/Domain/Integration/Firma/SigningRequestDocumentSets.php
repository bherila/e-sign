<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Integration\Firma\Uploads\FirmaDocumentUpload;
use App\Domain\Integration\Firma\Uploads\FirmaDocumentUploads;
use App\Domain\Integration\Native\EnvelopeService;
use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Signing\Envelopes\DocumentSetChange;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;

/**
 * A draft's `/documents` routes (issue #182): add, reorder, replace or rename, remove.
 *
 * Translation only. Each one is a {@see DocumentSetChange} applied by the state machine through
 * App\Domain\Integration\Native\EnvelopeService, the same path any other surface would take
 * (AGENTS.md, "One state machine"); the rules — draft only, the set's limits, fields carried
 * with their pages, a field never stranded on a removed page — live there, not here.
 *
 * Every route answers upstream's `DocumentSetMutationResponse`: the whole set after the change
 * and its two counters.
 */
final readonly class SigningRequestDocumentSets
{
    public function __construct(
        private EnvelopeService $envelopes,
        private FirmaDocumentUploads $uploads,
        private SigningRequestDownloads $downloads,
    ) {}

    /**
     * `POST /signing-requests/{id}/documents`.
     *
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    public function add(
        Workspace $workspace,
        Envelope $envelope,
        ServiceCredential $credential,
        string $uploadId,
        ?int $position,
        ?string $label,
        ?string $originalFilename,
    ): array {
        self::guardDraft($envelope);

        [$member] = $this->uploads->resolve($workspace, [$uploadId]);
        $upload = $member['upload'];

        // With no `label`, a document is named as it was uploaded, as one in `document_ids` is.
        // Upstream leaves it null; a caller clears it with `label: null` on PATCH.

        $updated = $this->envelopes->changeDocumentSet(
            $envelope,
            static fn (DocumentSet $set): DocumentSetChange => DocumentSetChange::add($set, $member['revision'], $label ?? $member['document']->title, $position),
            $credential,
            function () use ($upload, $originalFilename): void {
                $this->uploads->consume([$upload]);

                // Upstream: "File name to record for the document". The upload is where this
                // facade records one, so a name given here replaces the one given at upload.
                if ($originalFilename !== null) {
                    FirmaDocumentUpload::query()->whereKey($upload->getKey())->update(['file_name' => $originalFilename]);
                }
            },
        );

        return $this->response($updated);
    }

    /**
     * `PUT /signing-requests/{id}/documents/order`.
     *
     * @param  list<string>  $documentIds
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    public function reorder(Envelope $envelope, ServiceCredential $credential, array $documentIds): array
    {
        self::guardDraft($envelope);

        return $this->response($this->envelopes->changeDocumentSet(
            $envelope,
            static fn (DocumentSet $set): DocumentSetChange => DocumentSetChange::reorder($set, $documentIds),
            $credential,
        ));
    }

    /**
     * `PATCH /signing-requests/{id}/documents/{document_id}` with `document_id`: replace.
     *
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    public function replace(Workspace $workspace, Envelope $envelope, ServiceCredential $credential, string $documentId, string $uploadId): array
    {
        self::guardDraft($envelope);

        [$member] = $this->uploads->resolve($workspace, [$uploadId]);

        return $this->response($this->envelopes->changeDocumentSet(
            $envelope,
            static fn (DocumentSet $set): DocumentSetChange => DocumentSetChange::replace($set, $documentId, $member['revision']),
            $credential,
            fn () => $this->uploads->consume([$member['upload']]),
        ));
    }

    /**
     * `PATCH /signing-requests/{id}/documents/{document_id}` with `label`: rename, or clear.
     *
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    public function rename(Envelope $envelope, ServiceCredential $credential, string $documentId, ?string $label): array
    {
        self::guardDraft($envelope);

        return $this->response($this->envelopes->changeDocumentSet(
            $envelope,
            static fn (DocumentSet $set): DocumentSetChange => DocumentSetChange::rename($set, $documentId, $label),
            $credential,
        ));
    }

    /**
     * `DELETE /signing-requests/{id}/documents/{document_id}`.
     *
     * @return array<string, mixed>
     *
     * @throws FirmaException
     */
    public function remove(Envelope $envelope, ServiceCredential $credential, string $documentId): array
    {
        self::guardDraft($envelope);

        return $this->response($this->envelopes->changeDocumentSet(
            $envelope,
            static fn (DocumentSet $set): DocumentSetChange => DocumentSetChange::remove($set, $documentId),
            $credential,
        ));
    }

    /**
     * Upstream's `DocumentSetMutationResponse`.
     *
     * @return array{documents: list<array<string, mixed>>, documents_version: int, documents_layout_version: int}
     */
    public function response(Envelope $envelope): array
    {
        return [
            'documents' => $this->downloads->documentSummaries($envelope, CarbonImmutable::now()->addMinutes(FirmaProfile::DOWNLOAD_URL_TTL_MINUTES)),
            'documents_version' => $envelope->documents_version,
            'documents_layout_version' => $envelope->documents_layout_version,
        ];
    }

    /**
     * Checked before an upload is resolved, so a sent request answers that its set is locked
     * rather than something about the upload. The state machine checks again under the lock.
     *
     * @throws FirmaException
     */
    private static function guardDraft(Envelope $envelope): void
    {
        if ($envelope->state !== EnvelopeState::Draft) {
            throw FirmaException::of(
                FirmaErrorCode::DocumentSetLocked,
                'This signing request is no longer a draft, so its documents cannot change. A correction is a new '
                .'signing request.',
                ['from' => $envelope->state->value],
            );
        }
    }
}
