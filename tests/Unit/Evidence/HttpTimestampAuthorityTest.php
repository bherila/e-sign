<?php

declare(strict_types=1);

namespace Tests\Unit\Evidence;

use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityDestinationException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use App\Domain\Evidence\Sealing\HttpTimestampAuthority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The destination policy on the configured TSA endpoint.
 *
 * The TSA URL is operator configuration that a queue worker dereferences, so
 * without a policy it is an SSRF primitive. Every case here is refused before a
 * packet leaves the process, which is why none of these tests need the network.
 */
final class HttpTimestampAuthorityTest extends TestCase
{
    public function test_an_empty_url_means_the_deployment_cannot_produce_b_t(): void
    {
        $authority = new HttpTimestampAuthority('');

        $this->assertFalse($authority->isConfigured());

        $this->expectException(TimestampAuthorityNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/not downgraded/');

        $authority->endpoint();
    }

    public function test_a_configured_public_https_url_passes_the_policy(): void
    {
        // No DNS and no connection: an IP literal in documentation space
        // (RFC 5737 TEST-NET-3) is public as far as the policy is concerned.
        $authority = new HttpTimestampAuthority('https://203.0.113.10/tsr');

        $this->assertTrue($authority->isConfigured());
        $authority->assertUsable();

        $this->assertSame('https://203.0.113.10/tsr', $authority->endpoint());
    }

    #[DataProvider('refusedDestinations')]
    public function test_it_refuses_a_destination_the_policy_disallows(string $url, string $expected): void
    {
        $this->expectException(TimestampAuthorityDestinationException::class);
        $this->expectExceptionMessageMatches($expected);

        (new HttpTimestampAuthority($url))->assertUsable();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedDestinations(): array
    {
        return [
            'plaintext http without the opt-in' => ['http://203.0.113.10/tsr', '/plaintext HTTP/'],
            'a non-http scheme' => ['ftp://203.0.113.10/tsr', '/must use http or https/'],
            'a file url' => ['file:///etc/passwd', '/could not be parsed|must use http or https/'],
            'credentials in the url' => ['https://user:pass@203.0.113.10/tsr', '/must not carry credentials/'],
            'loopback' => ['https://127.0.0.1/tsr', '/non-public address/'],
            'the rfc 1918 private range' => ['https://10.1.2.3/tsr', '/non-public address/'],
            'the carrier private range' => ['https://192.168.1.1/tsr', '/non-public address/'],
            'link-local metadata' => ['https://169.254.169.254/latest', '/non-public address/'],
            'ipv6 loopback' => ['https://[::1]/tsr', '/non-public address/'],
            'an ipv6 unique local address' => ['https://[fc00::1]/tsr', '/non-public address/'],
            // PHP's filter flags let these through; the extra-range check does not.
            'carrier-grade nat' => ['https://100.64.1.2/tsr', '/non-public address/'],
            'ietf protocol assignments' => ['https://192.0.0.170/tsr', '/non-public address/'],
            'benchmarking space' => ['https://198.19.1.1/tsr', '/non-public address/'],
            'loopback as ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/tsr', '/non-public address/'],
            'carrier-grade nat as ipv4-mapped ipv6' => [
                'https://[::ffff:100.64.1.2]/tsr',
                '/non-public address/',
            ],
            'a host that does not resolve' => [
                'https://tsa.invalid/tsr',
                '/does not resolve|non-public address/',
            ],
        ];
    }

    public function test_a_public_ipv6_literal_passes_the_policy(): void
    {
        // RFC 3849 documentation space: a global-scope address the policy has no
        // reason to refuse, so the extra-range check must not over-reach.
        (new HttpTimestampAuthority('https://[2001:db8::1]/tsr'))->assertUsable();

        $this->assertTrue(true);
    }

    public function test_plaintext_http_is_accepted_only_with_the_explicit_opt_in(): void
    {
        $authority = new HttpTimestampAuthority('http://203.0.113.10/tsr', allowPlaintextHttp: true);

        $authority->assertUsable();

        $this->assertSame('http://203.0.113.10/tsr', $authority->endpoint());
    }

    public function test_it_reads_its_settings_from_the_esign_tsa_config_shape(): void
    {
        $authority = HttpTimestampAuthority::fromConfig([
            'url' => ' https://203.0.113.10/tsr ',
            'timeout' => '30',
            'allow_plaintext_http' => false,
        ]);

        $this->assertSame('https://203.0.113.10/tsr', $authority->endpoint());
    }
}
