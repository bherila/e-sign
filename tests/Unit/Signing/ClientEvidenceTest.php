<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Signing\Fields\ClientEvidence;
use PHPUnit\Framework\TestCase;

/**
 * An allowlist, not a scrubber: an allowlist only has to be right about what it keeps.
 */
class ClientEvidenceTest extends TestCase
{
    public function test_it_keeps_only_the_declared_keys(): void
    {
        $minimized = ClientEvidence::minimize([
            'ip' => '198.51.100.7',
            'user_agent' => 'SyntheticBrowser/1.0',
            'accept_language' => 'en-GB',
            'client_timezone' => 'Europe/London',
            'channel' => 'guest_signing_page',
            'cookie' => 'session=secret',
            'authorization' => 'Bearer secret',
            'session_id' => 'abc123',
            'canvas_fingerprint' => 'deadbeef',
        ]);

        $this->assertSame([
            'ip' => '198.51.100.7',
            'user_agent' => 'SyntheticBrowser/1.0',
            'accept_language' => 'en-GB',
            'client_timezone' => 'Europe/London',
            'channel' => 'guest_signing_page',
        ], $minimized);
    }

    public function test_the_output_order_follows_the_allowlist_not_the_input(): void
    {
        $this->assertSame(
            ['ip', 'user_agent'],
            array_keys(ClientEvidence::minimize([
                'user_agent' => 'SyntheticBrowser/1.0',
                'ip' => '198.51.100.7',
            ])),
        );
    }

    public function test_it_drops_structures_and_keeps_scalars(): void
    {
        $minimized = ClientEvidence::minimize([
            'user_agent' => ['nested', 'array'],
            'channel' => 42,
        ]);

        $this->assertSame(['channel' => '42'], $minimized);
    }

    public function test_it_drops_empty_values_rather_than_recording_them(): void
    {
        $this->assertSame([], ClientEvidence::minimize(['ip' => '', 'user_agent' => '   ']));
    }

    public function test_it_bounds_the_length_of_what_it_keeps(): void
    {
        $minimized = ClientEvidence::minimize(['user_agent' => str_repeat('a', 5_000)]);

        $this->assertSame(ClientEvidence::MAX_LENGTH, mb_strlen($minimized['user_agent']));
    }
}
