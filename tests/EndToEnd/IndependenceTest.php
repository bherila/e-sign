<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Tests\Support\PdfFixtures;
use Tests\Support\SyntheticConsumer\RefusingTimestampAuthority;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\TestCase;

/**
 * Release gate 12: **"Block Firma/DocuSign hosts: new workflows still complete. Use local
 * SMTP/storage configuration to prove Brevo/Garage are swappable. No CDN/analytics is
 * required."**
 *
 * The blocking here is stronger than the gate asks for, and deliberately so. A CI job that
 * nulled two hostnames in `/etc/hosts` would prove those two hostnames are unused and nothing
 * about the third. `Http::preventStrayRequests()` with one registered fake inverts it: the
 * *only* destination that exists for the whole run is this consumer's own endpoint, and
 * everything else — a Firma host, a DocuSign host, a CDN, an analytics beacon, a licence
 * check — raises on the spot. The workflows in this suite all run under that fence, so
 * "new workflows still complete" is asserted by every other file here; what this one adds is
 * the proof that the fence is real and that the two named vendors are on the wrong side of it.
 *
 * `docs/security/release-gates.md` recorded this gate as partly proven, with "a run with
 * Firma/DocuSign hosts blocked at the network level" as the missing part. This is that run —
 * at the process level rather than the network level, which is a difference worth stating: a
 * process-level fence cannot see a socket opened outside the HTTP client. The timestamp
 * authority is the one thing in this application that does that, which is why it is bound to
 * {@see RefusingTimestampAuthority} rather than merely fenced.
 */
class IndependenceTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNER = 'noor@independence.example.test';

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedDestinations(): array
    {
        return [
            'firma api' => ['https://api.firma.hr/functions/v1/signing-request-api/signing-requests'],
            'firma app' => ['https://app.firma.hr/sign/whatever'],
            'docusign demo' => ['https://demo.docusign.net/restapi/v2.1/accounts/1/envelopes'],
            'docusign na' => ['https://na4.docusign.net/restapi/v2.1/accounts/1/envelopes'],
            'a cdn' => ['https://cdn.jsdelivr.net/npm/pdfjs-dist/build/pdf.worker.min.js'],
            'an analytics beacon' => ['https://www.google-analytics.com/collect'],
        ];
    }

    #[DataProvider('blockedDestinations')]
    public function test_no_request_can_leave_this_process(string $url): void
    {
        SyntheticConsumer::install($this);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/without a matching fake/i');

        Http::get($url);
    }

    /**
     * The other half of the gate's first sentence: with everything blocked, a workflow still
     * runs from creation to a validated seal.
     */
    public function test_a_workflow_completes_with_every_external_destination_blocked(): void
    {
        $consumer = SyntheticConsumer::install($this);

        // Prove the fence is up *first*, then run the workflow under it.
        try {
            Http::get('https://api.firma.hr/functions/v1/signing-request-api/signing-requests');
            $this->fail('A request to a Firma host was not blocked.');
        } catch (RuntimeException) {
            // expected
        }

        $id = $this->completedEnvelope($consumer);

        $this->assertNotNull($consumer->receiver->firstProcessed('signing_request.completed'));

        $download = $consumer->download($id);
        $bytes = $consumer->fetch($download['download_url'])->assertOk()->streamedContent();

        $executed = Artifact::query()
            ->where('envelope_id', $consumer->envelope($id)->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $this->assertSame($executed->sha256, hash('sha256', $bytes));
        $this->assertSame([], app(ArtifactValidator::class)->validate($bytes)->failures);

        // Everything the application put on the wire during a complete workflow, and it is one
        // destination: the consumer's own callback.
        $this->assertSame(
            [SyntheticConsumer::WEBHOOK_URL],
            array_values(array_unique($consumer->outboundUrls())),
        );
    }

    /**
     * No timestamp authority is contacted, and the seal says so rather than pretending.
     */
    public function test_the_seal_is_b_b_because_no_timestamp_authority_was_reachable(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $authority = app(TimestampAuthority::class);
        $this->assertInstanceOf(RefusingTimestampAuthority::class, $authority);
        $this->assertFalse($authority->isConfigured());

        $id = $this->completedEnvelope($consumer);

        $bytes = $consumer->fetch($consumer->download($id)['download_url'])->streamedContent();
        $report = app(ArtifactValidator::class)->validate($bytes);

        // B-B, honestly labelled: the artifact carries no signature timestamp and the report
        // does not claim one. A requested level that cannot be met is an error and never a
        // silent downgrade (AGENTS.md), and this envelope asked for B-B.
        $this->assertSame([], $report->failures);
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());
        $this->assertFalse($report->hasSignatureTimestamp);

        // And asking it anything at all is an error, never a quiet fallback to B-B.
        try {
            $authority->assertUsable();
            $this->fail('The refusing authority declared itself usable.');
        } catch (TimestampAuthorityNotConfiguredException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Mail is a configuration choice, not a dependency: the whole suite runs on the `array`
     * mailer and the invitation still carries a working link.
     */
    public function test_mail_is_swappable_and_the_invitation_carries_the_signing_link(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $this->assertSame('array', config('mail.default'));

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $id = $this->sentEnvelope($consumer);

        /* ------------------------------------------------------------- the outbox row */

        $invitation = OutboundMail::query()
            ->where('kind', MailKind::Invitation)
            ->where('to_email', self::SIGNER)
            ->sole();

        // `sent_to_provider` and not `delivered`: signing success never depends on falsely
        // declaring an address reachable.
        $this->assertSame(MailState::SentToProvider, $invitation->state);
        $this->assertSame('array', $invitation->mailer);
        $this->assertFalse($invitation->state->meansMailboxAccepted());
        $this->assertNotNull($invitation->message_id);

        /* --------------------------------------------------- the message that went out */

        /** @var SentMessage|null $sent */
        $sent = $transport->messages()->last();
        $this->assertNotNull($sent);

        $body = $sent->toString();
        $url = (string) $invitation->context['action_url'];

        // The link in the message is the link the recipient uses, and it works: the harness
        // reads it out of exactly this row to drive the guest flow.
        $this->assertStringContainsString('sign/'.$id, $url);
        $this->assertStringContainsString(self::SIGNER, $body);

        $consumer->openInvitation(self::SIGNER)->assertOk();
        $consumer->signAs($id, self::SIGNER);

        $this->assertNotNull($consumer->envelope($id)->recipients()->sole()->signed_at);
    }

    /* ------------------------------------------------------------------------ helpers */

    private function sentEnvelope(SyntheticConsumer $consumer): string
    {
        return (string) $consumer->createAndSend([
            'name' => 'Synthetic independence agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Noor', 'last_name' => 'Signer', 'email' => self::SIGNER, 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Signature', 'recipient_email' => self::SIGNER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 30.0, 'height' => 5.0]],
            ],
        ])->assertStatus(201)->json('id');
    }

    private function completedEnvelope(SyntheticConsumer $consumer): string
    {
        $id = $this->sentEnvelope($consumer);

        $consumer->signAs($id, self::SIGNER);
        $consumer->runFinalizationWorker($id);
        $consumer->drainWebhooks();

        return $id;
    }
}
