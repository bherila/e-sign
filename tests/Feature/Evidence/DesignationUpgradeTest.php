<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\EnvelopeMailScheduler;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Signing\EnvelopeDesignationTest;
use Tests\Support\FakeDownloadUrlMinter;
use Tests\Support\FakeSigningUrlMinter;
use Tests\Support\FinalizationScenario;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Upgrade fixtures for issue #169: rows written by an earlier release, carried all the way
 * through the current one.
 *
 * Schema 1.2 shipped before the signing side of #169, so a draft created in between stored its
 * cc recipient as an ordinary `pending` party in stage 1. That shape is persisted state, and a
 * test that only covers drafts the current factory writes would never see it. This one puts a
 * draft back into the earlier representation, then sends, signs, approves, seals, and announces
 * it under the current code, and checks the cc recipient was never treated as a party who acts.
 */
class DesignationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_with_a_cc_recipient_in_the_earlier_shape_completes_with_the_cc_only_copied(): void
    {
        Queue::fake([SendOutboundMail::class, DispatchOutboxEvent::class]);
        $this->app->instance(SigningUrlMinter::class, new FakeSigningUrlMinter);
        $this->app->instance(DownloadUrlMinter::class, new FakeDownloadUrlMinter);

        $scenario = FinalizationScenario::signedWith(
            EnvelopeDesignationTest::schemaWithApproverAndCc(),
            ['signer', 'manager'],
            static function (Envelope $draft, SigningScenario $signing): void {
                // Exactly what EnvelopeFactory wrote for a cc recipient before #178.
                $signing->recipient($draft, 'records')
                    ->forceFill(['state' => RecipientState::Pending, 'order_index' => 1])
                    ->save();
            },
        );

        $records = $scenario->signing->recipient($scenario->envelope, 'records');
        $this->assertSame(RecipientState::Copied, $records->state);
        $this->assertSame(0, $records->order_index);
        $this->assertNull($records->invited_at);
        $this->assertSame(0, $scenario->envelope->attestations()->where('recipient_id', $records->getKey())->count());

        $scenario->finalizer()->finalize($scenario->envelope);
        $envelope = $scenario->envelope->refresh();
        $this->assertSame(EnvelopeState::Completed, $envelope->state);

        $this->app->make(EnvelopeMailScheduler::class)->schedule($envelope, EnvelopeEvent::Completed);

        $this->assertSame(
            [MailKind::Completed],
            OutboundMail::query()->where('to_email', 'records@example.test')->get()
                ->map(static fn (OutboundMail $mail): MailKind => $mail->kind)
                ->all(),
            'The cc recipient is sent the completed copy and nothing else.',
        );
    }
}
