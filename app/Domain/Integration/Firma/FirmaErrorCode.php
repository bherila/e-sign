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

    case Unauthorized = 'unauthorized';

    case Forbidden = 'forbidden';

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

    /* ------------------------------------------------------------------------ 409 */

    /** Upstream's `/download` 409 when nothing has been generated: verbatim token. */
    case NoDocumentAvailable = 'no_document_available';

    /** Cancel or send on a request whose state does not allow it. */
    case InvalidState = 'invalid_state';

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
            self::InvalidRequest => Response::HTTP_BAD_REQUEST,
            self::Unauthorized => Response::HTTP_UNAUTHORIZED,
            self::Forbidden => Response::HTTP_FORBIDDEN,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::NoDocumentAvailable, self::InvalidState => Response::HTTP_CONFLICT,
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
