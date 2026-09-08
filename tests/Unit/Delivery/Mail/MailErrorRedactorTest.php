<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery\Mail;

use App\Domain\Delivery\Mail\MailErrorRedactor;
use App\Domain\Delivery\Webhooks\TextRedactor;
use PHPUnit\Framework\TestCase;

/**
 * The redactor is the only thing between a verbatim transport error and a database column
 * an operator will paste into a ticket, so every case here is a string a real transport
 * actually produces.
 */
class MailErrorRedactorTest extends TestCase
{
    private MailErrorRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new MailErrorRedactor;
    }

    public function test_removes_the_recipient_address_an_smtp_rejection_quotes_back(): void
    {
        $redacted = $this->redactor->text(
            'Expected response code 250 but got code "550", with message "550 5.1.1 <signer@counterparty.test>: Recipient address rejected"'
        );

        $this->assertStringNotContainsString('signer@counterparty.test', $redacted);
        $this->assertStringNotContainsString('counterparty.test', $redacted);
        $this->assertStringContainsString('[address]', $redacted);
        // The diagnostic itself has to survive, or there is no point storing anything.
        $this->assertStringContainsString('550', $redacted);
        $this->assertStringContainsString('Recipient address rejected', $redacted);
    }

    public function test_removes_a_whole_url_so_a_signing_token_goes_with_it(): void
    {
        $redacted = $this->redactor->text(
            'Failed while fetching https://esign.example.test/sign/01JABCDEFGHJKMNPQRSTVWXYZ?t=s3cr3t-token-value'
        );

        $this->assertStringNotContainsString('esign.example.test', $redacted);
        $this->assertStringNotContainsString('s3cr3t-token-value', $redacted);
        $this->assertStringContainsString('[url]', $redacted);
    }

    public function test_removes_the_api_key_out_of_a_mailer_dsn(): void
    {
        $redacted = $this->redactor->text(
            'Unable to build transport from DSN brevo+api://xkeysib-ffffffffffffffffffffffffffffffffffffffff@default'
        );

        $this->assertStringNotContainsString('xkeysib', $redacted);
        $this->assertStringContainsString('[url]', $redacted);
    }

    public function test_removes_a_bare_high_entropy_token(): void
    {
        $redacted = $this->redactor->text('Rejected credential 0123456789abcdef0123456789abcdef');

        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef', $redacted);
        $this->assertStringContainsString(TextRedactor::PLACEHOLDER, $redacted);
        $this->assertStringContainsString('Rejected credential', $redacted);
    }

    public function test_a_ulid_survives_because_it_is_what_an_operator_correlates_on(): void
    {
        $redacted = $this->redactor->text('Delivery 01JQZX9K7M4N2P5R8T3V6W1Y0B was refused');

        $this->assertStringContainsString('01JQZX9K7M4N2P5R8T3V6W1Y0B', $redacted);
    }

    public function test_removes_the_credential_shapes_the_webhook_redactor_knows_about(): void
    {
        // The point of delegating to TextRedactor: before it, `passphrase` was not in this
        // class's key list and a JWT was only caught by length. Two redactors solving one
        // problem is how you get two different sets of holes.
        $redacted = $this->redactor->text(
            'passphrase: hunter2hunter2 and eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln and client_secret=abc123'
        );

        $this->assertStringNotContainsString('hunter2hunter2', $redacted);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        $this->assertStringNotContainsString('abc123', $redacted);
    }

    public function test_removes_a_labelled_secret_but_keeps_the_sentence_readable(): void
    {
        $redacted = $this->redactor->text('api-key=abc123 rejected; signature verification failed');

        $this->assertStringNotContainsString('abc123', $redacted);
        // The word has to stay: "signature verification failed" is the diagnosis.
        $this->assertStringContainsString('signature verification failed', $redacted);
    }

    public function test_never_returns_an_empty_string(): void
    {
        $this->assertNotSame('', $this->redactor->text(''));
        $this->assertNotSame('', $this->redactor->text('   '));
    }

    public function test_truncates_a_very_long_error(): void
    {
        $redacted = $this->redactor->text(str_repeat('transport failure. ', 200));

        $this->assertLessThanOrEqual(MailErrorRedactor::MAX_LENGTH, mb_strlen($redacted));
    }

    public function test_drops_denied_payload_keys_and_redacts_the_rest(): void
    {
        $redacted = $this->redactor->payload([
            'event' => 'hardBounce',
            'email' => 'signer@counterparty.test',
            'reason' => 'mailbox unavailable for signer@counterparty.test',
            'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/cert.pem',
            'nested' => [
                'recipient_address' => 'other@counterparty.test',
                'code' => 550,
            ],
        ]);

        $this->assertSame('[redacted]', $redacted['email']);
        $this->assertSame('[redacted]', $redacted['SigningCertURL']);
        $this->assertSame('[redacted]', $redacted['nested']['recipient_address']);
        $this->assertSame('hardBounce', $redacted['event']);
        $this->assertSame(550, $redacted['nested']['code']);
        // A denied value can also appear inside an allowed key's prose.
        $this->assertStringNotContainsString('counterparty.test', $redacted['reason']);
    }

    public function test_truncates_a_deeply_nested_payload(): void
    {
        $payload = ['level' => 'top'];
        $cursor = &$payload;

        for ($i = 0; $i < 20; $i++) {
            $cursor['child'] = ['level' => (string) $i];
            $cursor = &$cursor['child'];
        }

        unset($cursor);

        $encoded = json_encode($this->redactor->payload($payload));

        $this->assertIsString($encoded);
        $this->assertStringContainsString('[truncated]', $encoded);
    }
}
