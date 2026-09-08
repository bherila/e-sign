<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityDestinationException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityUnreachableException;

/**
 * RFC 3161 transport over cURL, behind a destination policy.
 *
 * The policy exists because the TSA endpoint is operator configuration that
 * the signing pipeline dereferences: without it, a URL in the environment is
 * an SSRF primitive reachable from a queue worker. It refuses anything but
 * HTTP(S), refuses plaintext HTTP unless the operator opted in, refuses
 * credentials in the URL, refuses a host that resolves to a loopback,
 * private, link-local, carrier-grade-NAT, IPv6-transition, or otherwise
 * reserved address, and pins the connection to the addresses it validated so
 * neither a second DNS answer nor an unresolved record can move the target.
 * Redirects are never followed, since only the first hop is checked.
 */
final class HttpTimestampAuthority implements TimestampAuthority
{
    /** Content types RFC 3161 section 3.4 defines for the query and the reply. */
    private const QUERY_CONTENT_TYPE = 'application/timestamp-query';

    private const REPLY_CONTENT_TYPE = 'application/timestamp-reply';

    private readonly string $url;

    public function __construct(
        string $url,
        private readonly int $timeout = 15,
        private readonly bool $allowPlaintextHttp = false,
    ) {
        $this->url = trim($url);
    }

    /**
     * @param  array<string, mixed>  $config  The `esign.tsa` configuration array.
     */
    public static function fromConfig(array $config): self
    {
        $url = $config['url'] ?? '';
        $timeout = $config['timeout'] ?? 15;

        return new self(
            url: is_scalar($url) ? (string) $url : '',
            timeout: is_numeric($timeout) ? max(1, (int) $timeout) : 15,
            allowPlaintextHttp: ($config['allow_plaintext_http'] ?? false) === true,
        );
    }

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }

    public function endpoint(): string
    {
        if ($this->url === '') {
            throw new TimestampAuthorityNotConfiguredException(
                'No RFC 3161 timestamp authority is configured (ESIGN_TSA_URL is empty), so PAdES B-T '.
                'cannot be produced. B-T is not downgraded to B-B.'
            );
        }

        return $this->url;
    }

    public function assertUsable(): void
    {
        $this->validatedDestination();
    }

    public function post(string $derRequest): string
    {
        [$url, $host, $port, $address] = $this->validatedDestination();

        if (! function_exists('curl_init')) {
            throw new TimestampAuthorityUnreachableException(
                'The cURL extension is required to reach the timestamp authority.'
            );
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TimestampAuthorityUnreachableException('Unable to initialize the timestamp request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $derRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: '.self::QUERY_CONTENT_TYPE,
                'Accept: '.self::REPLY_CONTENT_TYPE,
                'Expect:',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            // Only the first hop was validated, so a redirect must not be taken.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Pin to the address the policy checked, closing the window between
            // validation and connection in which DNS could answer differently.
            CURLOPT_RESOLVE => [$host.':'.$port.':'.$address],
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $body === true) {
            throw new TimestampAuthorityUnreachableException(
                'The timestamp authority could not be reached'.($error === '' ? '.' : ': '.$error)
            );
        }

        if ($status !== 200) {
            throw new TimestampAuthorityUnreachableException(
                'The timestamp authority answered with HTTP '.$status.'.'
            );
        }

        if ($body === '') {
            throw new TimestampAuthorityUnreachableException('The timestamp authority returned an empty body.');
        }

        // A DER SEQUENCE is the only shape a TimeStampResp can have. Catching an
        // HTML error page here keeps the failure at the transport, where it
        // belongs, instead of surfacing as an ASN.1 parse error.
        if ($body[0] !== "\x30") {
            throw new TimestampAuthorityUnreachableException(
                'The timestamp authority returned a body that is not a DER RFC 3161 response.'
            );
        }

        return $body;
    }

    /**
     * Apply the destination policy and return the parts needed to connect.
     *
     * @return array{string, string, int, string} URL, host, port, validated IP address.
     *
     * @throws TimestampAuthorityNotConfiguredException
     * @throws TimestampAuthorityDestinationException
     */
    private function validatedDestination(): array
    {
        $url = $this->endpoint();

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host']) || ! isset($parts['scheme'])) {
            throw new TimestampAuthorityDestinationException(
                'The configured timestamp authority URL could not be parsed.'
            );
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new TimestampAuthorityDestinationException(
                'The timestamp authority URL must use http or https, not "'.$scheme.'".'
            );
        }

        if ($scheme === 'http' && ! $this->allowPlaintextHttp) {
            throw new TimestampAuthorityDestinationException(
                'The timestamp authority URL uses plaintext HTTP. Set ESIGN_TSA_ALLOW_PLAINTEXT_HTTP=true '.
                'to accept that the document digest travels in the clear.'
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new TimestampAuthorityDestinationException(
                'The timestamp authority URL must not carry credentials.'
            );
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return [$url, $host, $port, $this->resolvePublicAddress($host)];
    }

    /**
     * Resolve a host and require every answer received to be a public address.
     *
     * Every answer, not just the one that would be used: a host resolving to
     * both a public and an internal address is refused outright rather than
     * left to connection ordering.
     *
     * "Received" is the exact guarantee. An AAAA lookup that fails rather than
     * returning nothing — SERVFAIL, a timeout, a resolver that refuses the
     * query type — is indistinguishable from "no AAAA record" through
     * dns_get_record(), and is treated as the latter, because refusing on it
     * would make sealing fail on any network whose resolver filters AAAA. The
     * connection is pinned to the addresses that were checked, so an
     * unvalidated record cannot be reached even if one existed.
     *
     * @throws TimestampAuthorityDestinationException
     */
    private function resolvePublicAddress(string $host): string
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicAddress($literal, $host);

            return $literal;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new TimestampAuthorityDestinationException(
                'The timestamp authority URL does not name a valid host.'
            );
        }

        $addresses = $this->resolveAll($host);
        if ($addresses === []) {
            throw new TimestampAuthorityDestinationException(
                'The timestamp authority host "'.$host.'" does not resolve.'
            );
        }

        foreach ($addresses as $address) {
            $this->assertPublicAddress($address, $host);
        }

        // cURL accepts a comma-separated address list for one host:port, so the
        // pin covers every answer that was checked, not only the first.
        return implode(',', $addresses);
    }

    /**
     * Every A and AAAA answer for a host.
     *
     * @return list<string>
     */
    private function resolveAll(string $host): array
    {
        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ipv6 = $record['ipv6'] ?? null;
                if (is_string($ipv6) && $ipv6 !== '') {
                    $addresses[] = $ipv6;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @throws TimestampAuthorityDestinationException
     */
    private function assertPublicAddress(string $address, string $host): void
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($public === false || $this->isInExtraReservedRange($address)) {
            throw new TimestampAuthorityDestinationException(
                'The timestamp authority host "'.$host.'" resolves to the non-public address '.$address.'.'
            );
        }
    }

    /**
     * Ranges PHP's IP filter flags treat as public but which are not the
     * public internet.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` covers RFC 1918,
     * loopback, link-local, IPv6 unique-local, and IPv4-mapped IPv6. It lets
     * through several ranges that still reach somewhere other than the public
     * internet, and two of those are transition mechanisms that carry an
     * embedded IPv4 address — so a v6 literal can name a v4 destination the v4
     * checks would have refused. Measured, not assumed: before this list,
     * `http://[64:ff9b::7f00:1]/` (NAT64-mapped 127.0.0.1) and
     * `http://[2002:7f00:1::1]/` (6to4-encapsulated 127.0.0.1) both passed the
     * whole policy.
     *
     * A timestamp authority is never inside any of these, so each prefix is
     * refused whole rather than decoded and re-checked.
     */
    private function isInExtraReservedRange(string $address): bool
    {
        // ::ffff:a.b.c.d is the same destination as a.b.c.d, so it is judged as one.
        $candidate = preg_replace('/^::ffff:/i', '', $address) ?? $address;

        $packed = @inet_pton($candidate);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $this->isInAnyPrefix($packed, [
                ['100.64.0.0', 10],   // RFC 6598 carrier-grade NAT
                ['192.0.0.0', 24],    // RFC 6890 IETF protocol assignments
                ['192.88.99.0', 24],  // RFC 7526 6to4 relay anycast
                ['198.18.0.0', 15],   // RFC 2544 benchmarking
            ]);
        }

        return $this->isInAnyPrefix($packed, [
            ['64:ff9b::', 96],    // RFC 6052 NAT64 well-known prefix
            ['64:ff9b:1::', 48],  // RFC 8215 NAT64 local-use prefix
            ['2002::', 16],       // RFC 3056 6to4
            ['2001::', 32],       // RFC 4380 Teredo
            ['100::', 64],        // RFC 6666 discard-only
        ]);
    }

    /**
     * Whether a packed address falls in any of the given prefixes.
     *
     * Compared byte-wise on the packed form, so one routine serves both address
     * families and neither needs 128-bit arithmetic.
     *
     * @param  list<array{string, int}>  $prefixes  Network address and prefix length.
     */
    private function isInAnyPrefix(string $packed, array $prefixes): bool
    {
        foreach ($prefixes as [$network, $bits]) {
            $networkPacked = @inet_pton($network);
            if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }

            $wholeBytes = intdiv($bits, 8);
            if (substr($packed, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
                continue;
            }

            $remainingBits = $bits % 8;
            if ($remainingBits === 0) {
                return true;
            }

            $mask = 0xFF << (8 - $remainingBits) & 0xFF;
            if ((ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
