<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Domain\Integration\Native\ApiErrorMap;
use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The native API's one error shape, applied on the way out.
 *
 * Every failure leaves `/api/v1` as:
 *
 *     {"error": {"code": "illegal_transition", "message": "...", "details": {...}}}
 *
 * `code` is machine-readable and stable ({@see ErrorCode}), `message` is for a human reading
 * a log, and `details` appears only when there is something structured to say. A client
 * switches on `code` and never on the message.
 *
 * ## Why a middleware and not the global exception handler
 *
 * bootstrap/app.php's handler serves the whole application — the signing UI, the health
 * probes, the compatibility facade — each of which owes a *different* error shape. The Firma
 * facade in particular must reproduce somebody else's error bodies exactly
 * (docs/HANDOFF.md section 10: "Preserve endpoint-specific response shapes rather than
 * forcing a single convenient serializer on every route"). Keeping the native shape in a
 * middleware attached to the native routes puts each surface's contract next to the routes
 * that owe it, and means adding this API cannot change what any other surface returns.
 *
 * ## Why it inspects the response instead of only catching
 *
 * `Illuminate\Routing\Pipeline` wraps every middleware layer *and* the route action in its
 * own try/catch: an exception from a controller is reported and rendered by the global
 * handler before any middleware's `catch` could see it, and what comes back up the stack is
 * an ordinary `Response`. It does, however, attach the original throwable to that response
 * (`withException()`), which is what this middleware reads. Catching is kept as well, for
 * the paths where the pipeline rethrows instead — belt and braces, since the cost of getting
 * this wrong is a stack trace on a public endpoint.
 *
 * It also normalises the two responses it does not raise: the shared `service-credential`
 * and `require-scope` middleware answer 401 and 403 in the flat `{message, error}` shape the
 * facade needs, and those are rewritten into the native envelope here. Those middleware are
 * untouched, so both surfaces keep the authentication behaviour and the `WWW-Authenticate`
 * headers they document.
 *
 * ## What never appears in a body
 *
 * No file path, no stack trace, no SQL, and no class name from an unexpected failure. The
 * detail goes to the reporter, where an operator can see all of it; the caller gets a fixed
 * sentence and a 500. With `APP_DEBUG` on outside production, and only there, the exception
 * class and message are added under `details` so a failing test is diagnosable.
 */
class ApiErrorBoundary
{
    /** Header set on a response replayed from an idempotency key. */
    public const REPLAY_HEADER = 'Idempotency-Replayed';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $error = ApiErrorMap::translate($exception);

            // The routing pipeline reports whatever it renders itself. This branch is the
            // one where it did not, so an unexpected failure is reported here instead — and
            // only an unexpected one: a 409 in the error log teaches nobody anything.
            if ($error->errorCode === ErrorCode::InternalError) {
                report($exception);
            }

            return $this->render($error);
        }

        $thrown = $response->exception ?? null;

        if ($thrown instanceof Throwable) {
            return $this->render(ApiErrorMap::translate($thrown));
        }

        return $this->normalise($response);
    }

    private function render(ApiException $exception): JsonResponse
    {
        return response()->json($exception->toArray(), $exception->status());
    }

    /**
     * Rewrite an error response raised by shared middleware into the native envelope.
     *
     * Successful responses, streamed artifact downloads, and anything already in the native
     * shape are returned untouched. Headers survive, which is what keeps the
     * `WWW-Authenticate` challenge on a 401.
     */
    private function normalise(Response $response): Response
    {
        if ($response->getStatusCode() < 400 || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || isset($payload['error']['code'])) {
            return $response;
        }

        $code = is_string($payload['error'] ?? null)
            ? ErrorCode::tryFrom($payload['error'])
            : null;
        $code ??= ApiErrorMap::codeForStatus($response->getStatusCode());

        $error = [
            'code' => $code->value,
            'message' => is_string($payload['message'] ?? null) && $payload['message'] !== ''
                ? $payload['message']
                : ApiErrorMap::defaultMessage($code),
        ];

        if (isset($payload['required_scope']) && is_string($payload['required_scope'])) {
            $error['details'] = ['required_scope' => $payload['required_scope']];
        }

        $response->setData(['error' => $error]);

        return $response;
    }
}
