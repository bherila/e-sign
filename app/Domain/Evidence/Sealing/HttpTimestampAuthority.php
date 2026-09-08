<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Outbound\ValidatedDestination;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityDestinationException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityUnreachableException;

/**
 * RFC 3161 transport over cURL, behind the shared outbound destination policy.
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
 *
 * Those rules live in App\Domain\Delivery\Outbound\DestinationPolicy, because
 * webhook delivery needs exactly the same ones against a URL a tenant supplies.
 * This class is the RFC 3161 transport and the TSA-specific wording of the
 * refusals; the policy is what decides them.
 */
final class HttpTimestampAuthority implements TimestampAuthority
{
    /** Content types RFC 3161 section 3.4 defines for the query and the reply. */
    private const QUERY_CONTENT_TYPE = 'application/timestamp-query';

    private const REPLY_CONTENT_TYPE = 'application/timestamp-reply';

    private readonly string $url;

    private readonly DestinationPolicy $policy;

    public function __construct(
        string $url,
        private readonly int $timeout = 15,
        bool $allowPlaintextHttp = false,
        ?DestinationPolicy $policy = null,
    ) {
        $this->url = trim($url);
        $this->policy = ($policy ?? new DestinationPolicy)->for(
            subject: 'timestamp authority',
            plaintextHint: 'Set ESIGN_TSA_ALLOW_PLAINTEXT_HTTP=true to accept that the document '.
                'digest travels in the clear.',
            allowPlaintextHttp: $allowPlaintextHttp,
        );
    }

    /**
     * @param  array<string, mixed>  $config  The `esign.tsa` configuration array.
     */
    public static function fromConfig(array $config, ?DestinationPolicy $policy = null): self
    {
        $url = $config['url'] ?? '';
        $timeout = $config['timeout'] ?? 15;

        return new self(
            url: is_scalar($url) ? (string) $url : '',
            timeout: is_numeric($timeout) ? max(1, (int) $timeout) : 15,
            allowPlaintextHttp: ($config['allow_plaintext_http'] ?? false) === true,
            policy: $policy,
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
        $destination = $this->validatedDestination();

        if (! function_exists('curl_init')) {
            throw new TimestampAuthorityUnreachableException(
                'The cURL extension is required to reach the timestamp authority.'
            );
        }

        $handle = curl_init($destination->url);
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
            CURLOPT_RESOLVE => [$destination->curlResolveEntry()],
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
     * Apply the shared destination policy to the configured endpoint.
     *
     * The refusal is re-thrown as a sealing exception so callers keep catching
     * one exception family; the message, which operators read, is unchanged.
     *
     * @throws TimestampAuthorityNotConfiguredException
     * @throws TimestampAuthorityDestinationException
     */
    private function validatedDestination(): ValidatedDestination
    {
        try {
            return $this->policy->validate($this->endpoint());
        } catch (DestinationRefusedException $refusal) {
            throw new TimestampAuthorityDestinationException($refusal->getMessage(), 0, $refusal);
        }
    }
}
