<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Envelopes\EnvelopeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\TestCase;
use ZipArchive;

/**
 * The platform NDA: a template, two recipients in sequence, the second countersigning.
 *
 * This is the long one. It is written as a single test on purpose — the claim being made is
 * that one uninterrupted run of the consumer's own workflow reaches an executed, sealed,
 * retrievable agreement, and splitting it into fifteen tests that each rebuild the world
 * would assert fifteen shorter claims instead of that one.
 *
 * Everything the consumer does here it does over HTTP: it creates from a template it already
 * had an id for, patches a prefill by name, sends, receives a signed webhook on its own
 * endpoint, polls, follows the invitation the recipient was mailed, signs on the guest pages,
 * watches the second invitation appear, countersigns, runs the finalization worker, and only
 * then downloads and validates the sealed PDF and pulls the evidence bundle.
 */
class PlatformNdaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = 'robin@platform.example.test';

    private const SELLER = 'sam@counterparty.example.test';

    public function test_the_platform_nda_runs_from_template_to_validated_seal(): void
    {
        $consumer = SyntheticConsumer::install($this);
        $version = $consumer->publishedTemplate();

        /* ------------------------------------------------------------------ 1. create */

        $created = $consumer->create([
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic platform NDA',
            'settings' => ['use_signing_order' => true, 'require_otp_verification' => false],
            'recipients' => [
                ['template_user_id' => 'buyer', 'first_name' => 'Robin', 'last_name' => 'Platform', 'email' => self::BUYER, 'designation' => 'Signer', 'order' => 1],
                ['template_user_id' => 'seller', 'first_name' => 'Sam', 'last_name' => 'Counterparty', 'email' => self::SELLER, 'designation' => 'Signer', 'order' => 2],
            ],
            // The one field the sender has to supply before the request can go out, patched
            // by the name the consumer has hardcoded rather than by our field id.
            'fields' => [['variable_name' => 'agreement_date', 'read_only_value' => '2026-02-01']],
        ])->assertStatus(201);

        $id = (string) $created->json('id');

        // The create response's status is a string, and the polling response's is an object
        // of booleans. Three representations coexist in this profile and none may be
        // normalised into another.
        $this->assertSame('draft', $created->json('status'));
        $this->assertFalse($consumer->poll($id)['status']['sent']);

        // Nothing has been announced to anybody: a draft has been shown to no one.
        $consumer->drainWebhooks();
        $this->assertSame(['signing_request.created'], $consumer->receiver->processedTypes());
        $this->assertFalse($consumer->hasInvitationFor(self::BUYER));

        /* --------------------------------------------- 2. correct a prefill by name */

        // The consumer's singular `field` patch, addressing the field by the variable name it
        // has hardcoded. It gets back the `/fields` row for that one field, plus the singular
        // `warning` this route carries (the create routes carry a plural `warnings` array;
        // neither may be normalised into the other).
        $patched = $consumer->patch($id, [
            'field' => ['variable_name' => 'agreement_date', 'value' => '2026-02-15'],
        ])->assertOk();

        $this->assertSame('2026-02-15', $patched->json('final_value'));
        $this->assertNull($patched->json('warning'));

        /* -------------------------------------------------------------------- 3. send */

        $sent = $consumer->send($id)->assertOk();
        $this->assertSame([self::BUYER], $sent->json('sentTo'));

        $consumer->drainWebhooks();

        $sentEvent = $consumer->receiver->firstProcessed('signing_request.sent');
        $this->assertNotNull($sentEvent, 'The consumer never received signing_request.sent.');
        $this->assertTrue($sentEvent->verified);
        $this->assertSame($id, $sentEvent->signingRequestId());
        $this->assertTrue($sentEvent->status()['sent']);
        $this->assertFalse($sentEvent->status()['finished']);

        // Sequential order: only the released stage was invited.
        $this->assertTrue($consumer->hasInvitationFor(self::BUYER));
        $this->assertFalse($consumer->hasInvitationFor(self::SELLER));

        /* ------------------------------------------------------- 4. the first signature */

        $consumer->signAs($id, self::BUYER);
        $consumer->drainWebhooks();

        $signed = $consumer->receiver->processed('signing_request.recipient.signed');
        $this->assertCount(1, $signed);
        $this->assertSame(self::BUYER, $signed[0]->recipientEmail());

        // A signature is not an execution.
        $this->assertFalse($signed[0]->status()['finished']);
        $this->assertSame(0, $consumer->receiver->countProcessed('signing_request.completed'));

        // The progression: the countersignatory is now invited, and the consumer's polling
        // reconciliation can see the same thing.
        $this->assertTrue($consumer->hasInvitationFor(self::SELLER));
        $this->assertNotNull($consumer->user($id, self::BUYER)['finished_on']);
        $this->assertNull($consumer->user($id, self::SELLER)['finished_on']);

        /* --------------------------------------------------- 5. the countersignature */

        $consumer->signAs($id, self::SELLER);
        $consumer->drainWebhooks();

        $this->assertCount(2, $consumer->receiver->processed('signing_request.recipient.signed'));

        // Still not completed: everyone has signed, and the artifact does not exist yet.
        $this->assertSame(0, $consumer->receiver->countProcessed('signing_request.completed'));
        $this->assertSame(EnvelopeState::Finalizing, $consumer->envelope($id)->state);
        $this->assertFalse($consumer->poll($id)['status']['finished']);

        /* ------------------------------------------- 6. the seal, then the event */

        // Asserted at the moment of receipt, because after the fact it is published either
        // way: when `completed` arrived, a validated artifact already existed.
        $publishedWhenAnnounced = null;
        $consumer->receiver->on(
            'signing_request.completed',
            function () use (&$publishedWhenAnnounced): void {
                $publishedWhenAnnounced = Artifact::query()->whereNotNull('published_at')->count();
            },
        );

        $consumer->runFinalizationWorker($id);
        $consumer->drainWebhooks();

        $completed = $consumer->receiver->firstProcessed('signing_request.completed');
        $this->assertNotNull($completed, 'The consumer never received signing_request.completed.');
        $this->assertTrue($completed->verified);
        $this->assertTrue($completed->status()['finished']);
        $this->assertTrue($completed->reportsDownloadAvailable());
        $this->assertSame(3, $publishedWhenAnnounced);

        /* ---------------------------------------------------------------- 7. download */

        $download = $consumer->download($id);
        $this->assertSame('finished', $download['status']);
        $this->assertFalse($download['is_partial']);

        $pdf = $consumer->fetch($download['download_url'])->assertOk();
        $bytes = $pdf->streamedContent();

        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $bytes);

        $executed = Artifact::query()
            ->where('envelope_id', $consumer->envelope($id)->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $this->assertSame($executed->sha256, hash('sha256', $bytes));

        /* ------------------------------------------------------- 8. validate the seal */

        $report = app(ArtifactValidator::class)->validate($bytes);

        $this->assertSame([], $report->failures);
        $this->assertTrue($report->isValid());
        $this->assertTrue($report->coversWholeFile);
        $this->assertTrue($report->cryptographicallySound);

        // B-B and not B-T, because no timestamp authority was configured and none was
        // contacted. A level that cannot be met is an error here, never a silent downgrade;
        // this envelope asked for B-B and got it.
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());
        $this->assertFalse($report->hasSignatureTimestamp);

        /* --------------------------------------------------------- 9. evidence bundle */

        $archive = $consumer->evidenceBundle($id)->assertOk();
        $this->assertSame('application/zip', $archive->headers->get('Content-Type'));

        $path = (string) tempnam(sys_get_temp_dir(), 'esign-e2e-bundle-');

        try {
            file_put_contents($path, $archive->streamedContent());

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);

            $names = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $names[] = $zip->getNameIndex($index);
            }

            $this->assertContains('reviewed-revision.pdf', $names);
            $zip->close();
        } finally {
            @unlink($path);
        }

        /* ------------------------------------------------ 10. nothing left the process */

        $this->assertSame(
            [SyntheticConsumer::WEBHOOK_URL],
            array_values(array_unique($consumer->outboundUrls())),
        );
    }
}
