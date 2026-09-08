<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

/**
 * Reduces what a signing session observed about a client to the little of it that is worth
 * keeping.
 *
 * docs/HANDOFF.md section 8 asks for "appropriately minimized network/client evidence" and,
 * in the same breath, warns not to treat an IP address or a user agent as conclusive
 * identity proof. Both statements point the same way: keep a small, fixed set of facts that
 * corroborate a session, and refuse to accumulate a browser fingerprint that would look like
 * proof without being any.
 *
 * So this is an allowlist, not a scrubber. Anything not named below is dropped rather than
 * sanitized, because a scrubber has to be right about every input and an allowlist only has
 * to be right about the ones it keeps. Nothing nested is accepted: a caller that wants to
 * record something structured has to add a key here and say why.
 *
 * What is deliberately absent: cookies, authorization headers, session identifiers, the
 * referrer (signing pages send none — AGENTS.md), and anything a page could compute about
 * fonts, canvases, or hardware.
 */
final class ClientEvidence
{
    /**
     * The keys kept, and nothing else.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        // The address the request arrived from. Corroborating, never identifying, and often
        // a shared egress. Kept whole: a truncated address corroborates nothing.
        'ip',
        'user_agent',
        'accept_language',
        // What the browser said its zone was. A client claim, recorded as one; the
        // authoritative acceptance time is the server's, on the attestation.
        'client_timezone',
        // Free-form label for the surface used, e.g. "guest_signing_page".
        'channel',
    ];

    /** Long enough for a real user agent, short enough that nothing can be smuggled in one. */
    public const MAX_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    public static function minimize(array $raw): array
    {
        $minimized = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            // Scalars only. A caller that passes an array for `user_agent` is either
            // confused or probing, and neither is a reason to store it.
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $minimized[$key] = mb_substr($value, 0, self::MAX_LENGTH);
        }

        return $minimized;
    }
}
