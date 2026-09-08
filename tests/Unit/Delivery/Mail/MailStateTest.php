<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery\Mail;

use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\MessageId;
use PHPUnit\Framework\TestCase;

/**
 * The state ordering, which is what makes duplicate and out-of-order provider webhooks
 * harmless, and the Message-ID normalization that lets three providers' spellings match one
 * stored value.
 */
class MailStateTest extends TestCase
{
    public function test_only_provider_confirmed_states_mean_a_mailbox_took_the_message(): void
    {
        // The distinction the whole outbox exists to preserve: handing bytes to a transport
        // is not delivery.
        $this->assertFalse(MailState::Queued->meansMailboxAccepted());
        $this->assertFalse(MailState::SentToProvider->meansMailboxAccepted());
        $this->assertFalse(MailState::Accepted->meansMailboxAccepted());
        $this->assertFalse(MailState::Bounced->meansMailboxAccepted());
        $this->assertFalse(MailState::Failed->meansMailboxAccepted());

        $this->assertTrue(MailState::Delivered->meansMailboxAccepted());
        // A complaint means it arrived and the recipient reported it.
        $this->assertTrue(MailState::Complained->meansMailboxAccepted());
    }

    public function test_feedback_moves_forward_and_never_back(): void
    {
        $this->assertTrue(MailState::Delivered->supersedes(MailState::SentToProvider));
        $this->assertTrue(MailState::Accepted->supersedes(MailState::SentToProvider));
        $this->assertFalse(MailState::SentToProvider->supersedes(MailState::Delivered));
        $this->assertFalse(MailState::Accepted->supersedes(MailState::Delivered));
    }

    public function test_a_duplicate_webhook_changes_nothing(): void
    {
        foreach (MailState::cases() as $state) {
            $this->assertFalse($state->supersedes($state));
        }
    }

    public function test_a_negative_outcome_always_wins(): void
    {
        // A bounce or a complaint is what an operator has to see, so a late `delivered`
        // cannot bury it.
        $this->assertTrue(MailState::Bounced->supersedes(MailState::Delivered));
        $this->assertTrue(MailState::Complained->supersedes(MailState::Bounced));
        $this->assertFalse(MailState::Delivered->supersedes(MailState::Bounced));
    }

    public function test_only_queued_is_still_the_applications_to_send(): void
    {
        $this->assertFalse(MailState::Queued->isTerminalForSending());

        foreach (MailState::cases() as $state) {
            if ($state !== MailState::Queued) {
                $this->assertTrue($state->isTerminalForSending(), "{$state->value} should be terminal for sending.");
            }
        }
    }

    public function test_message_ids_normalize_to_one_spelling(): void
    {
        // Symfony returns it bracketed for SMTP, Brevo quotes it with brackets, SES sends it
        // bare. All three have to match one stored value.
        $this->assertSame('abc@mail.example.test', MessageId::normalize('<abc@mail.example.test>'));
        $this->assertSame('abc@mail.example.test', MessageId::normalize('  <ABC@Mail.Example.Test>  '));
        $this->assertSame('abc@mail.example.test', MessageId::normalize('abc@mail.example.test'));
    }

    public function test_an_absent_or_unusable_message_id_is_null(): void
    {
        $this->assertNull(MessageId::normalize(null));
        $this->assertNull(MessageId::normalize(''));
        $this->assertNull(MessageId::normalize('<>'));
        // Longer than the column. Truncating would invent a false match.
        $this->assertNull(MessageId::normalize(str_repeat('a', 192)));
    }
}
