<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\ErrorCode;
use App\Domain\Preparation\Geometry\InvalidGeometryException;
use App\Domain\Preparation\Geometry\UndeclaredCoordinateConventionException;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\ValidationError;
use App\Domain\Preparation\Templates\TemplateStateException;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use App\Domain\Signing\Exceptions\SigningException;
use App\Domain\Signing\Exceptions\StaleEnvelope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every way the facade can fail, mapped into the upstream error envelope once.
 *
 * This is deliberately a *second* map beside App\Domain\Integration\Native\ApiErrorMap, and
 * not a wrapper around it. The two surfaces owe different bodies, different status codes for
 * the same refusal, and different vocabularies; collapsing them would mean one client's
 * contract changing whenever the other's did (`docs/HANDOFF.md` section 10).
 *
 * The domain exceptions themselves are untouched. A signing rule refuses in the state
 * machine's own words and only its presentation is decided here, which is what keeps the
 * rules out of the compatibility controllers (AGENTS.md, "One state machine").
 *
 * ## Where the status codes differ from the native API
 *
 * The native API answers `422` for a body it validated and then refused, and `409` only for
 * a state conflict. Upstream answers `400` for most refusals and `409` for the state ones,
 * so that is what the facade answers. A consumer written against upstream branches on the
 * status before it looks at the body, and a `422` where it expects a `400` is a bug in our
 * compatibility, not in its client.
 */
final class FirmaErrorMap
{
    public static function translate(Throwable $exception): FirmaException
    {
        return match (true) {
            $exception instanceof FirmaException => $exception,

            // The Form Requests speak this one. Upstream's `Error.details` is a free-form
            // object, so the field errors go there unchanged.
            $exception instanceof ValidationException => FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The request body did not validate.',
                ['fields' => $exception->errors()],
            ),

            // Send twice, cancel a finished request, patch one that is already out. Upstream
            // answers 409 for the cancel case and 400 for the patch case; 409 is the more
            // useful of the two everywhere, because it is the one a client must not retry.
            $exception instanceof IllegalTransition => FirmaException::of(
                FirmaErrorCode::InvalidState,
                $exception->getMessage(),
                ['transition' => $exception->transition, 'from' => $exception->from],
            ),

            $exception instanceof StaleEnvelope => FirmaException::of(
                FirmaErrorCode::InvalidState,
                $exception->getMessage(),
                ['subject' => $exception->subject, 'expected_version' => $exception->expectedVersion],
            ),

            // Every reason at once. Upstream's `SigningRequestUser.ready_to_send` is the
            // shape a consumer reads for this; `/users` reports it per recipient and the
            // reasons are repeated here so a failed send needs no second call.
            $exception instanceof SendPreconditionsFailed => FirmaException::of(
                FirmaErrorCode::UnprocessableEntity,
                $exception->getMessage(),
                ['problems' => $exception->problems],
            ),

            $exception instanceof FieldSubmissionRejected => FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                $exception->getMessage(),
                ['field' => $exception->fieldId, 'reason' => $exception->reason],
            ),

            $exception instanceof InvalidFieldSchemaException => FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'The fields could not be placed on the document: '.$exception->getMessage(),
                ['errors' => array_map(
                    static fn (ValidationError $error): array => $error->toArray(),
                    $exception->result->errors,
                )],
            ),

            $exception instanceof InvalidEnvelopeSnapshot => FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                $exception->getMessage(),
                ['reason' => $exception->reason],
            ),

            // A coordinate the profile's own convention cannot express: a percentage outside
            // 0..100, or a rectangle that would leave the page. Never reinterpreted as
            // points (`AGENTS.md`, disagreement D4).
            $exception instanceof InvalidGeometryException,
            $exception instanceof UndeclaredCoordinateConventionException => FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                $exception->getMessage(),
            ),

            $exception instanceof TemplateStateException => FirmaException::of(
                FirmaErrorCode::UnprocessableEntity,
                $exception->getMessage(),
                ['reason' => $exception->reason],
            ),

            $exception instanceof SigningException => FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                $exception->getMessage(),
                ['reason' => $exception->code()],
            ),

            $exception instanceof FinalizationException => FirmaException::of(
                FirmaErrorCode::NoDocumentAvailable,
                $exception->getMessage(),
            ),

            // Deliberately **not** mapped: App\Domain\Preparation\Documents\
            // DocumentStorageException. Its messages interpolate the disk name, the object
            // path, and the driver's own error text, so putting one on the wire would leak a
            // storage key from a public endpoint — and a storage outage answered as a client
            // error would have the caller retry a body that was never wrong. It falls to the
            // default arm: a fixed sentence, a 500, and a `report()`. The native
            // App\Domain\Integration\Native\ApiErrorMap leaves it unmapped for the same
            // reason.

            $exception instanceof ApiException => self::fromNative($exception),

            $exception instanceof ModelNotFoundException => FirmaException::notFound('signing request'),

            $exception instanceof AuthorizationException => FirmaException::of(
                FirmaErrorCode::Forbidden,
                'This API key may not perform that action.',
            ),

            $exception instanceof HttpExceptionInterface => self::fromHttpException($exception),

            default => self::unexpected($exception),
        };
    }

    /** Whether this is a refusal the facade states plainly, rather than a deployment fault. */
    public static function isExpectedRefusal(Throwable $exception): bool
    {
        return self::translate($exception)->errorCode !== FirmaErrorCode::InternalError;
    }

    public static function defaultMessage(FirmaErrorCode $code): string
    {
        return match ($code) {
            FirmaErrorCode::Unauthorized => 'No usable API key was presented.',
            FirmaErrorCode::Forbidden => 'This API key is not granted the scope this endpoint requires.',
            FirmaErrorCode::NotFound => 'No signing request with that id exists in this workspace.',
            default => 'The request could not be completed.',
        };
    }

    /**
     * Translate a refusal raised by the shared native services.
     *
     * `EnvelopeService`, `TemplateCatalog`, and `IdempotencyStore` are the same objects the
     * native API calls — that is the point — so they naturally speak `ErrorCode`. Their
     * refusals are re-dressed here rather than re-implemented, so the facade cannot drift
     * from the tenancy and lookup rules those services enforce.
     */
    private static function fromNative(ApiException $exception): FirmaException
    {
        $code = match ($exception->errorCode) {
            ErrorCode::InvalidCredential => FirmaErrorCode::Unauthorized,
            ErrorCode::InsufficientScope => FirmaErrorCode::Forbidden,
            ErrorCode::NotFound => FirmaErrorCode::NotFound,
            ErrorCode::IllegalTransition, ErrorCode::Conflict, ErrorCode::IdempotencyKeyInFlight => FirmaErrorCode::InvalidState,
            ErrorCode::NotCompleted => FirmaErrorCode::NoDocumentAvailable,
            ErrorCode::Unsupported => FirmaErrorCode::Unsupported,
            ErrorCode::SendPreconditionsFailed, ErrorCode::TemplateState => FirmaErrorCode::UnprocessableEntity,
            ErrorCode::InternalError => FirmaErrorCode::InternalError,
            default => FirmaErrorCode::InvalidRequest,
        };

        return new FirmaException($code, $exception->getMessage(), $exception->details);
    }

    private static function fromHttpException(HttpExceptionInterface&Throwable $exception): FirmaException
    {
        $code = match ($exception->getStatusCode()) {
            Response::HTTP_BAD_REQUEST => FirmaErrorCode::InvalidRequest,
            Response::HTTP_UNAUTHORIZED => FirmaErrorCode::Unauthorized,
            Response::HTTP_FORBIDDEN => FirmaErrorCode::Forbidden,
            // A malformed id caught by the router, an expired signed download URL, and an
            // unknown route under this prefix are all "there is nothing here for you".
            Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED => FirmaErrorCode::NotFound,
            Response::HTTP_CONFLICT => FirmaErrorCode::InvalidState,
            Response::HTTP_UNPROCESSABLE_ENTITY => FirmaErrorCode::UnprocessableEntity,
            Response::HTTP_NOT_IMPLEMENTED => FirmaErrorCode::Unsupported,
            default => null,
        };

        if ($code === null) {
            return self::unexpected($exception);
        }

        $message = trim($exception->getMessage());

        return FirmaException::of($code, $message === '' ? self::defaultMessage($code) : $message);
    }

    private static function unexpected(Throwable $exception): FirmaException
    {
        return FirmaException::of(
            FirmaErrorCode::InternalError,
            'The request could not be completed. The failure has been recorded; quote the time and the endpoint when reporting it.',
            self::debugDetails($exception),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function debugDetails(Throwable $exception): ?array
    {
        if (! config('app.debug') || app()->environment('production')) {
            return null;
        }

        // Class and message only. Never a trace and never a file path: even a local body
        // gets pasted into a ticket.
        return ['exception' => $exception::class, 'exception_message' => $exception->getMessage()];
    }
}
