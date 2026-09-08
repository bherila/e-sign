<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Outbound\AllowlistEntry;
use App\Domain\Delivery\Outbound\DestinationAllowlist;
use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHostResolver;

/**
 * The outbound destination policy, which is the only thing standing between a
 * tenant-supplied webhook URL and the instance's own network.
 *
 * Every case here is decided before a packet leaves the process, so none of
 * these tests need the network — and the resolver is faked, so none of them
 * depend on what the machine running them can look up.
 */
final class DestinationPolicyTest extends TestCase
{
    public function test_a_public_https_destination_passes_and_reports_its_addresses(): void
    {
        $destination = $this->policy(['hooks.example.test' => ['203.0.113.10', '203.0.113.11']])
            ->validate('https://hooks.example.test/inbox');

        $this->assertSame('hooks.example.test', $destination->host);
        $this->assertSame(443, $destination->port);
        $this->assertSame(['203.0.113.10', '203.0.113.11'], $destination->addresses);
        $this->assertSame(
            'hooks.example.test:443:203.0.113.10,203.0.113.11',
            $destination->curlResolveEntry(),
        );
    }

    #[DataProvider('refusedLiterals')]
    public function test_it_refuses_an_internal_address_literal(string $url, string $expected): void
    {
        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches($expected);

        $this->policy()->validate($url);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedLiterals(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/inbox', '/non-public address/'],
            'private rfc 1918' => ['https://10.1.2.3/inbox', '/non-public address/'],
            'private 192.168' => ['https://192.168.1.1/inbox', '/non-public address/'],
            'link-local' => ['https://169.254.1.1/inbox', '/non-public address/'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/', '/non-public address/'],
            'ipv6 loopback' => ['https://[::1]/inbox', '/non-public address/'],
            'ipv6 unique local' => ['https://[fc00::1]/inbox', '/non-public address/'],
            'carrier-grade nat' => ['https://100.64.1.2/inbox', '/non-public address/'],
            'ipv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/inbox', '/non-public address/'],
            // IPv6 transition mechanisms carry an embedded IPv4 address, so a
            // v6 literal can name a v4 destination the v4 checks refuse.
            'nat64-mapped loopback' => ['https://[64:ff9b::7f00:1]/inbox', '/non-public address/'],
            'nat64-mapped rfc 1918' => ['https://[64:ff9b::a00:1]/inbox', '/non-public address/'],
            'nat64 local-use prefix' => ['https://[64:ff9b:1::1]/inbox', '/non-public address/'],
            '6to4 encapsulating loopback' => ['https://[2002:7f00:1::1]/inbox', '/non-public address/'],
            '6to4 relay anycast' => ['https://192.88.99.1/inbox', '/non-public address/'],
            'teredo' => ['https://[2001:0:1:2:3:4:5:6]/inbox', '/non-public address/'],
            'discard-only' => ['https://[100::1]/inbox', '/non-public address/'],
            'ietf protocol assignments' => ['https://192.0.0.170/inbox', '/non-public address/'],
            // docs/security/review-2026-09.md finding D-1. The sibling of the IPv4-*mapped*
            // prefix PHP already treats as reserved; behind a SIIT/NAT64 translator this is
            // 127.0.0.1, and it passed the whole policy.
            'ipv4-translated loopback' => ['https://[::ffff:0:7f00:1]/inbox', '/non-public address/'],
            'ipv4-translated rfc 1918' => ['https://[::ffff:0:a00:1]/inbox', '/non-public address/'],
            'multicast' => ['https://224.0.0.1/inbox', '/non-public address/'],
            'benchmarking space' => ['https://198.19.1.1/inbox', '/non-public address/'],
            'credentials in the url' => ['https://user:pass@203.0.113.10/inbox', '/must not carry credentials/'],
            'a non-http scheme' => ['gopher://203.0.113.10/inbox', '/must use http or https/'],
            'a file url' => ['file:///etc/passwd', '/could not be parsed|must use http or https/'],
        ];
    }

    /**
     * The extra-range list must not over-reach.
     *
     * Each address here sits immediately outside a refused prefix, or inside a
     * /32 that merely shares a first hextet with Teredo. A policy that refused
     * any of them would break ordinary public receivers.
     */
    #[DataProvider('allowedLiterals')]
    public function test_it_allows_a_public_destination(string $url): void
    {
        $this->policy()->validate($url);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedLiterals(): array
    {
        return [
            'documentation ipv4' => ['https://203.0.113.10/inbox'],
            'documentation ipv6' => ['https://[2001:db8::1]/inbox'],
            'a global-scope ipv6 address' => ['https://[2606:4700::1]/inbox'],
            'one below carrier-grade nat' => ['https://100.63.255.255/inbox'],
            'one above carrier-grade nat' => ['https://100.128.0.0/inbox'],
            'one above the ietf protocol block' => ['https://192.0.1.0/inbox'],
            'one above benchmarking space' => ['https://198.20.0.0/inbox'],
            'one above the 6to4 relay block' => ['https://192.88.100.0/inbox'],
        ];
    }

    public function test_an_allowlist_entry_can_admit_an_otherwise_refused_prefix(): void
    {
        // The transition prefixes are refused whole rather than decoded, so the
        // only way to a destination inside one is an administrator naming it.
        $policy = new DestinationPolicy(
            resolver: new FakeHostResolver(['nat64.internal.test' => ['64:ff9b::cb00:7110']]),
            allowlist: new DestinationAllowlist([
                new AllowlistEntry('nat64.internal.test', allowPrivate: true),
            ]),
        );

        $this->assertSame(['64:ff9b::cb00:7110'], $policy->validate('https://nat64.internal.test/inbox')->addresses);
    }

    public function test_it_refuses_a_host_whose_dns_answer_is_private(): void
    {
        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches('/resolves to the non-public address 10\.0\.0\.7/');

        $this->policy(['rebind.example.test' => ['10.0.0.7']])
            ->validate('https://rebind.example.test/inbox');
    }

    public function test_it_refuses_a_host_that_answers_with_a_public_and_a_private_address(): void
    {
        // Refusing outright rather than picking the public answer: which address
        // a connection would use is not something this process decides.
        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches('/non-public address 192\.168\.5\.5/');

        $this->policy(['split.example.test' => ['203.0.113.10', '192.168.5.5']])
            ->validate('https://split.example.test/inbox');
    }

    public function test_it_refuses_a_host_that_does_not_resolve(): void
    {
        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches('/does not resolve/');

        $this->policy()->validate('https://nowhere.example.test/inbox');
    }

    public function test_it_refuses_plaintext_http_by_default(): void
    {
        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches('/plaintext HTTP/');

        $this->policy(['hooks.example.test' => ['203.0.113.10']])
            ->validate('http://hooks.example.test/inbox');
    }

    public function test_an_administrator_allowlist_entry_admits_one_named_internal_consumer(): void
    {
        $policy = new DestinationPolicy(
            resolver: new FakeHostResolver(['consumer.internal.test' => ['10.8.0.9']]),
            allowlist: new DestinationAllowlist([
                new AllowlistEntry('consumer.internal.test', allowPrivate: true, allowPlaintext: true),
            ]),
        );

        $destination = $policy->validate('http://consumer.internal.test:8080/hooks');

        $this->assertSame(['10.8.0.9'], $destination->addresses);
        $this->assertSame('consumer.internal.test:8080:10.8.0.9', $destination->curlResolveEntry());
    }

    public function test_an_allowlist_entry_admits_only_the_host_it_names(): void
    {
        $policy = new DestinationPolicy(
            resolver: new FakeHostResolver([
                'consumer.internal.test' => ['10.8.0.9'],
                'other.internal.test' => ['10.8.0.10'],
            ]),
            allowlist: new DestinationAllowlist([
                new AllowlistEntry('consumer.internal.test', allowPrivate: true),
            ]),
        );

        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches('/other\.internal\.test/');

        $policy->validate('https://other.internal.test/hooks');
    }

    public function test_a_cidr_allowlist_entry_admits_the_addresses_inside_it(): void
    {
        $policy = new DestinationPolicy(
            resolver: new FakeHostResolver(['vpn.internal.test' => ['10.8.0.20']]),
            allowlist: new DestinationAllowlist([
                new AllowlistEntry('10.8.0.0/24', allowPrivate: true),
            ]),
        );

        $this->assertSame(['10.8.0.20'], $policy->validate('https://vpn.internal.test/hooks')->addresses);
    }

    public function test_a_cidr_allowlist_entry_does_not_stretch_past_its_prefix(): void
    {
        $policy = new DestinationPolicy(
            resolver: new FakeHostResolver(['elsewhere.internal.test' => ['10.9.0.20']]),
            allowlist: new DestinationAllowlist([
                new AllowlistEntry('10.8.0.0/24', allowPrivate: true),
            ]),
        );

        $this->expectException(DestinationRefusedException::class);

        $policy->validate('https://elsewhere.internal.test/hooks');
    }

    public function test_an_allowlist_entry_grants_only_what_it_says(): void
    {
        // allow_private without allow_plaintext: the private address is fine,
        // the cleartext body is not.
        $policy = new DestinationPolicy(
            resolver: new FakeHostResolver(['consumer.internal.test' => ['10.8.0.9']]),
            allowlist: new DestinationAllowlist([
                new AllowlistEntry('consumer.internal.test', allowPrivate: true),
            ]),
        );

        $policy->validate('https://consumer.internal.test/hooks');

        $this->expectException(DestinationRefusedException::class);
        $this->expectExceptionMessageMatches('/plaintext HTTP/');

        $policy->validate('http://consumer.internal.test/hooks');
    }

    public function test_the_environment_form_parses_into_entries(): void
    {
        $entries = DestinationAllowlist::parseEnvironment(
            'consumer.internal.test|private|plaintext, 10.8.0.0/24|private , junk|'
        );

        $this->assertSame([
            ['value' => 'consumer.internal.test', 'allow_private' => true, 'allow_plaintext' => true],
            ['value' => '10.8.0.0/24', 'allow_private' => true, 'allow_plaintext' => false],
            ['value' => 'junk', 'allow_private' => false, 'allow_plaintext' => false],
        ], $entries);
    }

    /**
     * @param  array<string, list<string>>  $answers
     */
    private function policy(array $answers = []): DestinationPolicy
    {
        return new DestinationPolicy(resolver: new FakeHostResolver($answers), subject: 'webhook endpoint');
    }
}
