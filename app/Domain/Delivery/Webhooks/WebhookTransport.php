<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Outbound\ValidatedDestination;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Puts one signed request on the wire, under the destination policy.
 *
 * The destination is re-validated on every attempt rather than once when the
 * endpoint is created: a hostname that answered with a public address at
 * creation time can answer with 169.254.169.254 an hour later, and the
 * connection is pinned to the addresses this attempt checked so the answer
 * cannot change again between the check and the connection.
 */
final class WebhookTransport
{
    private readonly DestinationPolicy $policy;

    public function __construct(DestinationPolicy $policy)
    {
        $this->policy = $policy->for('webhook endpoint');
    }

    /**
     * @throws DestinationRefusedException
     */
    public function validate(string $url): ValidatedDestination
    {
        return $this->policy->validate($url);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws ConnectionException on timeout or a lost response.
     */
    public function send(
        ValidatedDestination $destination,
        array $headers,
        string $body,
        int $timeout,
        int $connectTimeout,
    ): Response {
        self::assertPinningIsAvailable();

        return Http::withHeaders($headers)
            ->withBody($body, 'application/json')
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withOptions(self::transportOptions($destination))
            ->post($destination->url);
    }

    /**
     * Transport options that make the policy's decision stick.
     *
     * Redirects are never followed: only the first hop was validated, so a 302
     * to the metadata service would walk straight past every check above. The
     * cURL resolve entry pins host:port to the addresses that were checked,
     * which is what closes the DNS-rebinding window.
     *
     * @return array<string, mixed>
     */
    public static function transportOptions(ValidatedDestination $destination): array
    {
        self::assertPinningIsAvailable();

        return [
            'allow_redirects' => false,
            'verify' => true,
            'curl' => [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS_STR => 'https,http',
                CURLOPT_RESOLVE => [$destination->curlResolveEntry()],
            ],
        ];
    }

    /**
     * Refuse to send at all unless the pinning above can actually take effect.
     *
     * Address pinning exists only as cURL handler options, and Guzzle applies the `curl` key
     * only when the cURL handler is selected. Without `ext-curl` Guzzle silently falls back
     * to its stream handler, which ignores that array entirely: `allow_redirects` and
     * `verify` still hold, but `CURLOPT_RESOLVE` and `CURLOPT_PROTOCOLS_STR` vanish and the
     * DNS-rebinding window between `validate()` and the connection reopens — with no error
     * and no test failure (docs/security/review-2026-09.md finding D-3).
     *
     * `HttpTimestampAuthority` has always failed closed on the same condition. This is the
     * same refusal for the same reason, and `composer.json` now requires the extension so a
     * deployment finds out at install time rather than at delivery time.
     *
     * @throws RuntimeException
     */
    private static function assertPinningIsAvailable(): void
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException(
                'Webhook delivery requires ext-curl: without it the HTTP client cannot pin the '
                .'connection to the addresses the destination policy validated, and delivery '
                .'would proceed with an unvalidated DNS answer.',
            );
        }
    }
}
