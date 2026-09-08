<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Outbound;

use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;

/**
 * The policy every outbound request to an operator- or tenant-supplied URL
 * passes before a packet leaves the process.
 *
 * Without it, any stored URL that a queue worker dereferences is an SSRF
 * primitive: webhook endpoints, the RFC 3161 timestamp authority, and any
 * future remote import all point somewhere a caller chose. The policy refuses
 * anything but HTTP(S), refuses plaintext HTTP, refuses credentials in the
 * URL, refuses a host that resolves to a loopback, private, link-local,
 * carrier-grade-NAT, IPv6-transition, or otherwise reserved address, and hands
 * back the addresses it checked so the caller can pin the connection to them. Redirects
 * are the caller's responsibility to disable, since only the first hop is
 * checked here.
 *
 * The single way past the private-address and plaintext refusals is the
 * administrator allowlist in `config('esign.delivery.destination_allowlist')`.
 * There is no caller-controlled bypass, by construction: nothing on this class
 * takes a "trusted" or "internal" flag from the request path.
 */
final class DestinationPolicy
{
    public function __construct(
        private readonly HostResolver $resolver = new SystemHostResolver,
        private readonly DestinationAllowlist $allowlist = new DestinationAllowlist,
        private readonly bool $allowPlaintextHttp = false,
        private readonly string $subject = 'destination',
        private readonly string $plaintextHint = '',
    ) {}

    /**
     * A copy of this policy that describes a different subject in its errors.
     *
     * Message wording is part of what operators read in a health probe or a
     * delivery error, so each consumer names itself. A consumer with its own
     * documented plaintext opt-in (the timestamp authority has one, because
     * several public RFC 3161 endpoints are HTTP-only) passes it here; it
     * widens nothing else.
     */
    public function for(string $subject, string $plaintextHint = '', ?bool $allowPlaintextHttp = null): self
    {
        return new self(
            resolver: $this->resolver,
            allowlist: $this->allowlist,
            allowPlaintextHttp: $allowPlaintextHttp ?? $this->allowPlaintextHttp,
            subject: $subject,
            plaintextHint: $plaintextHint,
        );
    }

    /**
     * @throws DestinationRefusedException
     */
    public function validate(string $url): ValidatedDestination
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || ! isset($parts['scheme'])) {
            throw $this->refuse('URL could not be parsed.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw $this->refuse('URL must use http or https, not "'.$scheme.'".');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw $this->refuse('URL must not carry credentials.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($scheme === 'http' && ! $this->plaintextIsPermitted($host)) {
            throw $this->refuse(
                'URL uses plaintext HTTP.'.($this->plaintextHint === '' ? '' : ' '.$this->plaintextHint)
            );
        }

        return new ValidatedDestination(
            url: $url,
            scheme: $scheme,
            host: $host,
            port: $port,
            addresses: $this->resolveReachableAddresses($host),
        );
    }

    private function plaintextIsPermitted(string $host): bool
    {
        return $this->allowPlaintextHttp || $this->allowlist->permitsPlaintextFor($host);
    }

    /**
     * Resolve a host and require every answer received to be reachable.
     *
     * Every answer, not just the one that would be used: a host resolving to
     * both a public and an internal address is refused outright rather than
     * left to connection ordering.
     *
     * "Received" is the exact guarantee. An AAAA lookup that fails rather than
     * returning nothing — SERVFAIL, a timeout, a resolver that refuses the
     * query type — is indistinguishable from "no AAAA record" through the
     * resolver, and is treated as the latter, because refusing on it would
     * break delivery on any network whose resolver filters AAAA. The caller
     * pins the connection to the addresses that were checked, so an
     * unvalidated record cannot be reached even if one existed.
     *
     * @return list<string>
     *
     * @throws DestinationRefusedException
     */
    private function resolveReachableAddresses(string $host): array
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $this->assertReachableAddress($literal, $host);

            return [$literal];
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw $this->refuse('URL does not name a valid host.');
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw $this->refuse('host "'.$host.'" does not resolve.');
        }

        foreach ($addresses as $address) {
            $this->assertReachableAddress($address, $host);
        }

        return array_values($addresses);
    }

    /**
     * @throws DestinationRefusedException
     */
    private function assertReachableAddress(string $address, string $host): void
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($public !== false && ! $this->isInExtraReservedRange($address)) {
            return;
        }

        if ($this->allowlist->permitsPrivateAddress($host, $address)) {
            return;
        }

        throw $this->refuse('host "'.$host.'" resolves to the non-public address '.$address.'.');
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
     * Neither a timestamp authority nor a webhook receiver is ever inside any
     * of these, so each prefix is refused whole rather than decoded and
     * re-checked. An administrator allowlist entry is the only way past one,
     * and it names a host or a CIDR explicitly.
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

    private function refuse(string $detail): DestinationRefusedException
    {
        return new DestinationRefusedException('The '.$this->subject.' '.$detail);
    }
}
