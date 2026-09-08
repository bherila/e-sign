<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use Symfony\Component\HttpFoundation\Response;

/**
 * Every machine-readable error code the native API can return.
 *
 * The string values are the wire format. A client switches on them, so renaming one is a
 * breaking change exactly like renaming a field-schema validation code, and the OpenAPI
 * document enumerates them from this enum's own cases.
 *
 * Each code carries the status it is returned with, in one place, so a route cannot answer
 * `409 validation_failed` in one controller and `422 validation_failed` in another. Where a
 * domain exception already has a stable `code()` — the signing module's
 * App\Domain\Signing\Exceptions\SigningException hierarchy — the value here is that same
 * string, so a caller sees one vocabulary rather than a translation of one.
 */
enum ErrorCode: string
{
    /* ---------------------------------------------------------------- 401 / 403 */

    /** No credential, an unknown one, a revoked one, or an expired one. */
    case InvalidCredential = 'invalid_credential';

    /** A valid credential that was not granted the scope this route names. */
    case InsufficientScope = 'insufficient_scope';

    /* ------------------------------------------------------------------ 404 / 405 */

    /**
     * No such resource **in the caller's workspace**.
     *
     * Returned for another tenant's identifier as well as for one that does not exist
     * anywhere, and deliberately indistinguishable between the two: the lookup is
     * constrained by the principal's workspace before the identifier is used, so the API
     * never learns which it was either (docs/HANDOFF.md section 10).
     */
    case NotFound = 'not_found';

    case MethodNotAllowed = 'method_not_allowed';

    /* ------------------------------------------------------------------------ 409 */

    /** The transition does not exist from the state the envelope is in. */
    case IllegalTransition = 'illegal_transition';

    /** The move was legal but somebody else moved first. Re-read and retry. */
    case Conflict = 'conflict';

    /** Artifacts were asked for before finalization produced one. */
    case NotCompleted = 'not_completed';

    /** The same idempotency key is being replayed while the first attempt is still running. */
    case IdempotencyKeyInFlight = 'idempotency_key_in_flight';

    /* ------------------------------------------------------------------------ 422 */

    case ValidationFailed = 'validation_failed';

    /** The same idempotency key was presented with a different request. */
    case IdempotencyKeyReused = 'idempotency_key_reused';

    case InvalidCursor = 'invalid_cursor';

    /** The envelope is not ready to be sent; `details.problems` lists every reason at once. */
    case SendPreconditionsFailed = 'send_preconditions_failed';

    /** A field value was refused: unknown field, not the sender's to write, wrong type, frozen. */
    case FieldRejected = 'field_rejected';

    /** The snapshot an envelope would be built from is not usable. */
    case InvalidSnapshot = 'invalid_snapshot';

    /** A recipient id in the request is not one the field schema declares. */
    case UnknownRecipient = 'unknown_recipient';

    /** A template, version, or document is in a state this call cannot use. */
    case TemplateState = 'template_state';

    /** A webhook URL the outbound destination policy refuses. */
    case DestinationRefused = 'destination_refused';

    /** An event name that is neither in the compatibility profile nor prefixed `esign.`. */
    case UnknownEventName = 'unknown_event_name';

    /* ------------------------------------------------------------------------ 429 */

    /**
     * Too many failed authentications from this client address in the last minute.
     *
     * Counted on failures only, so a working integration never sees it. The answer carries
     * `Retry-After` (docs/security/review-2026-09.md finding A-1).
     */
    case TooManyRequests = 'too_many_requests';

    /* ------------------------------------------------------------------------ 501 */

    /**
     * Declared in this document and not implemented in this build.
     *
     * Fail closed (AGENTS.md): an option the API advertises but cannot honour is an error
     * with this code, never a success that quietly did something else.
     */
    case Unsupported = 'unsupported';

    /* ------------------------------------------------------------------------ 500 */

    case InternalError = 'internal_error';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredential => Response::HTTP_UNAUTHORIZED,
            self::InsufficientScope => Response::HTTP_FORBIDDEN,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::MethodNotAllowed => Response::HTTP_METHOD_NOT_ALLOWED,
            self::IllegalTransition,
            self::Conflict,
            self::NotCompleted,
            self::IdempotencyKeyInFlight => Response::HTTP_CONFLICT,
            self::ValidationFailed,
            self::IdempotencyKeyReused,
            self::InvalidCursor,
            self::SendPreconditionsFailed,
            self::FieldRejected,
            self::InvalidSnapshot,
            self::UnknownRecipient,
            self::TemplateState,
            self::DestinationRefused,
            self::UnknownEventName => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::TooManyRequests => Response::HTTP_TOO_MANY_REQUESTS,
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
