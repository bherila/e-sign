<?php

declare(strict_types=1);

namespace App\Http\Middleware\Compat;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaErrorMap;
use App\Domain\Integration\Firma\FirmaException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The facade's error shape, applied on the way out.
 *
 * Every failure under `/functions/v1/signing-request-api` leaves in the upstream envelope:
 *
 *     {"error": "invalid_state", "message": "…", "details": {…}}
 *
 * and `create-and-send`'s validation failure leaves in *its* envelope, which carries `code`,
 * `phase` and `validation_errors` instead of `details` — because upstream's document does not
 * use one error shape (disagreement D1) and a single serializer cannot produce both.
 *
 * ## Why a middleware, and why a second one
 *
 * `App\Http\Middleware\Api\ApiErrorBoundary` does the same job for `/api/v1` and renders a
 * completely different body. Neither may inherit the other's:
 * `docs/HANDOFF.md` section 10 is explicit that endpoint-specific response shapes are
 * preserved rather than forced through one convenient serializer, and that applies to
 * failures at least as much as to successes — a consumer branches on the status and the
 * `error` token long before it reads anything else. Keeping each surface's contract in a
 * middleware attached to that surface's routes means adding one cannot change the other, and
 * bootstrap/app.php's global handler stays the answer for the signing UI and the health
 * probes.
 *
 * It inspects the response as well as catching, for the reason `ApiErrorBoundary` documents:
 * `Illuminate\Routing\Pipeline` wraps the route action in its own try/catch, so an exception
 * from a controller has already been rendered by the global handler by the time a
 * middleware's `catch` could see it. What comes back up the stack is an ordinary `Response`
 * with the throwable attached, which is what this reads.
 *
 * It also rewrites the two responses it does not raise. The shared `service-credential` and
 * `require-scope` middleware answer `401` and `403` in a flat `{message, error}` shape; those
 * become this envelope here, with the `WWW-Authenticate` header left intact so both surfaces
 * keep the authentication behaviour they document.
 *
 * ## `unsupported` is 501 and always names the option
 *
 * AGENTS.md: unsupported routes and options return clear errors, never a successful no-op.
 * The `501` bodies this renders carry `details.unsupported_option`, so an integrator reads
 * *which* instruction was refused instead of a bare "not implemented".
 *
 * Nothing puts a file path, a stack trace, SQL, or the class name of an unexpected failure
 * into a body. With `APP_DEBUG` on outside production, and only there, the exception class
 * and message are added under `details` so a failing test is diagnosable.
 */
class FirmaErrorBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            return $this->rendered($exception);
        }

        $thrown = $response->exception ?? null;

        if ($thrown instanceof Throwable) {
            return $this->render(FirmaErrorMap::translate($thrown));
        }

        return $this->normalise($response);
    }

    private function rendered(Throwable $exception): JsonResponse
    {
        $error = FirmaErrorMap::translate($exception);

        // The routing pipeline reports whatever it renders itself. This branch is the one
        // where it did not, so an unexpected failure is reported here — and only an
        // unexpected one: a 409 in the error log teaches nobody anything.
        if ($error->errorCode === FirmaErrorCode::InternalError) {
            report($exception);
        }

        return $this->render($error);
    }

    private function render(FirmaException $exception): JsonResponse
    {
        return response()->json($exception->toArray(), $exception->status());
    }

    /**
     * Rewrite an error response raised by shared middleware or by the signed-URL guard.
     *
     * Successful responses, streamed downloads, and anything already in this shape are
     * returned untouched. Headers survive, which is what keeps the `WWW-Authenticate`
     * challenge on a 401.
     */
    private function normalise(Response $response): Response
    {
        if ($response->getStatusCode() < 400 || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        $existing = $payload['error'] ?? null;

        if (is_string($existing) && FirmaErrorCode::tryFrom($existing) instanceof FirmaErrorCode) {
            return $response;
        }

        // The shared credential middleware's own tokens first, so a 401 keeps saying what it
        // said; then the status, which covers everything the framework itself renders.
        //
        // The status is **not** rewritten. Only the body is re-dressed, so a response this
        // middleware did not raise cannot end up saying `internal_error` over a `429` — a
        // token that disagrees with its own status is worse than a vague one.
        $code = match ($existing) {
            'invalid_credential' => FirmaErrorCode::Unauthorized,
            'insufficient_scope' => FirmaErrorCode::Forbidden,
            default => $this->tokenForStatus($response->getStatusCode()),
        };

        $body = [
            'error' => $code->value,
            'message' => is_string($payload['message'] ?? null) && $payload['message'] !== ''
                ? $payload['message']
                : FirmaErrorMap::defaultMessage($code),
        ];

        if (isset($payload['required_scope']) && is_string($payload['required_scope'])) {
            $body['details'] = ['required_scope' => $payload['required_scope']];
        }

        $response->setData($body);

        return $response;
    }

    /**
     * The closest token in this profile's vocabulary for a status the framework rendered.
     *
     * Total on purpose: every status gets a token, so nothing leaves this surface in the
     * framework's `{message}` shape. `405` maps to `not_found` because the vocabulary has no
     * method token and "there is nothing here for you" is the honest reading — in practice
     * the catch-all route in `routes/compat-firma.php` answers `501` before a `405` can
     * happen, which is a better answer still.
     */
    private function tokenForStatus(int $status): FirmaErrorCode
    {
        return match (true) {
            $status === Response::HTTP_UNAUTHORIZED => FirmaErrorCode::Unauthorized,
            $status === Response::HTTP_FORBIDDEN => FirmaErrorCode::Forbidden,
            $status === Response::HTTP_NOT_FOUND,
            $status === Response::HTTP_METHOD_NOT_ALLOWED => FirmaErrorCode::NotFound,
            $status === Response::HTTP_CONFLICT => FirmaErrorCode::InvalidState,
            $status === Response::HTTP_UNPROCESSABLE_ENTITY => FirmaErrorCode::UnprocessableEntity,
            $status === Response::HTTP_NOT_IMPLEMENTED => FirmaErrorCode::Unsupported,
            $status >= 500 => FirmaErrorCode::InternalError,
            default => FirmaErrorCode::InvalidRequest,
        };
    }
}
