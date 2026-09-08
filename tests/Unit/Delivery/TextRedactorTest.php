<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Webhooks\TextRedactor;
use PHPUnit\Framework\TestCase;

/**
 * A receiver's error page routinely echoes the request that produced it. What
 * we keep for diagnosis must not become a credential store.
 */
final class TextRedactorTest extends TestCase
{
    public function test_it_removes_a_secret_it_was_told_about(): void
    {
        $secret = 'whsec_2d1b5a2f9d3e4c8a7b6f0e1d2c3b4a59';

        $redacted = (new TextRedactor)->redact('bad signature for '.$secret, [$secret]);

        $this->assertStringNotContainsString($secret, $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
    }

    public function test_it_removes_an_echoed_authorization_header(): void
    {
        $redacted = (new TextRedactor)->redact('rejected: Authorization: Bearer sk-live-abcdef1234567890');

        $this->assertStringNotContainsString('sk-live-abcdef1234567890', $redacted);
        $this->assertStringContainsString('Authorization', $redacted);
    }

    public function test_it_removes_a_jwt(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $this->assertStringNotContainsString($jwt, (new TextRedactor)->redact('token was '.$jwt));
    }

    public function test_it_removes_a_query_string_token_in_a_transport_error(): void
    {
        $redacted = (new TextRedactor)->redact('cURL error 28: failed to connect to host?token=9f8e7d6c5b4a39281706');

        $this->assertStringNotContainsString('9f8e7d6c5b4a39281706', $redacted);
    }

    public function test_it_leaves_an_ordinary_message_readable(): void
    {
        $this->assertSame(
            'Internal Server Error',
            (new TextRedactor)->redact("Internal\n  Server Error\n"),
        );
    }

    public function test_it_keeps_ulids_readable_because_operators_correlate_on_them(): void
    {
        $ulid = '01JZZK8Q2T3V4W5X6Y7Z8A9B0C';

        $this->assertStringContainsString($ulid, (new TextRedactor)->redact('unknown event '.$ulid));
    }

    public function test_it_truncates_to_the_configured_budget(): void
    {
        $redacted = (new TextRedactor)->redact(str_repeat('a ', 4096), maxBytes: 1024);

        $this->assertLessThanOrEqual(1024, strlen($redacted));
        $this->assertStringEndsWith('…', $redacted);
    }
}
