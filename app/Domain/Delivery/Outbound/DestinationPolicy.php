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
 * carrier-grade-NAT, or otherwise reserved address, and hands back the
 * addresses it checked so the caller can pin the connection to them. Redirects
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
     * Resolve a host and require every answer to be reachable under the policy.
     *
     * Every answer, not just the one that would be used: a host that resolves
     * to both a public and an internal address is refused outright rather than
     * left to connection ordering.
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
     * loopback, link-local, IPv6 ULA, and IPv4-mapped IPv6, but it lets through
     * RFC 6598 carrier-grade NAT — which is exactly the address space a shared
     * host's internal network sits in — and RFC 6890 IETF protocol assignments.
     */
    private function isInExtraReservedRange(string $address): bool
    {
        // ::ffff:a.b.c.d is the same destination as a.b.c.d, so it is judged as one.
        $candidate = preg_replace('/^::ffff:/i', '', $address) ?? $address;

        $packed = @inet_pton($candidate);
        if ($packed === false || strlen($packed) !== 4) {
            return false;
        }

        $value = unpack('N', $packed);
        if ($value === false) {
            return false;
        }

        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$network, $bits]) {
            $mask = -1 << (32 - $bits) & 0xFFFFFFFF;
            if (($value[1] & $mask) === (ip2long($network) & $mask)) {
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
