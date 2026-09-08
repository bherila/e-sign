<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Finds a public RFC 3161 authority that is actually answering right now.
 *
 * Only tests use this. The application never probes or falls back between
 * authorities: exactly one endpoint is configured, and a failure there is a
 * failure, not a reason to try somewhere else. The candidate list exists so a
 * B-T test can skip honestly instead of failing when a host is offline.
 *
 * `ESIGN_TEST_TSA_URL` overrides the list, which is how CI pins one endpoint.
 */
final class TsaProbe
{
    /**
     * Public authorities, in the order they are tried.
     *
     * freetsa.org publishes over HTTPS but its root is in no ordinary trust
     * store; DigiCert's root is widely trusted but its endpoint is plaintext
     * HTTP only. Both facts are part of the Stage 0 finding.
     *
     * @return list<string>
     */
    public static function candidateUrls(): array
    {
        $configured = getenv('ESIGN_TEST_TSA_URL');
        if (is_string($configured) && trim($configured) !== '') {
            return [trim($configured)];
        }

        return [
            'https://freetsa.org/tsr',
            'http://timestamp.digicert.com',
        ];
    }

    /**
     * The first candidate that accepts a TCP connection, or null when none do.
     */
    public static function firstReachable(int $timeoutSeconds = 5): ?string
    {
        return self::allReachable($timeoutSeconds)[0] ?? null;
    }

    /**
     * Every candidate that accepts a TCP connection, in candidate order.
     *
     * A caller that needs a token rather than a socket tries them in turn: an
     * authority that is answering can still refuse a particular request, for
     * its own reasons and without saying which, and that is not the same event
     * as having no egress at all.
     *
     * @return list<string>
     */
    public static function allReachable(int $timeoutSeconds = 5): array
    {
        $reachable = [];

        foreach (self::candidateUrls() as $url) {
            if (self::isReachable($url, $timeoutSeconds)) {
                $reachable[] = $url;
            }
        }

        return $reachable;
    }

    /**
     * True when a TCP connection to this endpoint's host and port succeeds.
     *
     * A connection, not a timestamp request: the point is to tell "no egress
     * from this host" apart from "the authority refused the token", which are
     * a skip and a failure respectively.
     */
    public static function isReachable(string $url, int $timeoutSeconds = 5): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            return false;
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @fsockopen($parts['host'], (int) $port, $errorNumber, $errorMessage, $timeoutSeconds);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
