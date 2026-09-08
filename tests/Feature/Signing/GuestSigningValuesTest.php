<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\Support\SyntheticImages;
use Tests\TestCase;

/**
 * Saving what a signer filled in — and the two things that endpoint refuses.
 *
 * Ownership is the first: docs/ARCHITECTURE.md invariant 1 says a recipient completes only
 * their own fields, and the guest surface must not be the place that gets it wrong.
 *
 * Controlled rendering is the second. docs/HANDOFF.md section 8 requires submitted signature
 * images to be decoded and re-encoded with size and dimension limits, and forbids arbitrary
 * SVG, HTML, and remote URLs. Every one of those has a case here, and the positive case
 * asserts the stored bytes are *not* the submitted bytes — which is the whole point of
 * re-encoding rather than validating.
 */
class GuestSigningValuesTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_a_recipient_saves_their_own_fields(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => [
                    'buyer_ack' => true,
                    'buyer_notes' => 'Synthetic note from the buyer.',
                ],
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['field_ids', 'reviewed' => ['material_values_sha256', 'envelope_version']]);

        $this->assertDatabaseHas('envelope_field_values', [
            'envelope_id' => $scenario->envelope->getKey(),
            'schema_field_id' => 'buyer_ack',
        ]);
    }

    public function test_the_returned_review_matches_what_the_acceptance_will_be_checked_against(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['buyer_ack' => true],
            ]);

        $envelope = $scenario->envelope->refresh();

        $this->assertSame($envelope->version, $response->json('reviewed.envelope_version'));
        $this->assertSame(
            app(EnvelopeStateMachine::class)->materialValuesDigest($envelope),
            $response->json('reviewed.material_values_sha256'),
        );
    }

    public function test_another_recipients_field_is_refused(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['seller_title' => 'Director'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('values.seller_title');

        $this->assertDatabaseMissing('envelope_field_values', ['schema_field_id' => 'seller_title']);
    }

    public function test_a_read_only_field_of_their_own_is_refused(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['agreement_effective_date' => '2026-02-02'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('values.agreement_effective_date');
    }

    public function test_an_unknown_field_is_refused(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['not_a_field' => 'anything'],
            ])
            ->assertStatus(422);
    }

    public function test_a_value_of_the_wrong_type_is_refused_by_the_server(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['buyer_ack' => 'yes please'],
            ])
            ->assertStatus(422);
    }

    public function test_a_signature_is_re_encoded_rather_than_stored_as_submitted(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $submitted = SyntheticImages::pngDataUrl();

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['buyer_signature' => $submitted],
            ])
            ->assertOk();

        $stored = EnvelopeFieldValue::query()
            ->where('schema_field_id', 'buyer_signature')
            ->firstOrFail();

        $this->assertIsString($stored->value);
        $this->assertStringStartsWith('data:image/png;base64,', $stored->value);
        $this->assertNotSame(
            $submitted,
            $stored->value,
            'A validated image stored verbatim would carry whatever else was in the file.',
        );
    }

    public function test_a_jpeg_signature_is_accepted_and_becomes_a_png(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['buyer_signature' => SyntheticImages::jpegDataUrl()],
            ])
            ->assertOk();

        $stored = EnvelopeFieldValue::query()->where('schema_field_id', 'buyer_signature')->firstOrFail();
        $this->assertStringStartsWith('data:image/png;base64,', (string) $stored->value);
    }

    public function test_an_svg_signature_is_refused(): void
    {
        $this->assertSignatureRefused(SyntheticImages::svgDataUrl(), 'unsupported_type');
    }

    public function test_bytes_that_are_not_an_image_are_refused(): void
    {
        $this->assertSignatureRefused(SyntheticImages::notAnImageDataUrl(), 'not_decodable');
    }

    public function test_a_remote_url_is_refused(): void
    {
        $this->assertSignatureRefused('https://attacker.example.test/signature.png', 'not_a_data_url');
    }

    public function test_an_over_wide_signature_is_refused(): void
    {
        config()->set('esign.signing.max_signature_image_width', 100);

        $this->assertSignatureRefused(SyntheticImages::pngDataUrl(300, 50), 'too_large');
    }

    public function test_an_over_tall_signature_is_refused(): void
    {
        config()->set('esign.signing.max_signature_image_height', 20);

        $this->assertSignatureRefused(SyntheticImages::pngDataUrl(100, 60), 'too_large');
    }

    public function test_an_oversized_signature_is_refused(): void
    {
        config()->set('esign.signing.max_signature_image_bytes', 1_024);

        $this->assertSignatureRefused(SyntheticImages::pngDataUrl(1_500, 700), 'too_many_bytes');
    }

    public function test_values_cannot_be_saved_without_a_session(): void
    {
        $scenario = GuestSigningScenario::sent();

        $this->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
            'values' => ['buyer_ack' => true],
        ])->assertForbidden();

        // The envelope already holds the sender's prefill, so "nothing was written" is
        // asserted against the field that was submitted rather than against the table.
        $this->assertDatabaseMissing('envelope_field_values', ['schema_field_id' => 'buyer_ack']);
    }

    private function assertSignatureRefused(string $submitted, string $reason): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($scenario->envelope, '/values'), [
                'values' => ['buyer_signature' => $submitted],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', $reason);

        $this->assertDatabaseMissing('envelope_field_values', ['schema_field_id' => 'buyer_signature']);
    }
}
