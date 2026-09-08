<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PdfFixtures;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\TestCase;

/**
 * The data-destruction certification: one recipient, one signature, one seal.
 *
 * The smallest of the four workflows, and the one that pins the single-signer path: there is
 * no countersignature to wait for, so the *first* acceptance is also the last and the envelope
 * must go straight to finalizing. A build that only ever activated the next stage would pass
 * every multi-signer test and hang here.
 *
 * It is also where the three published artifacts are checked as a set, because a certification
 * is the case where somebody is most likely to want the completion report on its own.
 */
class DataDestructionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICER = 'sasha@custodian.example.test';

    public function test_a_single_signer_certification_completes_and_publishes_three_artifacts(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $id = (string) $consumer->createAndSend([
            'name' => 'Synthetic data destruction certification',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Sasha', 'last_name' => 'Custodian', 'email' => self::OFFICER, 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Officer Signature', 'recipient_email' => self::OFFICER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 30.0, 'height' => 5.0]],
                ['type' => 'checkbox', 'page_number' => 1, 'variable_name' => 'Destruction Confirmed', 'recipient_email' => self::OFFICER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 66.0, 'width' => 2.0, 'height' => 2.0]],
            ],
        ])->assertStatus(201)->json('id');

        $consumer->drainWebhooks();
        $this->assertTrue($consumer->hasInvitationFor(self::OFFICER));

        $consumer->signAs($id, self::OFFICER);
        $consumer->drainWebhooks();

        // One signature, and it is the last one — but still not a completion. The event that
        // says the agreement is executed comes from the finalizer, not from the signature.
        $this->assertCount(1, $consumer->receiver->processed('signing_request.recipient.signed'));
        $this->assertSame(0, $consumer->receiver->countProcessed('signing_request.completed'));
        $this->assertFalse($consumer->poll($id)['status']['finished']);
        $this->assertSame(0, Artifact::query()->count());

        $consumer->runFinalizationWorker($id);
        $consumer->drainWebhooks();

        $completed = $consumer->receiver->firstProcessed('signing_request.completed');
        $this->assertNotNull($completed);
        $this->assertTrue($completed->verified);
        $this->assertTrue($completed->status()['finished']);

        /* ------------------------------------------------------------ the three files */

        $envelopeKey = $consumer->envelope($id)->getKey();
        $kinds = Artifact::query()->where('envelope_id', $envelopeKey)->pluck('kind')->map->value->sort()->values()->all();

        $this->assertSame([
            ArtifactKind::CompletionReport->value,
            ArtifactKind::EvidenceJson->value,
            ArtifactKind::ExecutedPdf->value,
        ], $kinds);

        /* ---------------------------------------- the executed PDF, and the report alone */

        $polled = $consumer->poll($id);

        $executedBytes = $consumer->fetch($polled['final_document_download_url'])->assertOk()->streamedContent();
        $reportBytes = $consumer->fetch($polled['certificate_only_download_url'])->assertOk()->streamedContent();
        $reviewBytes = $consumer->fetch($polled['document_only_download_url'])->assertOk()->streamedContent();

        $executed = Artifact::query()->where('envelope_id', $envelopeKey)
            ->where('kind', ArtifactKind::ExecutedPdf->value)->sole();
        $report = Artifact::query()->where('envelope_id', $envelopeKey)
            ->where('kind', ArtifactKind::CompletionReport->value)->sole();

        $this->assertSame($executed->sha256, hash('sha256', $executedBytes));
        $this->assertSame($report->sha256, hash('sha256', $reportBytes));

        // The original is retained byte-for-byte: the review revision the signer was shown is
        // still exactly what it was, and is not the sealed artifact.
        $this->assertSame($consumer->envelope($id)->documentRevision->sha256, hash('sha256', $reviewBytes));
        $this->assertNotSame($executed->sha256, hash('sha256', $reviewBytes));

        $validation = app(ArtifactValidator::class)->validate($executedBytes);
        $this->assertSame([], $validation->failures);
        $this->assertSame(AssuranceLevel::PadesBB, $validation->reachedLevel());

        // The completion report is a statement about what happened, not a credential. It is
        // deliberately unsealed, and honest language matters here: it is labelled separately
        // from anything an X.509 certificate would be (`docs/HANDOFF.md` §8).
        $this->assertFalse(ArtifactKind::CompletionReport->isSealed());
        $this->assertFalse(app(ArtifactValidator::class)->validate($reportBytes)->signed);
        $this->assertStringStartsWith('%PDF', $reportBytes);
        $this->assertSame('application/pdf', ArtifactKind::CompletionReport->contentType());
    }
}
