<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Integration\Native\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * The `Idempotency-Key` protocol, as a service rather than as a controller habit.
 *
 * A network retry must not create a second envelope. The upstream contract the compatibility
 * facade reproduces has no idempotency at all (docs/HANDOFF.md section 10: "Add native
 * idempotency keys and a clearly documented compatibility extension where the upstream
 * contract lacks them"), so this is ours, and both HTTP surfaces use this one implementation.
 *
 * ## The protocol
 *
 * 1. **Claim.** Insert `(credential_id, key, request_hash)` with no response. The unique
 *    index decides the race: exactly one concurrent request gets the claim.
 * 2. **Run**, then **complete** the row with the status and the exact response body.
 * 3. A later request with the **same key and the same request** replays that body verbatim.
 * 4. A later request with the **same key and a different request** is refused
 *    `422 idempotency_key_reused`. It is a client bug, and answering it with the first
 *    call's response would be a silent no-op on a call the client believes it made.
 * 5. A request that arrives while the claim is still open is `409 idempotency_key_in_flight`.
 *    Retrying after the first attempt finishes replays it.
 *
 * ## What is stored
 *
 * Only successful responses (2xx). A failure leaves the claim behind with a null status,
 * which expires with the rest; replaying a transient 500 would make it permanent, and
 * replaying a 422 would freeze a validation error the client has since fixed.
 *
 * Expiry is 24 hours from the claim. An expired row is not a match — it is deleted and the
 * request proceeds as new — so a key that outlives its window fails open into a fresh call
 * rather than silently replaying week-old content.
 */
final class IdempotencyStore
{
    /** How long a recorded response can be replayed. */
    public const TTL_HOURS = 24;

    /** The header, and the maximum key length that will be accepted. */
    public const HEADER = 'Idempotency-Key';

    public const MAX_KEY_LENGTH = 255;

    /**
     * The digest a replay is checked against.
     *
     * The body is canonicalised when it parses as JSON — object keys sorted, recursively —
     * so a client that serialises its map in a different order on the retry still replays
     * rather than being told it reused the key. Anything that is not JSON is hashed as
     * bytes, which is the only honest thing to do with an opaque payload.
     *
     * The **query string is part of the request** and is hashed too, sorted so parameter
     * order cannot defeat a legitimate replay. Leaving it out was not cosmetic: every Form
     * Request on this surface validates `$request->all()`, which merges the query, so
     * `POST …/rotate-secret?grace_hours=24` and `POST …/rotate-secret?grace_hours=0` had the
     * same method, path, and (empty) body — the same fingerprint. The second was answered
     * with a replay of the first, so an operator cutting a leaked secret over immediately got
     * a 200 carrying a secret, and no rotation at all
     * (docs/security/review-2026-09.md finding A-3).
     */
    public static function hashRequest(string $method, string $path, string $body, string $query = ''): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $body = self::canonicalJson($decoded);
        }

        return hash(
            'sha256',
            strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".self::canonicalQuery($query)."\n".$body,
        );
    }

    /**
     * A query string in a stable order, so `?a=1&b=2` and `?b=2&a=1` are one request.
     *
     * `parse_str` is what Laravel's own input resolution uses, so the canonical form is built
     * from the same parse that decides what the request actually said.
     */
    private static function canonicalQuery(string $query): string
    {
        if (trim($query) === '') {
            return '';
        }

        parse_str($query, $parameters);
        ksort($parameters);

        return (string) json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Claim the key, or hand back the recorded response for a replay.
     *
     * @return IdempotentResponse|null Null means the caller now owns the claim and must run
     *                                 the request, then call {@see complete()}.
     *
     * @throws ApiException On reuse with a different request, or on a claim still in flight.
     */
    public function claim(ServiceCredential $credential, string $key, string $requestHash): ?IdempotentResponse
    {
        $existing = $this->existing($credential, $key);

        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        try {
            IdempotencyKey::query()->create([
                'credential_id' => $credential->getKey(),
                'key' => $key,
                'request_hash' => $requestHash,
            ]);
        } catch (QueryException) {
            // Another request claimed the same key between the read and the insert. The
            // unique index is the authority; re-read and answer from whatever it holds.
            $raced = $this->existing($credential, $key);

            if ($raced === null) {
                throw ApiException::of(
                    ErrorCode::Conflict,
                    'The idempotency key could not be claimed. Retry the request.',
                );
            }

            return $this->replay($raced, $requestHash);
        }

        return null;
    }

    /**
     * Record the response for a claim this process owns.
     *
     * Non-2xx responses release the claim instead of storing it, so a client that fixes its
     * payload and retries with the same key gets a real attempt rather than its own old
     * error back.
     */
    public function complete(ServiceCredential $credential, string $key, int $status, string $body): void
    {
        $row = IdempotencyKey::query()
            ->where('credential_id', $credential->getKey())
            ->where('key', $key)
            ->first();

        if (! $row instanceof IdempotencyKey) {
            return;
        }

        if ($status < 200 || $status > 299) {
            $row->delete();

            return;
        }

        $row->response_status = $status;
        $row->response_body = $body;
        $row->completed_at = CarbonImmutable::now();
        $row->save();
    }

    /** Release a claim whose request never produced a response (an exception, a crash). */
    public function release(ServiceCredential $credential, string $key): void
    {
        IdempotencyKey::query()
            ->where('credential_id', $credential->getKey())
            ->where('key', $key)
            ->whereNull('response_status')
            ->delete();
    }

    /**
     * Delete every key past its window.
     *
     * @return int Rows removed.
     */
    public function prune(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subHours(self::TTL_HOURS);

        return IdempotencyKey::query()->where('created_at', '<=', $cutoff)->delete();
    }

    private function existing(ServiceCredential $credential, string $key): ?IdempotencyKey
    {
        $row = IdempotencyKey::query()
            ->where('credential_id', $credential->getKey())
            ->where('key', $key)
            ->first();

        if (! $row instanceof IdempotencyKey) {
            return null;
        }

        if ($this->hasExpired($row)) {
            // Past its window it is not a record of anything. Removing it here rather than
            // waiting for the prune keeps "expired" and "never seen" identical to a caller.
            $row->delete();

            return null;
        }

        return $row;
    }

    private function hasExpired(IdempotencyKey $row): bool
    {
        return $row->created_at === null
            || $row->created_at->addHours(self::TTL_HOURS)->isPast();
    }

    /**
     * @throws ApiException
     */
    private function replay(IdempotencyKey $row, string $requestHash): IdempotentResponse
    {
        if (! hash_equals($row->request_hash, $requestHash)) {
            throw ApiException::of(
                ErrorCode::IdempotencyKeyReused,
                'This Idempotency-Key was already used for a different request. '
                .'Use a fresh key for a different call, or resend the original request byte for byte to replay it.',
            );
        }

        if ($row->isInFlight()) {
            throw ApiException::of(
                ErrorCode::IdempotencyKeyInFlight,
                'A request with this Idempotency-Key is still being processed. Retry shortly to replay its response.',
            );
        }

        return new IdempotentResponse((int) $row->response_status, (string) $row->response_body);
    }

    /**
     * Recursively key-sorted JSON. Lists keep their order — it is meaningful — and objects
     * do not, because two encoders may emit the same map differently.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function canonicalJson(array $value): string
    {
        return (string) json_encode(
            self::sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
