<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\ErrorCode;
use App\Domain\Integration\Native\IdempotencyStore;
use App\Domain\Integration\Native\Models\IdempotencyKey;
use App\Http\Middleware\AuthenticateServiceCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Honours `Idempotency-Key` on every mutating call.
 *
 * A network timeout is not evidence that a request did not happen. Without this header a
 * client that retries a `POST /envelopes` after a dropped connection sends the same
 * agreement to the same people twice, and there is no way to tell afterwards which of the
 * two the signer used. With it, the second call returns the first call's response, byte for
 * byte, including the envelope id.
 *
 * The header is optional and honoured whenever it is present. Making it mandatory would
 * break the first call every integrator writes; making it advisory and ignoring it would be
 * worse, because a client that sends one is entitled to assume it means something.
 *
 * Runs *inside* the credential middleware, because a key is scoped to the credential that
 * presented it: see {@see IdempotencyKey} for why not
 * to the workspace. It runs *inside* {@see ApiErrorBoundary} too, so a refusal raised here
 * gets the same error envelope as everything else.
 *
 * Only 2xx responses are recorded. {@see IdempotencyStore} explains why replaying a failure
 * would be worse than re-running it.
 */
class ApiIdempotency
{
    /** The methods a key is honoured on. A GET is already idempotent by definition. */
    public const METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);
        $credential = $request->attributes->get(AuthenticateServiceCredential::CREDENTIAL_ATTRIBUTE);

        if ($key === null || ! $credential instanceof ServiceCredential) {
            return $next($request);
        }

        $hash = IdempotencyStore::hashRequest(
            $request->getMethod(),
            $request->path(),
            (string) $request->getContent(),
            // Part of the request, and part of what the Form Requests on this surface
            // validate: `$request->all()` merges the query. Omitting it made two different
            // calls share a fingerprint (docs/security/review-2026-09.md finding A-3).
            (string) $request->getQueryString(),
        );

        $replay = $this->store->claim($credential, $key, $hash);

        if ($replay !== null) {
            return response($replay->body, $replay->status, [
                'Content-Type' => 'application/json',
                // So a client can tell a replay from a fresh result without diffing bodies.
                ApiErrorBoundary::REPLAY_HEADER => 'true',
            ]);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // The request produced no response at all. Release the claim so the client's
            // retry is a real attempt rather than a permanent 409.
            $this->store->release($credential, $key);

            throw $exception;
        }

        $this->store->complete(
            $credential,
            $key,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        return $response;
    }

    /**
     * @throws ApiException When the header is present but not usable as a key.
     */
    private function key(Request $request): ?string
    {
        if (! in_array($request->getMethod(), self::METHODS, true)) {
            return null;
        }

        $key = trim((string) $request->header(IdempotencyStore::HEADER, ''));

        if ($key === '') {
            return null;
        }

        if (mb_strlen($key) > IdempotencyStore::MAX_KEY_LENGTH) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                'The Idempotency-Key header may be at most '.IdempotencyStore::MAX_KEY_LENGTH.' characters.',
                ['fields' => ['Idempotency-Key' => ['The key is too long.']]],
            );
        }

        return $key;
    }
}
