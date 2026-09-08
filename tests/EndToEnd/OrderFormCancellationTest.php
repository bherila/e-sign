<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PdfFixtures;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\TestCase;

/**
 * The order form: created, sent, half-signed, then withdrawn.
 *
 * The interesting part of a cancellation is not that the state changes. It is what happens to
 * everything that was already in flight — the signature that was already given, the link the
 * second signatory still has in their inbox, and what a `download` means for an agreement that
 * will never be executed. `docs/HANDOFF.md` §15 names cancellation as one of the four pilot
 * flows for exactly that reason.
 */
class OrderFormCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = 'quinn@ordering.example.test';

    private const SUPPLIER = 'ari@supplier.example.test';

    public function test_a_cancelled_order_form_stops_the_pending_signatory_and_serves_partial_bytes(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $id = (string) $consumer->createAndSend([
            'name' => 'Synthetic order form',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['use_signing_order' => true, 'require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Quinn', 'last_name' => 'Buyer', 'email' => self::BUYER, 'designation' => 'Signer', 'order' => 1],
                ['first_name' => 'Ari', 'last_name' => 'Supplier', 'email' => self::SUPPLIER, 'designation' => 'Signer', 'order' => 2],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Buyer Signature', 'recipient_email' => self::BUYER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 28.0, 'height' => 5.0]],
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Supplier Signature', 'recipient_email' => self::SUPPLIER, 'required' => true, 'position' => ['x' => 55.0, 'y' => 72.0, 'width' => 28.0, 'height' => 5.0]],
            ],
        ])->assertStatus(201)->json('id');

        $consumer->drainWebhooks();
        $consumer->signAs($id, self::BUYER);
        $consumer->drainWebhooks();

        // Mid-flight: one signature in, the second signatory holding a live link.
        $this->assertCount(1, $consumer->receiver->processed('signing_request.recipient.signed'));
        $this->assertTrue($consumer->hasInvitationFor(self::SUPPLIER));
        $consumer->openInvitation(self::SUPPLIER)->assertOk();

        /* ------------------------------------------------------------------- withdraw */

        $cancelled = $consumer->cancel($id, ['reason' => 'Superseded by a revised order.'])->assertOk();

        $this->assertSame($id, $cancelled->json('signing_request_id'));
        $this->assertNotNull($cancelled->json('cancelled_on'));

        $consumer->drainWebhooks();

        $event = $consumer->receiver->firstProcessed('signing_request.cancelled');
        $this->assertNotNull($event, 'The consumer never received signing_request.cancelled.');
        $this->assertTrue($event->verified);

        // Both flags true at once. A terminal state that was also sent reports both, which is
        // what the profile means by "multiple can be true"; collapsing them into one string
        // would lose the fact that people were asked to sign this.
        $this->assertTrue($event->status()['sent']);
        $this->assertTrue($event->status()['cancelled']);
        $this->assertFalse($event->status()['finished']);

        $polled = $consumer->poll($id);
        $this->assertTrue($polled['status']['cancelled']);
        $this->assertNotNull($polled['timestamps']['cancelled_on']);

        /* --------------------------------------------------- the link that now refuses */

        // A 403 with the same page every refusal gets: the reason is in the audit trail, not
        // on a page a prober can read.
        $consumer->openInvitation(self::SUPPLIER)->assertForbidden();

        $landing = (string) parse_url($consumer->invitationUrlFor(self::SUPPLIER), PHP_URL_PATH);
        $this->call('POST', $landing.'/start')->assertForbidden();

        $supplier = $consumer->envelope($id)->recipients()->where('email', self::SUPPLIER)->sole();
        $this->assertNotSame(RecipientState::Signed, $supplier->state);

        /* ---------------------------------------------------------- partial semantics */

        // The signature that was given still stands. Cancelling withdraws the agreement; it
        // does not un-sign it, and the evidence of what happened is not rewritten.
        $this->assertNotNull($consumer->user($id, self::BUYER)['finished_on']);
        $this->assertNull($consumer->user($id, self::SUPPLIER)['finished_on']);

        $download = $consumer->download($id);

        $this->assertSame('cancelled', $download['status']);
        $this->assertTrue($download['is_partial']);
        $this->assertNotNull($download['download_url']);

        $partial = $consumer->fetch($download['download_url'])->assertOk();
        $bytes = $partial->streamedContent();

        $this->assertSame('application/pdf', $partial->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $bytes);

        // Partial means *the reviewed revision*, not a half-sealed artifact. Nothing was ever
        // sealed here, and the digest served is the revision's own.
        $revision = $consumer->envelope($id)->documentRevision;
        $this->assertSame($revision->sha256, hash('sha256', $bytes));
        $this->assertSame($revision->sha256, $partial->headers->get('X-Document-Sha256'));

        /* ------------------------------------------------------- nothing was completed */

        $this->assertSame(0, $consumer->receiver->countProcessed('signing_request.completed'));
        $this->assertSame(0, Artifact::query()->count());
        $this->assertSame(EnvelopeState::Cancelled, $consumer->envelope($id)->state);

        // Every attempt the consumer's receiver saw verified. A cancellation is not a reason
        // to relax the signature check.
        $this->assertSame(0, $consumer->receiver->rejectedCount());
    }
}
