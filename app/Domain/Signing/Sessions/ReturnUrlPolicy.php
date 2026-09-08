<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use Illuminate\Contracts\Config\Repository;

/**
 * Where a signer may be sent once they are finished.
 *
 * docs/HANDOFF.md section 8: "Validate callback/return destinations rather than reflecting
 * arbitrary URLs." An integrating application wants its user back afterwards, and the naive
 * implementation — echo `?return=` into a `Location` header — is an open redirect on a page
 * whose entire purpose is to be trusted while somebody signs something.
 *
 * So a destination is used only when its host appears in
 * `config('esign.signing.return_url_allowlist')`, which is deployment configuration and not
 * anything a workspace member can set. Everything else is *ignored*: the signer sees the
 * ordinary confirmation page and no error. Rejecting loudly would turn the parameter into an
 * oracle for what the allowlist contains, and a signer who has just executed an agreement
 * should not be shown a validation failure about somebody else's integration.
 *
 * The allowlist is empty by default. A deployment that has not thought about return
 * destinations honours none, which is the outcome that cannot be wrong.
 *
 * The validated URL is stored on the session and re-read from there, never re-read from the
 * request on the way out — so the value that decides the final redirect is one this class
 * has already accepted.
 */
final class ReturnUrlPolicy
{
    /** Wider than any sane destination, narrow enough for the column that holds it. */
    public const MAX_LENGTH = 2_048;

    public function __construct(private readonly Repository $config) {}

    /**
     * The destination to honour, or null.
     *
     * Null is returned for every failure without distinguishing them, on purpose; see the
     * class docblock.
     */
    public function sanitize(?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            // Relative destinations are refused too. "Back to where you came from" inside
            // this application is a link the confirmation page already knows how to build;
            // a relative `?return=` is only ever somebody probing.
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $this->isAllowedHost($parts['host']) ? $candidate : null;
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = $this->config->get('esign.signing.return_url_allowlist', []);

        if (! is_array($hosts)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '',
            $hosts,
        ), static fn (string $host): bool => $host !== ''));
    }

    /**
     * Exact host match, case-insensitive.
     *
     * No suffix matching, deliberately. A `*.example.test` rule reads as a convenience and
     * behaves as a delegation: whoever can register a subdomain — or take over an abandoned
     * one — inherits the redirect. Listing the hosts is a sentence longer in `.env` and has
     * no such failure mode.
     */
    private function isAllowedHost(string $host): bool
    {
        return in_array(strtolower($host), $this->allowedHosts(), true);
    }
}
