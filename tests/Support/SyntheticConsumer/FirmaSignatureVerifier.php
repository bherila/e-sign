<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

/**
 * The receiver's own implementation of the `X-Firma-Signature` scheme.
 *
 * Deliberately independent of `App\Domain\Delivery\Webhooks\WebhookSigner`. Nothing in this
 * file imports it, and nothing here is shared with it: this is the verification a consumer
 * writes from `docs/delivery/webhooks.md` alone, so a change to our signer that stopped
 * matching the documented scheme would break these tests rather than move with them.
 *
 * All three of the rules that document calls non-optional are here, because a receiver that
 * skips one is the receiver the rule exists for:
 *
 * 1. **The raw body.** `verify()` takes the exact bytes off the wire. A framework-decoded
 *    and re-encoded body is a different byte string and will not verify.
 * 2. **A replay window.** Freshness is checked before any HMAC is computed, so an ancient
 *    replay costs nothing.
 * 3. **Constant time.** Every candidate signature is compared with `hash_equals` and the
 *    loop never returns early, so the number of comparisons does not depend on which
 *    secret matched.
 */
final class FirmaSignatureVerifier
{
    /** The window `docs/delivery/webhooks.md` makes mandatory for this profile. */
    public const REPLAY_WINDOW_SECONDS = 300;

    /**
     * The `t=` value and every `v1=` value, in the order they appear.
     *
     * A malformed header yields `[null, []]` rather than an exception: a receiver answers a
     * malformed request with a 400, and throwing here would turn one into a 500 — which,
     * per the same document, asks the sender to retry something that can never verify.
     *
     * @return array{0: int|null, 1: list<string>}
     */
    public static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);

            match (trim($key)) {
                't' => $timestamp = ctype_digit($value) ? (int) $value : null,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        return [$timestamp, $signatures];
    }

    /**
     * @param  list<string>  $secrets  Every secret the receiver currently accepts. More than
     *                                 one only during a rotation overlap.
     */
    public static function verify(
        string $header,
        string $rawBody,
        array $secrets,
        int $now,
        int $windowSeconds = self::REPLAY_WINDOW_SECONDS,
    ): bool {
        [$timestamp, $signatures] = self::parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        // Freshness first, before any crypto: rule 2.
        if (abs($now - $timestamp) > $windowSeconds) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$rawBody;
        $ok = false;

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);

            foreach ($signatures as $candidate) {
                // No early return, and `|| $ok` rather than `$ok ||`, so every comparison
                // runs whatever the first one answered: rule 3.
                $ok = hash_equals($expected, $candidate) || $ok;
            }
        }

        return $ok;
    }
}
