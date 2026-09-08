<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Webhooks\WebhookTransport;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Fetches, and caches, the certificate an SNS message says it was signed with.
 *
 * This is the one outbound request the verification path makes, to a URL that arrived in an
 * unauthenticated request body, which makes it the most dangerous line in the SES feedback
 * feature. Three things bound it:
 *
 *  1. **The URL is judged before a packet leaves.** `Aws\Sns\MessageValidator` has already
 *     required HTTPS, a `.pem` suffix, and a host matching the SNS pattern before it calls
 *     in here — so a non-AWS host or a plaintext URL never reaches this class at all. Then
 *     the shared DestinationPolicy adds what a name pin cannot: it refuses a host that
 *     *resolves* to a loopback, private, link-local, or reserved address, and it returns the
 *     addresses it checked so the connection is pinned to them. A poisoned answer for a
 *     genuine AWS name does not become a route to an internal service.
 *  2. **Redirects are never followed**, because only the first hop was validated. The same
 *     transport options the webhook outbox and the timestamp authority use.
 *  3. **Answers are cached by URL with a bounded TTL.** SNS publishes thousands of
 *     notifications for one certificate; without a cache, a notification flood is also a
 *     certificate-fetch flood, and the amplification factor is chosen by whoever is sending
 *     the flood.
 *
 * Only a successful fetch is cached. Caching a failure would turn one bad minute at AWS into
 * a TTL of refused feedback, and a refusal is cheap: it costs a DNS lookup and a connection,
 * both bounded by the route's own rate limit.
 */
final class SnsSigningCertificates
{
    /**
     * An X.509 certificate in PEM is about 1–2 KB. This is not a size limit for correctness,
     * it is a limit on what an attacker who can answer for an AWS name can push into the
     * cache store.
     */
    private const MAX_CERTIFICATE_BYTES = 16384;

    /** Seconds. Fetching a certificate is one small GET, inside a webhook request. */
    private const FETCH_TIMEOUT = 5;

    private const CONNECT_TIMEOUT = 3;

    private const CACHE_PREFIX = 'esign:sns:signing-certificate:';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly DestinationPolicy $destinations,
        private readonly CacheRepository $cache,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * @throws SnsVerificationException When the URL is refused, unreachable, or does not
     *                                  answer with something PEM-shaped.
     */
    public function fetch(string $url): string
    {
        $key = self::CACHE_PREFIX.hash('sha256', $url);

        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $certificate = $this->download($url);

        $this->cache->put($key, $certificate, $this->ttlSeconds);

        return $certificate;
    }

    /**
     * @throws SnsVerificationException
     */
    private function download(string $url): string
    {
        try {
            $destination = $this->destinations->for('SNS signing certificate')->validate($url);
        } catch (DestinationRefusedException) {
            throw new SnsVerificationException(
                'The SNS signing certificate URL was refused by the destination policy.',
                'certificate_destination_refused',
            );
        }

        try {
            $response = $this->http
                ->timeout(self::FETCH_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->withOptions(WebhookTransport::transportOptions($destination))
                ->get($destination->url);
        } catch (Throwable) {
            // Connection errors included: an unreachable AWS is a refusal here, and the
            // controller turns a refusal into a retryable 503.
            throw new SnsVerificationException(
                'The SNS signing certificate could not be fetched.',
                'certificate_unreachable',
            );
        }

        if (! $response->successful()) {
            throw new SnsVerificationException(
                'The SNS signing certificate could not be fetched.',
                'certificate_unreachable',
            );
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_CERTIFICATE_BYTES || ! str_contains($body, '-----BEGIN CERTIFICATE-----')) {
            throw new SnsVerificationException(
                'The SNS signing certificate URL did not answer with a PEM certificate.',
                'certificate_malformed',
            );
        }

        return $body;
    }
}
