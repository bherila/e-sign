<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Signing\EnvelopeDesignationTest;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * Designations on the native API (issue #169, part 3).
 *
 * A template's approver and cc recipient are copied into the envelope with the template, and
 * every read of the envelope says what each party is, at every step through to completion.
 */
class NativeApiDesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_templates_designations_are_kept_from_create_through_completion(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion(EnvelopeDesignationTest::schemaWithApproverAndCc(), 'Synthetic approval template');
        $headers = NativeApiScenario::headers($issued);

        $created = $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'recipients' => [
                // A cc recipient's contact details are as correctable as anybody's.
                ['id' => 'records', 'name' => 'Synthetic Records Desk', 'email' => 'records@desk.example.test'],
            ],
        ], $headers)->assertCreated();

        $designations = static function (array $recipients): array {
            $designations = array_column($recipients, 'designation', 'schema_recipient_id');
            ksort($designations);

            return $designations;
        };

        $this->assertSame(['manager' => 'approver', 'records' => 'cc', 'signer' => 'signer'], $designations($created->json('recipients')));

        $id = (string) $created->json('id');
        $this->postJson('/api/v1/envelopes/'.$id.'/send', [], $headers)->assertOk();

        $recipients = collect($this->getJson('/api/v1/envelopes/'.$id.'/recipients', $headers)->assertOk()->json('data'))
            ->keyBy('schema_recipient_id');
        $this->assertSame(['active', 1], [$recipients['signer']['state'], $recipients['signer']['order_index']]);
        $this->assertSame(['pending', 2], [$recipients['manager']['state'], $recipients['manager']['order_index']]);
        $this->assertSame(['copied', 0, 'cc'], [$recipients['records']['state'], $recipients['records']['order_index'], $recipients['records']['designation']]);
        $this->assertSame('records@desk.example.test', $recipients['records']['email']);

        $signing = $scenario->signing;
        $envelope = Envelope::query()->where('public_id', $id)->firstOrFail();
        $signing->signAs($envelope, $signing->recipient($envelope, 'signer'), 'session-signer');
        $signing->signAs($envelope->refresh(), $signing->recipient($envelope, 'manager'), 'session-manager');
        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);

        $after = collect($this->getJson('/api/v1/envelopes/'.$id, $headers)->assertOk()->json('recipients'))
            ->keyBy('schema_recipient_id');
        $this->assertSame(['signed', 'approver'], [$after['manager']['state'], $after['manager']['designation']]);
        $this->assertSame(['copied', 'cc'], [$after['records']['state'], $after['records']['designation']]);
    }
}
