<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use Symfony\Component\HttpFoundation\Response;

/**
 * The values the facade puts in the upstream error envelope's `error` member.
 *
 * Upstream's `Error` schema is `{error (required), message, details}` and describes `error`
 * only as "human-readable", while every recorded example puts a short machine token there
 * (`no_document_available`, `generation_timeout`, `stale_at_publication`) and the sentence in
 * `message`. The examples win: a client can switch on a token and cannot switch on prose.
 *
 * These are deliberately **not** App\Domain\Integration\Native\ErrorCode. The native codes
 * are this application's vocabulary and are stable for its own clients; these are somebody
 * else's, and the two must be free to diverge. `FirmaErrorMap` is the one translation.
 */
enum FirmaErrorCode: string
{
    /* -------------------------------------------------------------- 400 / 401 / 403 */

    /** Upstream's generic 400 for a body it cannot use. */
    case InvalidRequest = 'invalid_request';

    /**
     * A document with a page that has no valid content of its own (issue #167).
     *
     * Upstream answers this `400` with code `MALFORMED_PDF` and `malformed_pages` (reference
     * v1.40.1); the token follows this enum's lowercase vocabulary, and the page numbers
     * travel in `details`, as every structured member here does.
     */
    case MalformedPdf = 'malformed_pdf';

    case Unauthorized = 'unauthorized';

    case Forbidden = 'forbidden';

    /*
     * The multi-document contract (reference v1.39.0; issues #163, #182). Upstream's codes, in
     * this enum's lowercase vocabulary, as every other member is. Each is the answer a caller
     * branches on, so none of them is folded into `invalid_request`.
     */

    /** More than one of `document`, `document_id`, `document_ids` and `template_id`. */
    case ConflictingDocumentSource = 'conflicting_document_source';

    /** The same upload id twice in one `document_ids`. */
    case DuplicateDocumentId = 'duplicate_document_id';

    /** More than 20 documents in one set. */
    case MaxDocumentsExceeded = 'max_documents_exceeded';

    /** An upload id this workspace never received, one that expired, or one already used. */
    case DocumentUploadNotFound = 'document_upload_not_found';

    /** A reorder that does not name every document exactly once. */
    case InvalidDocumentOrder = 'invalid_document_order';

    /** Removing the last document. */
    case MinDocumentsRequired = 'min_documents_required';

    /** A replacement with a different number of pages. */
    case PageCountMismatch = 'page_count_mismatch';

    /** A replacement with a page of a different size. */
    case DimensionMismatch = 'dimension_mismatch';

    /* ------------------------------------------------------------------------ 404 */

    /**
     * No signing request, template, recipient, or field with that id **in this workspace**.
     *
     * Indistinguishable from one that does not exist anywhere, for the reason
     * docs/HANDOFF.md section 10 gives: the lookup is constrained by the credential's
     * workspace before the identifier is used, so nothing here ever learned which it was.
     * The recorded fixtures confirm upstream answers 404 for an unknown id on every endpoint
     * (`tests/Fixtures/firma/firma-compat-v1/README.md`).
     */
    case NotFound = 'not_found';

    /** No document with that id in this signing request. */
    case DocumentNotFound = 'document_not_found';

    /* ------------------------------------------------------------------------ 409 */

    /** Upstream's `/download` 409 when nothing has been generated: verbatim token. */
    case NoDocumentAvailable = 'no_document_available';

    /** Cancel on a request whose state does not allow it, or a move of that kind. */
    case InvalidState = 'invalid_state';

    /**
     * A send attempted on a request that has already been sent.
     *
     * The pinned document's examples put a `400` here; upstream's current reference corrects
     * that to `409` with a dedicated code (`ALREADY_SENT`, in its structured error format),
     * which is the answer a retrying client branches on. The token follows this enum's
     * lowercase vocabulary, as every other member does. See the
     * `POST /signing-requests/{id}/send` table in the capability matrix.
     */
    case AlreadySent = 'already_sent';

    /** A change to the document set of a request that is no longer a draft. */
    case DocumentSetLocked = 'document_set_locked';

    /** A `documents_layout_version` that is not the request's current one. */
    case DocumentSetVersionConflict = 'document_set_version_conflict';

    /** A field write on a multi-document request without `documents_layout_version`. */
    case DocumentsLayoutVersionRequired = 'documents_layout_version_required';

    /* ------------------------------------------------------------------------ 413 */

    /**
     * A request body over the documented 6 MiB ceiling.
     *
     * Upstream documents the code as `REQUEST_TOO_LARGE` in its structured error format;
     * the token here follows this enum's lowercase vocabulary, as every other member does.
     */
    case RequestTooLarge = 'request_too_large';

    /** A document set over 100 MB, or an upload over its own limit. */
    case DocumentSetTooLarge = 'document_set_too_large';

    /* ------------------------------------------------------------------ 422 */

    /** A body that validated structurally and asks for something inconsistent. */
    case UnprocessableEntity = 'unprocessable_entity';

    /** `create-and-send` refused before or after creation; carries `phase`. */
    case ValidationFailed = 'validation_failed';

    /* ------------------------------------------------------------------------ 501 */

    /**
     * A route or an option this profile does not implement.
     *
     * Always with a body naming the option, never a success that quietly did something else
     * (AGENTS.md, "Fail closed"; docs/HANDOFF.md section 10: "Never advertise a successful
     * no-op for an unsupported route or required option").
     */
    case Unsupported = 'unsupported';

    /* ------------------------------------------------------------------------ 500 */

    case InternalError = 'internal_error';

    public function status(): int
    {
        return match ($this) {
            self::InvalidRequest, self::MalformedPdf,
            self::ConflictingDocumentSource, self::DuplicateDocumentId, self::MaxDocumentsExceeded,
            self::DocumentUploadNotFound, self::InvalidDocumentOrder, self::MinDocumentsRequired,
            self::PageCountMismatch, self::DimensionMismatch => Response::HTTP_BAD_REQUEST,
            self::Unauthorized => Response::HTTP_UNAUTHORIZED,
            self::Forbidden => Response::HTTP_FORBIDDEN,
            self::NotFound, self::DocumentNotFound => Response::HTTP_NOT_FOUND,
            self::NoDocumentAvailable, self::InvalidState, self::AlreadySent,
            self::DocumentSetLocked, self::DocumentSetVersionConflict,
            self::DocumentsLayoutVersionRequired => Response::HTTP_CONFLICT,
            self::RequestTooLarge, self::DocumentSetTooLarge => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            self::UnprocessableEntity, self::ValidationFailed => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::Unsupported => Response::HTTP_NOT_IMPLEMENTED,
            self::InternalError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
