<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery\Mail;

use App\Domain\Delivery\Mail\MailErrorRedactor;
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
        $this->assertStringContainsString('[token]', $redacted);
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
