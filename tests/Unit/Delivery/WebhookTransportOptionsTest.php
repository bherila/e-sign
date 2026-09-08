<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Outbound\ValidatedDestination;
use App\Domain\Delivery\Webhooks\WebhookTransport;
use PHPUnit\Framework\TestCase;

/**
 * The transport options are where the policy's decision either sticks or
 * quietly stops mattering: a followed redirect walks past every check, and an
 * unpinned connection re-resolves the host after it was validated.
 */
final class WebhookTransportOptionsTest extends TestCase
{
    public function test_redirects_are_refused_at_every_layer(): void
    {
        $options = WebhookTransport::transportOptions($this->destination());

        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['curl'][CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $options['curl'][CURLOPT_MAXREDIRS]);
    }

    public function test_the_connection_is_pinned_to_the_addresses_that_were_checked(): void
    {
        $options = WebhookTransport::transportOptions($this->destination());

        $this->assertSame(
            ['hooks.example.test:443:203.0.113.10,203.0.113.11'],
            $options['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_tls_verification_is_on_and_the_protocol_set_is_narrow(): void
    {
        $options = WebhookTransport::transportOptions($this->destination());

        $this->assertTrue($options['verify']);
        $this->assertSame('https,http', $options['curl'][CURLOPT_PROTOCOLS_STR]);
    }

    private function destination(): ValidatedDestination
    {
        return new ValidatedDestination(
            url: 'https://hooks.example.test/inbox',
            scheme: 'https',
            host: 'hooks.example.test',
            port: 443,
            addresses: ['203.0.113.10', '203.0.113.11'],
        );
    }
}
