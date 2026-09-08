<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Domain\Delivery\Webhooks\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * The signature a receiver has to be able to reproduce.
 *
 * Every expectation here is computed independently, from the scheme as written
 * in docs/HANDOFF.md §11, rather than by calling the class under test twice.
 */
final class WebhookSignerTest extends TestCase
{
    private const SECRET = 'whsec_2d1b5a2f9d3e4c8a7b6f0e1d2c3b4a5968778695a4b3c2d1e0f9a8b7c6d5e4f3';

    private const BODY = '{"id":"01JZZK8Q2T3V4W5X6Y7Z8A9B0C","type":"signing_request.completed","data":{"n":1}}';

    public function test_the_signature_is_hex_hmac_sha256_over_timestamp_dot_raw_body(): void
    {
        $timestamp = 1757000000;

        // Computed here from the documented scheme, not from the signer.
        $expected = hash_hmac('sha256', '1757000000.'.self::BODY, self::SECRET);

        $this->assertSame($expected, (new WebhookSigner)->signature(self::SECRET, $timestamp, self::BODY));
        $this->assertSame(64, strlen($expected));
    }

    public function test_the_header_carries_the_timestamp_and_one_v1_entry(): void
    {
        $header = (new WebhookSigner)->headerValue([self::SECRET], 1757000000, self::BODY);

        $this->assertSame(
            't=1757000000,v1='.hash_hmac('sha256', '1757000000.'.self::BODY, self::SECRET),
            $header,
        );
    }

    public function test_a_rotation_puts_both_secrets_in_the_header_current_first(): void
    {
        $previous = 'whsec_previous';
        $header = (new WebhookSigner)->headers([self::SECRET, $previous], 1757000000, self::BODY);

        $this->assertSame(
            't=1757000000,'.
            'v1='.hash_hmac('sha256', '1757000000.'.self::BODY, self::SECRET).','.
            'v1='.hash_hmac('sha256', '1757000000.'.self::BODY, $previous),
            $header[WebhookSigner::SIGNATURE_HEADER],
        );

        // The documented upstream rotation header, emitted alongside, so a
        // receiver written literally against the vendor guide also verifies.
        $this->assertSame(
            't=1757000000,v1='.hash_hmac('sha256', '1757000000.'.self::BODY, $previous),
            $header[WebhookSigner::PREVIOUS_SIGNATURE_HEADER],
        );
    }

    public function test_without_a_rotation_there_is_no_old_signature_header(): void
    {
        $headers = (new WebhookSigner)->headers([self::SECRET], 1757000000, self::BODY);

        $this->assertArrayNotHasKey(WebhookSigner::PREVIOUS_SIGNATURE_HEADER, $headers);
    }

    public function test_one_changed_byte_in_the_body_changes_the_signature(): void
    {
        $signer = new WebhookSigner;

        $this->assertNotSame(
            $signer->signature(self::SECRET, 1757000000, self::BODY),
            $signer->signature(self::SECRET, 1757000000, self::BODY.' '),
        );
    }

    public function test_the_timestamp_is_part_of_what_is_signed(): void
    {
        $signer = new WebhookSigner;

        $this->assertNotSame(
            $signer->signature(self::SECRET, 1757000000, self::BODY),
            $signer->signature(self::SECRET, 1757000001, self::BODY),
        );
    }
}
