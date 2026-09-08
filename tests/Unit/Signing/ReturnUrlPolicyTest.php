<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Signing\Sessions\ReturnUrlPolicy;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a signer may be sent afterwards.
 *
 * docs/HANDOFF.md section 8: "Validate callback/return destinations rather than reflecting
 * arbitrary URLs." The failure this prevents is an open redirect on the one page in the
 * product that has to be trusted while somebody executes a contract.
 *
 * Every refusal returns null rather than an error, and the tests assert that rather than an
 * exception: a distinguishable rejection would turn the parameter into an oracle for the
 * allowlist's contents, and would show a signer a validation failure about somebody else's
 * integration immediately after they signed.
 */
class ReturnUrlPolicyTest extends TestCase
{
    public function test_a_listed_host_is_kept_intact(): void
    {
        $this->assertSame(
            'https://consumer.example.test/done?ref=7#section',
            $this->policy()->sanitize('https://consumer.example.test/done?ref=7#section'),
        );
    }

    public function test_the_host_comparison_is_case_insensitive(): void
    {
        $this->assertSame(
            'https://CONSUMER.Example.Test/done',
            $this->policy()->sanitize('https://CONSUMER.Example.Test/done'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusals(): array
    {
        return [
            'an unlisted host' => ['https://attacker.example.test/steal'],
            'a subdomain of a listed host' => ['https://evil.consumer.example.test/steal'],
            'a listed host as a subdomain of another' => ['https://consumer.example.test.attacker.test/'],
            'credentials in the authority' => ['https://consumer.example.test@attacker.example.test/'],
            'a javascript URL' => ['javascript:alert(1)'],
            'a data URL' => ['data:text/html,<script>alert(1)</script>'],
            'a relative path' => ['/dashboard'],
            'a protocol-relative URL' => ['//consumer.example.test/done'],
            'an empty string' => [''],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses(string $candidate): void
    {
        $this->assertNull($this->policy()->sanitize($candidate));
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull($this->policy()->sanitize(null));
    }

    public function test_an_absurdly_long_destination_is_refused(): void
    {
        $long = 'https://consumer.example.test/'.str_repeat('a', ReturnUrlPolicy::MAX_LENGTH);

        $this->assertNull($this->policy()->sanitize($long));
    }

    public function test_an_empty_allowlist_honours_nothing(): void
    {
        $policy = new ReturnUrlPolicy(new Repository(['esign' => ['signing' => ['return_url_allowlist' => []]]]));

        $this->assertNull($policy->sanitize('https://consumer.example.test/done'));
    }

    public function test_plain_http_is_allowed_only_for_a_listed_host(): void
    {
        // http is permitted because an internal consumer on a private network is a real
        // deployment; the allowlist, not the scheme, is what keeps it narrow.
        $this->assertSame('http://consumer.example.test/done', $this->policy()->sanitize('http://consumer.example.test/done'));
        $this->assertNull($this->policy()->sanitize('http://elsewhere.example.test/done'));
    }

    private function policy(): ReturnUrlPolicy
    {
        return new ReturnUrlPolicy(new Repository([
            'esign' => ['signing' => ['return_url_allowlist' => ['consumer.example.test']]],
        ]));
    }
}
