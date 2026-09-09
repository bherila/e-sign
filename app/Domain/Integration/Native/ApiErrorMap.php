<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Webhooks\Exceptions\UnknownEventNameException;
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
 * Every way the native API can fail, mapped once.
 *
 * The domain modules refuse things in their own vocabulary — an illegal transition, a stale
 * compare-and-swap, a rejected field value, a refused webhook destination — and each of those
 * refusals owes a different HTTP answer. This is the single translation table. It lives in
 * the Integration module rather than in a controller so that both HTTP surfaces map the same
 * exception the same way, and so a new domain exception has exactly one place to be handled.
 *
 * The classification also decides what gets **logged**. Anything this map recognises is an
 * expected refusal: the caller asked for something the application will not do, was told
 * plainly why, and nothing is wrong with the deployment. Only the default arm — an exception
 * nobody anticipated — is an application fault, and only that is reported.
 * {@see isExpectedRefusal()} is what App\Providers\IntegrationServiceProvider uses to keep a
 * `409` off the error log.
 *
 * Nothing here puts a file path, a stack trace, or SQL into a body. An unexpected failure
 * gets a fixed sentence; the detail goes to the reporter, where an operator can see all of it.
 */
final class ApiErrorMap
{
    public static function translate(Throwable $exception): ApiException
    {
        return match (true) {
            $exception instanceof ApiException => $exception,

            $exception instanceof ValidationException => ApiException::of(
                ErrorCode::ValidationFailed,
                'The request body did not validate.',
                ['fields' => $exception->errors()],
            ),

            // The move is illegal from this state no matter who asks: send twice, cancel a
            // completed envelope, patch one that is already out.
            $exception instanceof IllegalTransition => ApiException::of(
                ErrorCode::IllegalTransition,
                $exception->getMessage(),
                ['transition' => $exception->transition, 'from' => $exception->from],
            ),

            // The move was legal and somebody else made it first. Re-read and retry.
            $exception instanceof StaleEnvelope => ApiException::of(
                ErrorCode::Conflict,
                $exception->getMessage(),
                ['subject' => $exception->subject, 'expected_version' => $exception->expectedVersion],
            ),

            // Every reason at once, because send is the last cheap moment to fix any of them.
            $exception instanceof SendPreconditionsFailed => ApiException::of(
                ErrorCode::SendPreconditionsFailed,
                $exception->getMessage(),
                ['problems' => $exception->problems],
            ),

            $exception instanceof FieldSubmissionRejected => ApiException::of(
                ErrorCode::FieldRejected,
                $exception->getMessage(),
                ['field' => $exception->fieldId, 'reason' => $exception->reason],
            ),

            // The structured errors when the snapshot carried any — a schema that does not import
            // brings the importer's own list. Without them a caller reading
            // `reason: invalid_field_schema` has to guess which of a dozen rules it broke, and the
            // codes that say something specific — `coordinate_too_precise`,
            // `anchor_resolution_unavailable` — would survive only inside a sentence.
            $exception instanceof InvalidEnvelopeSnapshot => ApiException::of(
                ErrorCode::InvalidSnapshot,
                $exception->getMessage(),
                array_filter([
                    'reason' => $exception->reason,
                    'problems' => array_map(
                        static fn (ValidationError $error): array => $error->toArray(),
                        $exception->problems,
                    ),
                ], static fn (mixed $value): bool => $value !== []),
            ),

            // Every structured error, not only the first one flattened into a sentence. Codes
            // are API surface and a client is expected to branch on them — `coordinate_too_precise`
            // and `anchor_resolution_unavailable` in particular say something a caller can act on
            // that "invalid_field_schema" does not.
            $exception instanceof InvalidFieldSchemaException => ApiException::of(
                ErrorCode::InvalidSnapshot,
                $exception->getMessage(),
                [
                    'reason' => 'invalid_field_schema',
                    'problems' => array_map(
                        static fn (ValidationError $error): array => $error->toArray(),
                        $exception->errors(),
                    ),
                ],
            ),

            // Anything else the signing module refuses. `code()` is documented as a stable
            // identifier and part of the API surface, so it is passed through as the reason
            // rather than flattened away.
            $exception instanceof SigningException => ApiException::of(
                ErrorCode::ValidationFailed,
                $exception->getMessage(),
                ['reason' => $exception->code()],
            ),

            $exception instanceof TemplateStateException => ApiException::of(
                ErrorCode::TemplateState,
                $exception->getMessage(),
                ['reason' => $exception->reason],
            ),

            $exception instanceof DestinationRefusedException => ApiException::of(
                ErrorCode::DestinationRefused,
                $exception->getMessage(),
            ),

            $exception instanceof UnknownEventNameException => ApiException::of(
                ErrorCode::UnknownEventName,
                $exception->getMessage(),
            ),

            // A `firstOrFail()` behind a workspace-constrained query. Same answer as any
            // other missing resource: the caller learns nothing about other tenants.
            $exception instanceof ModelNotFoundException => ApiException::of(
                ErrorCode::NotFound,
                'No resource with that id exists in this workspace.',
            ),

            $exception instanceof AuthorizationException => ApiException::of(
                ErrorCode::InsufficientScope,
                'This API credential may not perform that action.',
            ),

            $exception instanceof HttpExceptionInterface => self::fromHttpException($exception),

            default => self::unexpected($exception),
        };
    }

    /**
     * Whether this is a refusal the API states plainly, rather than a fault.
     *
     * Used to keep expected answers out of the error log. A `409 illegal_transition` is the
     * API working: an operator paged for one would learn nothing, and a log full of them
     * hides the one exception that does matter.
     */
    public static function isExpectedRefusal(Throwable $exception): bool
    {
        return self::translate($exception)->errorCode !== ErrorCode::InternalError;
    }

    public static function defaultMessage(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::InvalidCredential => 'No usable API credential was presented.',
            ErrorCode::InsufficientScope => 'This API credential is not granted the scope this endpoint requires.',
            ErrorCode::NotFound => 'No resource with that id exists in this workspace.',
            ErrorCode::MethodNotAllowed => 'That HTTP method is not allowed on this endpoint.',
            ErrorCode::TooManyRequests => 'Too many failed authentication attempts. Try again shortly.',
            default => 'The request could not be completed.',
        };
    }

    public static function codeForStatus(int $status): ErrorCode
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => ErrorCode::InvalidCredential,
            Response::HTTP_FORBIDDEN => ErrorCode::InsufficientScope,
            Response::HTTP_NOT_FOUND => ErrorCode::NotFound,
            Response::HTTP_METHOD_NOT_ALLOWED => ErrorCode::MethodNotAllowed,
            Response::HTTP_CONFLICT => ErrorCode::Conflict,
            Response::HTTP_UNPROCESSABLE_ENTITY => ErrorCode::ValidationFailed,
            Response::HTTP_TOO_MANY_REQUESTS => ErrorCode::TooManyRequests,
            Response::HTTP_NOT_IMPLEMENTED => ErrorCode::Unsupported,
            default => ErrorCode::InternalError,
        };
    }

    private static function fromHttpException(HttpExceptionInterface&Throwable $exception): ApiException
    {
        $code = match ($exception->getStatusCode()) {
            Response::HTTP_UNAUTHORIZED => ErrorCode::InvalidCredential,
            Response::HTTP_FORBIDDEN => ErrorCode::InsufficientScope,
            Response::HTTP_NOT_FOUND => ErrorCode::NotFound,
            Response::HTTP_METHOD_NOT_ALLOWED => ErrorCode::MethodNotAllowed,
            Response::HTTP_CONFLICT => ErrorCode::Conflict,
            Response::HTTP_TOO_MANY_REQUESTS => ErrorCode::TooManyRequests,
            Response::HTTP_NOT_IMPLEMENTED => ErrorCode::Unsupported,
            default => null,
        };

        if ($code === null) {
            return self::unexpected($exception);
        }

        $message = trim($exception->getMessage());

        return ApiException::of($code, $message === '' ? self::defaultMessage($code) : $message);
    }

    private static function unexpected(Throwable $exception): ApiException
    {
        return ApiException::of(
            ErrorCode::InternalError,
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

        // Class and message only, never the trace and never a file path: even a local body
        // gets pasted into a ticket.
        return ['exception' => $exception::class, 'exception_message' => $exception->getMessage()];
    }
}
