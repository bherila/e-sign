<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery\Mail;

use App\Domain\Delivery\Mail\MailContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules on the one URL a transactional message is allowed to carry.
 *
 * Each one exists because of a specific failure. A relative URL is unusable in a mail
 * client, which has no base URL. Credentials in the authority are a credential in a
 * forwarded message. A token in a fragment is a token the server never receives and can
 * therefore never expire or revoke. And more than one query parameter means more than one
 * thing to lift out of a quoted reply.
 */
class MailContextTest extends TestCase
{
    public function test_round_trips_through_the_persisted_shape(): void
    {
        $context = new MailContext(
            recipientName: 'Avery Counterparty',
            senderName: 'Example Holdings',
            agreementTitle: 'Mutual Nondisclosure Agreement',
            actionUrl: 'https://esign.example.test/sign/01JQZX?t=opaque',
            expiresAt: CarbonImmutable::parse('2026-10-01T12:00:00Z'),
            actorName: 'Blake Sender',
            reason: 'Superseded.',
            failureSummary: 'Finalization failed.',
            reference: 'envelope 01JQZX',
        );

        $restored = MailContext::fromArray($context->toArray());

        $this->assertSame($context->toArray(), $restored->toArray());
        $this->assertSame('Avery Counterparty', $restored->recipientName);
        $this->assertTrue($context->expiresAt->equalTo($restored->expiresAt));
    }

    public function test_a_context_needs_someone_to_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailContext(recipientName: '   ');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableUrls(): array
    {
        return [
            'relative' => ['/sign/01JQZX?t=opaque'],
            'scheme relative' => ['//esign.example.test/sign/01JQZX'],
            'not http' => ['javascript:alert(1)'],
            'file' => ['file:///etc/passwd'],
            'credentials in authority' => ['https://user:pass@esign.example.test/sign/01JQZX'],
            'fragment' => ['https://esign.example.test/sign/01JQZX#t=opaque'],
            'two query parameters' => ['https://esign.example.test/sign?r=01JQZX&t=opaque'],
        ];
    }

    #[DataProvider('unusableUrls')]
    public function test_refuses_an_unusable_action_url(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailContext(recipientName: 'Avery Counterparty', actionUrl: $url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function usableUrls(): array
    {
        return [
            'path token' => ['https://esign.example.test/sign/01JQZX9K7M4N2P5R8T3V6W1Y0B'],
            'one query parameter' => ['https://esign.example.test/sign?t=opaque'],
            // Plaintext is allowed for a local development deployment; enforcing HTTPS is
            // a deployment concern, not this object's.
            'plaintext for local development' => ['http://localhost:8000/sign/01JQZX'],
        ];
    }

    #[DataProvider('usableUrls')]
    public function test_accepts_a_usable_action_url(string $url): void
    {
        $this->assertSame($url, (new MailContext(recipientName: 'Avery Counterparty', actionUrl: $url))->actionUrl);
    }

    public function test_reports_which_fields_a_template_is_missing(): void
    {
        $context = new MailContext(recipientName: 'Avery Counterparty', senderName: 'Example Holdings');

        $this->assertSame(
            ['agreementTitle', 'actionUrl'],
            $context->missing(['recipientName', 'senderName', 'agreementTitle', 'actionUrl']),
        );
    }

    public function test_rejects_an_unknown_field_name_rather_than_reporting_it_present(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MailContext(recipientName: 'Avery Counterparty'))->missing(['notAField']);
    }

    public function test_an_older_rows_context_still_rehydrates(): void
    {
        // A deploy that added or dropped a field must not leave a queue of messages that can
        // never be sent and never be explained.
        $context = MailContext::fromArray([
            'recipient_name' => 'Avery Counterparty',
            'agreement_title' => 'Mutual Nondisclosure Agreement',
            'some_field_from_the_future' => 'ignored',
        ]);

        $this->assertSame('Avery Counterparty', $context->recipientName);
        $this->assertNull($context->actionUrl);
    }

    public function test_an_unparseable_expiry_becomes_no_stated_expiry(): void
    {
        $context = MailContext::fromArray([
            'recipient_name' => 'Avery Counterparty',
            'expires_at' => 'whenever',
        ]);

        $this->assertNull($context->expiresAt);
    }
}
