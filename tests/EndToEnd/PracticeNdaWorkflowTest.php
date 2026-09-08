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
 * The practice NDA: one `create-and-send` call with a base64 PDF and percent coordinates,
 * buyer then seller.
 *
 * The platform NDA covers the template path; this covers the other one, which is the call the
 * consumer makes when it holds the document itself. The load-bearing difference is the
 * coordinate space: the profile's `position` is a **percentage of the displayed page** and the
 * native schema is in points, so the test asserts the exact native rectangle a percentage
 * lands on — computed from the fixture's own page size, not from whatever the code produced.
 * "Never infer percent versus points from a number's magnitude" (AGENTS.md) is only a rule if
 * something fails when it is broken.
 */
class PracticeNdaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = 'devon@practice.example.test';

    private const SELLER = 'wren@counterparty.example.test';

    /** `single-page-letter.pdf`, from tests/Fixtures/pdf/manifest.json. */
    private const PAGE_WIDTH = 612.0;

    private const PAGE_HEIGHT = 792.0;

    public function test_the_practice_nda_runs_from_create_and_send_to_validated_seal(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $created = $consumer->createAndSend([
            'name' => 'Synthetic practice NDA',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['use_signing_order' => true, 'require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Devon', 'last_name' => 'Buyer', 'email' => self::BUYER, 'designation' => 'Signer', 'order' => 1],
                ['first_name' => 'Wren', 'last_name' => 'Seller', 'email' => self::SELLER, 'designation' => 'Signer', 'order' => 2],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Buyer Signature', 'recipient_email' => self::BUYER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 28.0, 'height' => 5.0]],
                ['type' => 'title', 'page_number' => 1, 'variable_name' => 'Buyer Title', 'recipient_email' => self::BUYER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 28.0, 'height' => 3.0]],
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Seller Signature', 'recipient_email' => self::SELLER, 'required' => true, 'position' => ['x' => 55.0, 'y' => 72.0, 'width' => 28.0, 'height' => 5.0]],
                ['type' => 'title', 'page_number' => 1, 'variable_name' => 'Seller Title', 'recipient_email' => self::SELLER, 'required' => true, 'position' => ['x' => 55.0, 'y' => 80.0, 'width' => 28.0, 'height' => 3.0]],
            ],
        ])->assertStatus(201);

        $id = (string) $created->json('id');

        // The create-and-send response's status is the string "sent", and the first signer is
        // named in the body — that is the shape this call has, and it is not the polling one.
        $this->assertSame('sent', $created->json('status'));
        $this->assertSame(self::BUYER, $created->json('first_signer.email'));

        /* ------------------------------------------------ the coordinates, both ways */

        $native = $consumer->envelope($id)->fieldSchema()->fields[0]->rect;

        $this->assertSame(round(10.0 / 100 * self::PAGE_WIDTH, 3), $native->x);
        $this->assertSame(round(72.0 / 100 * self::PAGE_HEIGHT, 3), $native->y);
        $this->assertSame(round(28.0 / 100 * self::PAGE_WIDTH, 3), $native->width);
        $this->assertSame(round(5.0 / 100 * self::PAGE_HEIGHT, 3), $native->height);

        // A points reading of the same numbers would have placed it at 10, 72 — a different
        // place on the page that a tolerance-based test would have accepted.
        $this->assertNotSame(10.0, $native->x);

        // And back out to the consumer as the percentages it sent, in the upstream spellings.
        $row = $consumer->fields($id)[0];
        $this->assertEqualsWithDelta(10.0, $row['x_postion'], 0.001);
        $this->assertEqualsWithDelta(72.0, $row['y_position'], 0.001);
        $this->assertSame($row['x_postion'], $row['position']['x']);
        $this->assertSame($row['heigh'], $row['position']['height']);

        /* ------------------------------------------------------------- the two events */

        $consumer->drainWebhooks();

        $this->assertSame(
            ['signing_request.created', 'signing_request.sent'],
            $consumer->receiver->processedTypes(),
        );
        $this->assertTrue($consumer->hasInvitationFor(self::BUYER));
        $this->assertFalse($consumer->hasInvitationFor(self::SELLER));

        /* -------------------------------------------------- buyer, then the countersign */

        $consumer->signAs($id, self::BUYER);
        $consumer->drainWebhooks();

        $signed = $consumer->receiver->processed('signing_request.recipient.signed');
        $this->assertCount(1, $signed);
        $this->assertSame(self::BUYER, $signed[0]->recipientEmail());
        $this->assertTrue($consumer->hasInvitationFor(self::SELLER));

        $consumer->signAs($id, self::SELLER);
        $consumer->drainWebhooks();

        $this->assertCount(2, $consumer->receiver->processed('signing_request.recipient.signed'));
        $this->assertSame(0, $consumer->receiver->countProcessed('signing_request.completed'));

        /* --------------------------------------------------------------- seal and read */

        $consumer->runFinalizationWorker($id);
        $consumer->drainWebhooks();

        $completed = $consumer->receiver->firstProcessed('signing_request.completed');
        $this->assertNotNull($completed);
        $this->assertTrue($completed->verified);
        $this->assertTrue($completed->reportsDownloadAvailable());

        $download = $consumer->download($id);
        $this->assertSame('finished', $download['status']);
        $this->assertFalse($download['is_partial']);

        $bytes = $consumer->fetch($download['download_url'])->assertOk()->streamedContent();

        $executed = Artifact::query()
            ->where('envelope_id', $consumer->envelope($id)->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $this->assertSame($executed->sha256, hash('sha256', $bytes));

        $report = app(ArtifactValidator::class)->validate($bytes);
        $this->assertSame([], $report->failures);
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());

        // Both signatures are recorded, and the final values are readable back through the
        // same `/fields` call the consumer polls.
        foreach ([self::BUYER, self::SELLER] as $email) {
            $this->assertNotNull($consumer->user($id, $email)['finished_on']);
        }

        $signatureValues = array_values(array_filter(
            $consumer->fields($id),
            static fn (array $field): bool => $field['field_type'] === 'signature',
        ));

        $this->assertCount(2, $signatureValues);

        foreach ($signatureValues as $field) {
            // Without `?include=images` a signature reads as the profile's marker, never as
            // an image somebody could lift out of a polling response.
            $this->assertSame('Recipient Signature', $field['final_value']);
            $this->assertSame($field['final_value'], $field['value']);
        }
    }
}
