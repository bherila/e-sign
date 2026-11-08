<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma\Uploads;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaException;
use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Integration\Firma\InlineDocument;
use App\Domain\Integration\Firma\PreflightRefusal;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The facade's two ways to upload a document ahead of using it (issue #182).
 *
 * | Route | For | Steps |
 * |---|---|---|
 * | `POST /documents/inline` — **upload a small document** | a PDF up to 4.5 MB | one: base64 in the JSON body |
 * | `POST /documents` — **upload a large document** | a PDF up to 50 MB | two: ask for an upload URL, then PUT the raw bytes to it |
 *
 * Both answer a `document_id` for `document_id`, `document_ids` and a draft's `/documents`
 * routes. The large route is upstream's own `POST /documents`. Upstream answers it with a
 * pre-signed storage URL; this service never pre-signs (docs/BLOB_STORAGE.md rule 1), so the URL
 * is an application route, signed and expiring like every download link here, and the bytes
 * stream through the application into the same {@see DocumentIntake} — staged, hashed, really
 * parsed by the preflight parser, written and read back — as an inline `document`.
 *
 * An upload is used once: building a request from it, adding it to a draft or replacing a
 * draft's document with it consumes it, which is upstream's "consumed once the document is added".
 * A used, expired, foreign or never-received upload id is `document_upload_not_found`, with no
 * hint of which, because the caller should do the same thing about every one of them.
 */
final readonly class FirmaDocumentUploads
{
    /** Route the large upload's bytes are PUT to. A contract with routes/compat-firma.php. */
    public const CONTENT_ROUTE = 'compat.firma.documents.upload-large.content';

    /** What this build accepts. Upstream also takes DOCX and converts it; this build does not. */
    public const CONTENT_TYPE = 'application/pdf';

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private DocumentIntake $intake) {}

    /**
     * `POST /documents` — step one of a large upload.
     *
     * @return array{upload: FirmaDocumentUpload, upload_url: string, upload_token: string, expires_in: int}
     *
     * @throws FirmaException
     */
    public function issue(Workspace $workspace, ServiceCredential $credential, string $fileName, string $contentType, int $fileSize): array
    {
        self::guardContentType($contentType);

        if ($fileSize < 1 || $fileSize > FirmaProfile::MAX_UPLOAD_BYTES) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                '`file_size` must be between 1 and '.FirmaProfile::MAX_UPLOAD_BYTES.' bytes (50 MB).',
                ['max_bytes' => FirmaProfile::MAX_UPLOAD_BYTES],
            );
        }

        $now = CarbonImmutable::now();
        $upload = FirmaDocumentUpload::query()->create([
            'workspace_id' => $workspace->getKey(),
            'service_credential_id' => $credential->getKey(),
            'file_name' => $fileName,
            'content_type' => $contentType,
            'declared_bytes' => $fileSize,
            'expires_at' => $now->addHours(FirmaProfile::UPLOAD_USABLE_HOURS),
        ]);

        $urlExpiresAt = $now->addMinutes(FirmaProfile::UPLOAD_URL_TTL_MINUTES);
        $url = URL::temporarySignedRoute(self::CONTENT_ROUTE, $urlExpiresAt, ['upload' => $upload->public_id]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [
            'upload' => $upload,
            'upload_url' => $url,
            // Upstream: "Upload authentication token (included in the upload_url)". Here that is
            // the URL's signature, which is what authorizes the PUT; it is not an API key.
            'upload_token' => is_string($query['signature'] ?? null) ? $query['signature'] : '',
            'expires_in' => FirmaProfile::UPLOAD_URL_TTL_MINUTES * 60,
        ];
    }

    /**
     * `POST /documents/inline` — a small upload, in one call.
     *
     * @throws FirmaException
     */
    public function inline(Workspace $workspace, ServiceCredential $credential, string $fileName, string $base64): FirmaDocumentUpload
    {
        $bytes = InlineDocument::decode($base64);
        $now = CarbonImmutable::now();

        $upload = FirmaDocumentUpload::query()->create([
            'workspace_id' => $workspace->getKey(),
            'service_credential_id' => $credential->getKey(),
            'file_name' => $fileName,
            'content_type' => self::CONTENT_TYPE,
            'declared_bytes' => strlen($bytes),
            'expires_at' => $now->addHours(FirmaProfile::UPLOAD_USABLE_HOURS),
        ]);

        return $this->ingest($upload, $workspace, $bytes, 'integration.firma credential '.$credential->prefix);
    }

    /**
     * Step two of a large upload: the bytes, PUT to the signed URL.
     *
     * The signature has already been checked by the route. What is checked here is what the
     * signature cannot know: that the upload is still waiting, unexpired, and that the bytes are
     * what was declared.
     *
     * @param  resource  $body
     *
     * @throws FirmaException
     */
    public function receive(string $uploadId, $body, ?string $contentType): FirmaDocumentUpload
    {
        $upload = FirmaDocumentUpload::query()->where('public_id', $uploadId)->with('document')->first();

        if (! $upload instanceof FirmaDocumentUpload || $upload->isExpired()) {
            throw self::uploadNotFound($uploadId);
        }

        if ($upload->uploaded_at !== null) {
            // Retained byte-for-byte (AGENTS.md): an upload is written once, never overwritten.
            throw FirmaException::of(
                FirmaErrorCode::InvalidState,
                'This upload has already received its file. Request a new upload URL to upload another.',
                ['document_id' => $upload->public_id],
            );
        }

        $type = strtolower(trim(explode(';', (string) $contentType)[0]));

        if ($type !== '' && $type !== $upload->content_type) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The upload was requested as `'.$upload->content_type.'` and the PUT says `'.$type.'`. Send the file '
                .'with the Content-Type the upload was requested with.',
            );
        }

        $limit = (int) ($upload->declared_bytes ?? FirmaProfile::MAX_UPLOAD_BYTES);
        $bytes = self::readAtMost($body, $limit);

        if ($bytes === null) {
            throw FirmaException::of(
                FirmaErrorCode::DocumentSetTooLarge,
                'The file is larger than the '.$limit.' bytes declared as `file_size` when the upload was requested.',
                ['declared_bytes' => $limit],
            );
        }

        if ($bytes === '') {
            throw FirmaException::of(FirmaErrorCode::InvalidRequest, 'The PUT body is empty; send the file\'s raw bytes.');
        }

        // Claimed before the bytes are ingested, so two PUTs racing on one URL cannot both store a
        // document under it. A failure before intake records anything releases the claim, so a
        // client whose PUT hit a storage fault can retry the same URL.
        $claimed = FirmaDocumentUpload::query()
            ->whereKey($upload->getKey())
            ->whereNull('uploaded_at')
            ->update(['uploaded_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($claimed !== 1) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidState,
                'This upload has already received its file. Request a new upload URL to upload another.',
                ['document_id' => $upload->public_id],
            );
        }

        $workspace = Workspace::query()->findOrFail($upload->workspace_id);

        try {
            return $this->ingest($upload, $workspace, $bytes, 'integration.firma upload '.$upload->public_id);
        } catch (FirmaException $refused) {
            throw $refused;
        } catch (Throwable $failure) {
            FirmaDocumentUpload::query()->whereKey($upload->getKey())->whereNull('document_id')->update(['uploaded_at' => null]);

            throw $failure;
        }
    }

    /**
     * The documents a request names by upload id, in the order named, each one usable.
     *
     * @param  list<string>  $uploadIds
     * @return list<array{upload: FirmaDocumentUpload, document: Document, revision: DocumentRevision}>
     *
     * @throws FirmaException
     */
    public function resolve(Workspace $workspace, array $uploadIds): array
    {
        if (count($uploadIds) > DocumentSet::MAX_DOCUMENTS) {
            throw FirmaException::of(
                FirmaErrorCode::MaxDocumentsExceeded,
                'A signing request holds at most '.DocumentSet::MAX_DOCUMENTS.' documents; '.count($uploadIds).' were named.',
                ['max_documents' => DocumentSet::MAX_DOCUMENTS],
            );
        }

        $seen = [];

        foreach ($uploadIds as $id) {
            $key = strtolower($id);

            if (isset($seen[$key])) {
                throw FirmaException::of(
                    FirmaErrorCode::DuplicateDocumentId,
                    'Upload "'.$id.'" is named more than once. A document set has each document once.',
                    ['document_id' => $id],
                );
            }

            $seen[$key] = true;
        }

        $uploads = FirmaDocumentUpload::query()
            ->inWorkspace($workspace)
            ->whereIn('public_id', $uploadIds)
            ->with('document')
            ->get()
            ->keyBy('public_id');

        $resolved = [];
        $bytes = 0;

        foreach ($uploadIds as $index => $id) {
            $upload = $uploads->get($id);

            if (! $upload instanceof FirmaDocumentUpload
                || $upload->isExpired()
                || $upload->consumed_at !== null
                || ! $upload->document instanceof Document) {
                throw self::uploadNotFound($id);
            }

            $revision = $upload->document->reviewRevision();

            if (! $revision instanceof DocumentRevision || (int) $revision->page_count < 1) {
                throw PreflightRefusal::of($upload->document, $index + 1, $id);
            }

            $bytes += (int) $revision->bytes;
            $resolved[] = ['upload' => $upload, 'document' => $upload->document, 'revision' => $revision];
        }

        if ($bytes > DocumentSet::MAX_TOTAL_BYTES) {
            throw FirmaException::of(
                FirmaErrorCode::DocumentSetTooLarge,
                'The documents total '.$bytes.' bytes, over the '.DocumentSet::MAX_TOTAL_BYTES.'-byte limit for a set.',
                ['bytes' => $bytes, 'max_bytes' => DocumentSet::MAX_TOTAL_BYTES],
            );
        }

        return $resolved;
    }

    /**
     * Use the uploads, once. Call inside the transaction that uses them, so a request that rolls
     * back leaves them usable, and two requests racing for one upload cannot both have it.
     *
     * @param  list<FirmaDocumentUpload>  $uploads
     *
     * @throws FirmaException
     */
    public function consume(array $uploads): void
    {
        $now = CarbonImmutable::now();

        foreach ($uploads as $upload) {
            $claimed = FirmaDocumentUpload::query()
                ->whereKey($upload->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            if ($claimed !== 1) {
                throw self::uploadNotFound($upload->public_id);
            }
        }
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @throws FirmaException
     */
    private function ingest(FirmaDocumentUpload $upload, Workspace $workspace, string $bytes, string $actor): FirmaDocumentUpload
    {
        $document = $this->intake->intakeBytes($workspace, $bytes, $upload->file_name, AuditActor::system($actor));

        // Recorded whether or not preflight accepted it: intake keeps a refused upload with its
        // report, and the upload row is how that report is found again.
        $upload->forceFill(['document_id' => $document->getKey(), 'uploaded_at' => CarbonImmutable::now()])->save();
        $upload->setRelation('document', $document);

        if (! $document->reviewRevision() instanceof DocumentRevision) {
            throw PreflightRefusal::of($document, 1, $upload->public_id);
        }

        return $upload;
    }

    /**
     * Read the body up to `$limit` bytes, in chunks, never more than one chunk past it.
     *
     * @param  resource  $body
     * @return string|null Null when the body is longer than `$limit`.
     */
    private static function readAtMost($body, int $limit): ?string
    {
        $bytes = '';

        while (! feof($body)) {
            $chunk = fread($body, 1_048_576);

            if ($chunk === false) {
                break;
            }

            $bytes .= $chunk;

            if (strlen($bytes) > $limit) {
                return null;
            }
        }

        return $bytes;
    }

    /**
     * @throws FirmaException
     */
    private static function guardContentType(string $contentType): void
    {
        if ($contentType === self::DOCX) {
            throw FirmaException::unsupported(
                'content_type',
                'This build does not convert Word documents. Upload the agreement as a PDF: it is the file every '
                .'party reviews and the one that is sealed, so it is made once, by the sender, rather than here.',
            );
        }

        if ($contentType !== self::CONTENT_TYPE) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                '`content_type` must be `'.self::CONTENT_TYPE.'`.',
            );
        }
    }

    private static function uploadNotFound(string $uploadId): FirmaException
    {
        return FirmaException::of(
            FirmaErrorCode::DocumentUploadNotFound,
            'No usable upload "'.$uploadId.'" exists in this workspace. An upload is used once and expires '
            .FirmaProfile::UPLOAD_USABLE_HOURS.' hours after it was requested; upload the file again.',
            ['document_id' => $uploadId],
        );
    }
}
