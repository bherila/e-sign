<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Health\CidrMatcher;
use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    public function test_matches_loopback_v4(): void
    {
        $this->assertTrue(CidrMatcher::matches('127.0.0.1', '127.0.0.1/32'));
        $this->assertFalse(CidrMatcher::matches('127.0.0.2', '127.0.0.1/32'));
    }

    public function test_matches_a_v4_subnet(): void
    {
        $this->assertTrue(CidrMatcher::matches('10.0.5.42', '10.0.0.0/8'));
        $this->assertFalse(CidrMatcher::matches('11.0.5.42', '10.0.0.0/8'));
    }

    public function test_matches_loopback_v6(): void
    {
        $this->assertTrue(CidrMatcher::matches('::1', '::1/128'));
        $this->assertFalse(CidrMatcher::matches('::2', '::1/128'));
    }

    public function test_never_matches_across_address_families(): void
    {
        $this->assertFalse(CidrMatcher::matches('127.0.0.1', '::1/128'));
        $this->assertFalse(CidrMatcher::matches('::1', '127.0.0.1/32'));
    }

    public function test_matches_any_checks_every_candidate(): void
    {
        $this->assertTrue(CidrMatcher::matchesAny('127.0.0.1', ['10.0.0.0/8', '127.0.0.1/32']));
        $this->assertFalse(CidrMatcher::matchesAny('192.168.1.1', ['10.0.0.0/8', '127.0.0.1/32']));
    }

    public function test_rejects_malformed_input(): void
    {
        $this->assertFalse(CidrMatcher::matches('not-an-ip', '127.0.0.1/32'));
        $this->assertFalse(CidrMatcher::matches('127.0.0.1', 'not-a-cidr/32'));
    }
}
