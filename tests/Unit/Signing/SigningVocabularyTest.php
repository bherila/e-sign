<?php

declare(strict_types=1);

namespace Tests\Unit\Signing;

use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Envelopes\SigningMode;
use App\Domain\Signing\Envelopes\ValueSource;
use App\Domain\Signing\Envelopes\VerificationMethod;
use PHPUnit\Framework\TestCase;

/**
 * The string vocabularies the columns hold, pinned.
 *
 * Every one of these enums backs a plain `string` column rather than a native ENUM, so the
 * DDL reads identically on SQLite, MySQL, and MariaDB and the application is the authority.
 * That is the right trade, and its cost is that the database will accept any string at all:
 * a renamed case is a silent data migration nobody wrote. These assertions are what make
 * that rename fail here instead of in production, and they are also what the migration
 * comments and docs/signing/state-machine.md are checked against by a reader.
 */
class SigningVocabularyTest extends TestCase
{
    public function test_the_envelope_states_are_the_documented_lifecycle(): void
    {
        $this->assertSame([
            'draft',
            'sent',
            'in_progress',
            'finalizing',
            'completed',
            'cancelled',
            'declined',
            'expired',
            'finalization_failed',
        ], EnvelopeState::values());
    }

    public function test_only_pre_completion_outcomes_and_completion_are_terminal(): void
    {
        $terminal = array_values(array_filter(
            EnvelopeState::cases(),
            static fn (EnvelopeState $state): bool => $state->isTerminal(),
        ));

        $this->assertSame([
            EnvelopeState::Completed,
            EnvelopeState::Cancelled,
            EnvelopeState::Declined,
            EnvelopeState::Expired,
        ], $terminal);

        // The one that matters: a failed finalization is retryable, never an outcome.
        $this->assertFalse(EnvelopeState::FinalizationFailed->isTerminal());
    }

    public function test_the_remaining_vocabularies(): void
    {
        $this->assertSame(['pending', 'active', 'signed', 'declined'], RecipientState::values());
        $this->assertSame(['sequential', 'parallel'], SigningMode::values());
        $this->assertSame(['sender', 'recipient'], ValueSource::values());
        $this->assertSame([
            'email_link',
            'email_otp',
            'trusted_assertion',
            'authenticated_user',
        ], VerificationMethod::values());
    }

    public function test_only_parallel_signing_freezes_at_send(): void
    {
        $this->assertTrue(SigningMode::Parallel->freezesAtSend());
        $this->assertFalse(SigningMode::Sequential->freezesAtSend());
    }

    public function test_the_event_names(): void
    {
        $this->assertSame([
            'signing_request.created',
            'signing_request.sent',
            'signing_request.recipient.signed',
            'signing_request.recipient.declined',
            'signing_request.completed',
            'signing_request.cancelled',
            'esign.envelope.declined',
            'signing_request.expired',
            'esign.envelope.finalization.failed',
        ], EnvelopeEvent::values());
    }
}
