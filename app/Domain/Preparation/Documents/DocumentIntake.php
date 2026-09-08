<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Documents\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes an uploaded PDF and turns it into a document with an original and a review revision.
 *
 * Order of operations, and why it is this order:
 *
 *  1. **Stream the upload to a temp path and hash it there.** The digest is computed from a
 *     file this process controls, not from the request body and not from a value the client
 *     supplied. Everything downstream compares against it.
 *  2. **Preflight the bytes.** A real parse, with the limits from `config('esign.documents')`.
 *     Failure is terminal for the document (see below).
 *  3. **Produce the review revision.** Normally the same bytes with the same digest; a
 *     disclosed rebuild if the deployment has one configured. See ReviewNormalizer.
 *  4. **Write both objects and read them back.** DocumentBlobStore re-hashes what the disk
 *     returns. Nothing reaches the database until the bytes are provably retrievable.
 *  5. **Record everything in one transaction.** The document row, both revision rows, and
 *     the audit event commit together or not at all. There is no window in which a document
 *     exists without the revision that proves what was uploaded.
 *
 * Storage before database, rather than the other way round, is the staged pattern from
 * docs/ARCHITECTURE.md: a failed or partial write leaves unreferenced objects, which the
 * pruner in docs/BLOB_STORAGE.md reclaims, and leaves no database row at all. The inverse
 * order would leave rows pointing at bytes that never arrived, which nothing can repair.
 *
 * **A document that fails preflight is kept.** Its original bytes are stored, its row is
 * created with status `preflight_failed`, and the report is recorded. Discarding it would
 * destroy the only evidence of what was uploaded, which is exactly what an operator needs
 * when a sender reports "the system rejected our contract" or when a malicious upload has
 * to be examined. The cost is that rejected files consume storage and are subject to
 * retention like anything else; the mitigation is that the Form Request refuses an
 * oversized body before intake ever runs. A rejected document has no review revision and
 * can never gain one: nothing may be shown for assent that preflight did not accept.
 */
final readonly class DocumentIntake
{
    /** Read the upload in 512 KB chunks so a large file is never held in memory twice. */
    private const CHUNK_BYTES = 524_288;

    public function __construct(
        private PdfPreflight $preflight,
        private ReviewNormalizer $normalizer,
        private DocumentBlobStore $blobs,
        private AuditRecorder $audit,
        private string $disk,
    ) {}

    /**
     * @param  string|null  $title  Falls back to the uploaded filename, sanitized. The
     *                              filename never reaches a storage key or a header.
     *
     * @throws DocumentStorageException When the bytes cannot be stored and read back.
     */
    public function intake(Workspace $workspace, User $uploader, UploadedFile $file, ?string $title = null): Document
    {
        $staged = $this->stage($file);

        try {
            return $this->record(
                $workspace,
                $uploader,
                AuditActor::user($uploader),
                $file->getMimeType() ?? 'application/octet-stream',
                $staged,
                $this->title($title, $file),
            );
        } finally {
            @unlink($staged->path);
        }
    }

    /**
     * The same intake, for bytes that never arrived as a multipart upload.
     *
     * An API caller sends a document base64-encoded in a JSON body, and the caller is a
     * service credential rather than a person — so there is no `UploadedFile` to stage and no
     * `User` to attribute the upload to. Everything that matters is unchanged: the same
     * preflight, the same review normalization, the same write-then-read-back, the same one
     * transaction, and the same rule that a rejected document is kept with its report.
     *
     * `uploaded_by` and `created_by` are null, which they are already nullable for, and the
     * audit trail names the actor instead. A fabricated user id would be worse than an honest
     * absence: it would make an integration's upload indistinguishable from a person's.
     *
     * The MIME type is fixed by the caller rather than sniffed, because there is no uploaded
     * file to sniff — and it is not taken from the request either. Whether the bytes really
     * are a PDF is decided by the preflight parser, which is the only judge that matters.
     *
     * @throws DocumentStorageException When the bytes cannot be stored and read back.
     */
    public function intakeBytes(
        Workspace $workspace,
        string $bytes,
        string $title,
        AuditActor $actor,
        string $mimeType = 'application/pdf',
    ): Document {
        $staged = $this->stageBytes($bytes);

        try {
            return $this->record(
                $workspace,
                null,
                $actor,
                $mimeType,
                $staged,
                $this->titleOrDefault($title),
            );
        } finally {
            @unlink($staged->path);
        }
    }

    private function record(
        Workspace $workspace,
        ?User $uploader,
        AuditActor $actor,
        string $mimeType,
        StagedUpload $staged,
        string $title,
    ): Document {
        $bytes = (string) file_get_contents($staged->path);
        $report = $this->preflight->inspect($bytes);

        // Generated before the storage write because the key contains it, and the key has to
        // be known before anything is written. The row that uses it is created below.
        $publicId = (string) Str::ulid();

        $originalKey = DocumentStorageKey::for($workspace->public_id, $publicId, RevisionKind::Original, $staged->sha256);
        $this->blobs->putVerified($this->disk, $originalKey, $bytes, $staged->sha256);

        $review = $report->isAccepted()
            ? $this->normalizer->normalize($bytes, $staged->sha256, $report)
            : null;

        $reviewKey = null;
        if ($review !== null) {
            $reviewKey = DocumentStorageKey::for($workspace->public_id, $publicId, RevisionKind::Review, $review->sha256);
            $this->blobs->putVerified($this->disk, $reviewKey, $review->bytes, $review->sha256);
        }

        return DB::transaction(function () use (
            $workspace, $uploader, $actor, $mimeType, $staged, $title, $report, $publicId, $originalKey, $review, $reviewKey,
        ): Document {
            $document = new Document([
                'workspace_id' => $workspace->getKey(),
                'title' => $title,
                'uploaded_by' => $uploader?->getKey(),
                'original_disk' => $this->disk,
                'original_path' => $originalKey->value,
                'original_sha256' => $staged->sha256,
                'original_bytes' => $staged->bytes,
                // The sniffed type for an upload and a fixed one for an API body, never the
                // Content-Type the client claimed either way.
                'original_mime' => $mimeType,
                'page_count' => $report->isAccepted() ? $report->metrics->pageCount : null,
                'preflight_report' => $report->toArray(),
                'status' => DocumentStatus::Uploaded,
            ]);
            $document->public_id = $publicId;
            $document->save();

            $document->revisions()->create([
                'kind' => RevisionKind::Original,
                'disk' => $this->disk,
                'path' => $originalKey->value,
                'sha256' => $staged->sha256,
                'bytes' => $staged->bytes,
                'page_count' => $report->isAccepted() ? $report->metrics->pageCount : null,
                'normalization' => [
                    'applied' => false,
                    'steps' => [],
                    'summary' => 'None. This is the upload exactly as received.',
                    'disclosures' => [],
                ],
                'created_by' => $uploader?->getKey(),
            ]);

            if ($review !== null && $reviewKey !== null) {
                $document->revisions()->create([
                    'kind' => RevisionKind::Review,
                    'disk' => $this->disk,
                    'path' => $reviewKey->value,
                    'sha256' => $review->sha256,
                    'bytes' => strlen($review->bytes),
                    'page_count' => $review->pageCount,
                    'normalization' => $review->record,
                    'created_by' => $uploader?->getKey(),
                ]);
            }

            $document->status = $review === null ? DocumentStatus::PreflightFailed : DocumentStatus::Ready;
            $document->save();

            $this->audit->record(
                $actor,
                $review === null ? 'preparation.document_rejected' : 'preparation.document_uploaded',
                $document,
                [
                    'workspace_id' => $workspace->public_id,
                    'original_sha256' => $staged->sha256,
                    'original_bytes' => $staged->bytes,
                    'review_sha256' => $review?->sha256,
                    'normalization_applied' => $review?->wasApplied() ?? false,
                    'rejection_codes' => $report->rejectionCodes(),
                ],
            );

            return $document->fresh(['revisions']) ?? $document;
        });
    }

    /**
     * Stage bytes already in memory, hashing them once on the way to the temp path.
     *
     * The same {@see StagedUpload} the multipart path produces, so everything downstream is
     * identical. Bytes that arrived in a JSON body are already held in memory by the
     * framework; writing them out is what lets the storage write stream rather than hold a
     * second copy.
     */
    private function stageBytes(string $bytes): StagedUpload
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'esign-intake-');

        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);

            throw new DocumentStorageException('A temporary file for the document could not be created.');
        }

        return new StagedUpload($path, hash('sha256', $bytes), strlen($bytes));
    }

    /**
     * Copy the upload to a temp path this process owns, hashing as it goes.
     *
     * The framework's own temporary file is moved or deleted when the request ends, and its
     * lifetime is not something intake should depend on while it is talking to storage.
     * Hashing during the copy means the bytes are read once, not twice.
     */
    private function stage(UploadedFile $file): StagedUpload
    {
        $source = fopen($file->getRealPath(), 'rb');

        if ($source === false) {
            throw new DocumentStorageException('The uploaded file could not be opened for reading.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'esign-intake-');
        $target = fopen($path, 'wb');

        if ($target === false) {
            fclose($source);
            throw new DocumentStorageException('A temporary file for the upload could not be created.');
        }

        try {
            $context = hash_init('sha256');
            $bytes = 0;

            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new DocumentStorageException('The uploaded file could not be read to the end.');
                }

                hash_update($context, $chunk);
                $bytes += strlen($chunk);

                if ($chunk !== '' && fwrite($target, $chunk) === false) {
                    throw new DocumentStorageException('The uploaded file could not be staged to a temporary path.');
                }
            }

            return new StagedUpload($path, hash_final($context), $bytes);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /**
     * A title for the document.
     *
     * The uploaded filename is a client-supplied string: it is stripped of its extension and
     * of anything that is not printable, and it is used for display only. It never becomes a
     * storage key (DocumentStorageKey builds those from ULIDs and digests) and it never
     * becomes a Content-Disposition filename (DocumentRevision::downloadFilename() slugs the
     * title for that).
     */
    private function title(?string $title, UploadedFile $file): string
    {
        return $this->titleOrDefault($title ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
    }

    /** Strip control characters and bound the length. Display only, never a key or a header. */
    private function titleOrDefault(?string $title): string
    {
        $candidate = trim(preg_replace('/[\p{C}]+/u', '', trim((string) $title)) ?? '');

        return Str::limit($candidate === '' ? 'Untitled document' : $candidate, 200, '');
    }
}
