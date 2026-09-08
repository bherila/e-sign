<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Events;

use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\EnvelopeMailScheduler;
use App\Domain\Delivery\Events\Exceptions\SigningUrlUnavailable;
use App\Domain\Delivery\Events\PlaceholderSigningUrlMinter;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDownloadUrlMinter;
use Tests\Support\FakeSigningUrlMinter;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The mail side on its own, driven directly rather than through a transition.
 *
 * Two things are easier to state here than through the state machine: what happens when no
 * signing link can be issued, and what a completion notice says about a document that is not
 * published. Both are refusals, and both are the reason the ports have the shapes they do.
 */
class EnvelopeMailSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private SigningScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendOutboundMail::class, DispatchOutboxEvent::class]);

        $this->scenario = SigningScenario::create();
        $this->app->instance(SigningUrlMinter::class, new FakeSigningUrlMinter);
        $this->app->instance(DownloadUrlMinter::class, new FakeDownloadUrlMinter);
    }

    public function test_the_placeholder_minter_refuses_rather_than_sending_a_dead_link(): void
    {
        $envelope = $this->sentEnvelope();

        // The invitation the send already produced is out of the way; this is about the one
        // that must *not* be produced.
        $floor = (int) OutboundMail::query()->max('id');

        $buyer = $this->scenario->recipient($envelope, 'buyer');
        $buyer->forceFill(['invited_at' => null])->save();

        $this->app->instance(SigningUrlMinter::class, new PlaceholderSigningUrlMinter);

        try {
            $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::Sent);
            $this->fail('An invitation with no signing URL should be refused.');
        } catch (SigningUrlUnavailable $refusal) {
            $this->assertStringContainsString(SigningUrlMinter::class, $refusal->getMessage());
        }

        // Nothing was written and nothing was claimed: the job can be retried once guest
        // access binds a real issuer, and no row claims this person was invited.
        $this->assertSame(0, OutboundMail::query()->where('id', '>', $floor)->count());
        $this->assertNull($buyer->refresh()->invited_at);
    }

    public function test_a_completion_notice_links_to_the_document_only_once_one_exists(): void
    {
        $envelope = $this->sentEnvelope();

        // The finalization module lands the artifacts table; what an envelope exposes today
        // is the reference itself, and that is what the gate reads.
        $this->assertNull($envelope->artifact_ref);

        $floor = (int) OutboundMail::query()->max('id');

        $this->scheduler()->schedule($envelope, EnvelopeEvent::Completed);

        $unpublished = $this->completionContexts($floor);
        $this->assertNotEmpty($unpublished);

        foreach ($unpublished as $context) {
            $this->assertNull($context['action_url']);
        }

        $floor = (int) OutboundMail::query()->max('id');

        $envelope->forceFill(['artifact_ref' => 'artifacts/'.$envelope->public_id.'/final.pdf'])->save();

        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::Completed);

        $contexts = $this->completionContexts($floor);
        $this->assertNotEmpty($contexts);

        foreach ($contexts as $context) {
            $this->assertSame(
                'https://esign.example.test/agreements/'.$envelope->public_id.'/download',
                $context['action_url'],
            );
        }
    }

    public function test_an_invitation_is_sent_once_however_many_times_the_event_is_replayed(): void
    {
        $envelope = $this->sentEnvelope();

        // At-least-once delivery means the scheduling job can run twice. The recipient must
        // not get two links.
        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::Sent);
        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::Sent);

        $this->assertSame(1, OutboundMail::query()->where('kind', MailKind::Invitation->value)->count());
    }

    public function test_nothing_is_invited_once_the_envelope_is_no_longer_open(): void
    {
        $envelope = $this->sentEnvelope();
        $this->machine()->cancel($envelope, 'Superseded.');

        $seller = $this->scenario->recipient($envelope, 'seller');
        $this->assertNull($seller->invited_at);

        // A job that sat on the queue while the envelope was withdrawn must not go on to
        // invite somebody to sign it.
        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::Sent);

        $this->assertSame(['buyer@example.test'], OutboundMail::query()
            ->where('kind', MailKind::Invitation->value)
            ->pluck('to_email')
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completionContexts(int $floor): array
    {
        return OutboundMail::query()
            ->where('kind', MailKind::Completed->value)
            ->where('id', '>', $floor)
            ->orderBy('id')
            ->get()
            ->map(static fn (OutboundMail $mail): array => $mail->context)
            ->all();
    }

    private function sentEnvelope(): Envelope
    {
        $envelope = $this->scenario->preparedDraft();
        $this->machine()->send($envelope);

        return $envelope->refresh();
    }

    private function scheduler(): EnvelopeMailScheduler
    {
        return $this->app->make(EnvelopeMailScheduler::class);
    }

    private function machine(): EnvelopeStateMachine
    {
        return new EnvelopeStateMachine(
            $this->app->make(DeliveryEnvelopeEventSink::class),
            $this->scenario->assurance,
        );
    }
}
