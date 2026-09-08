<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NativeApiScenario;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * `GET /envelopes/{id}/values`, and the one decision that is not obvious: a signature is
 * described rather than dumped unless the caller asks for the bytes.
 *
 * The reasoning is on App\Domain\Integration\Native\FieldValueView. What is asserted here is
 * that the default really does withhold the image, that the digest is over the *decoded*
 * bytes so it is comparable with anything else that hashed the same image, and that
 * `?include=images` returns exactly what was captured.
 */
class NativeApiValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_schema_field_is_reported_in_schema_order_including_empty_ones(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->signing->preparedDraft();

        $response = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/values',
            NativeApiScenario::headers($issued))->assertOk();

        $this->assertSame(
            array_map(static fn ($field): string => $field->id, $envelope->fieldSchema()->fields),
            array_column($response->json('data'), 'field_id'),
        );

        // A field nobody has completed is present with a null value, not omitted: an absent
        // key would be indistinguishable from a field the schema does not have.
        $this->assertSame(null, $response->json('data.1.value'));
        $this->assertSame('buyer_notes', $response->json('data.1.field_id'));
    }

    public function test_a_signature_is_described_and_its_bytes_are_withheld_by_default(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->signedByBuyer($scenario);

        $response = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/values',
            NativeApiScenario::headers($issued))->assertOk();

        $signature = collect($response->json('data'))->firstWhere('field_id', 'buyer_signature');

        $this->assertSame('signature', $signature['type']);
        $this->assertNull($signature['value']);
        $this->assertSame('image/png', $signature['signature']['type']);

        // The digest and the length are over the decoded image, not over the data URL
        // wrapper, so they are comparable with anything else that hashed the same PNG.
        $captured = SigningFixtures::sampleValue($envelope->fieldSchema()->field('buyer_signature'));
        $decoded = (string) base64_decode(explode(',', $captured, 2)[1], true);

        $this->assertSame(strlen($decoded), $signature['signature']['bytes']);
        $this->assertSame(hash('sha256', $decoded), $signature['signature']['sha256']);
        $this->assertLessThan(strlen($captured), $signature['signature']['bytes']);

        // The image itself is nowhere in the body.
        $this->assertStringNotContainsString('iVBORw0KGgo', (string) $response->getContent());
    }

    public function test_include_images_returns_the_captured_value_verbatim(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->signedByBuyer($scenario);

        $response = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/values?include=images',
            NativeApiScenario::headers($issued))->assertOk();

        $signature = collect($response->json('data'))->firstWhere('field_id', 'buyer_signature');
        $captured = SigningFixtures::sampleValue($envelope->fieldSchema()->field('buyer_signature'));

        $this->assertSame($captured, $signature['signature']['data']);
    }

    public function test_an_unrecognised_include_value_is_refused_rather_than_ignored(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->signing->preparedDraft();

        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/values?include=everything',
            NativeApiScenario::headers($issued))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_signing_date_is_supplied_by_the_service_from_the_attestation(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->signedByBuyer($scenario);

        $signing = $scenario->signing;
        $signing->signAs($envelope->refresh(), $signing->recipient($envelope, 'seller'), 'session-seller');

        $response = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/values',
            NativeApiScenario::headers($issued))->assertOk();

        $signedAt = collect($response->json('data'))->firstWhere('field_id', 'seller_signed_at');

        $this->assertSame('service', $signedAt['source']);
        $this->assertSame(
            $signing->recipient($envelope, 'seller')->attestations()->firstOrFail()->accepted_at->toDateString(),
            $signedAt['value'],
        );
    }

    private function signedByBuyer(NativeApiScenario $scenario): Envelope
    {
        $signing = $scenario->signing;
        $envelope = $signing->sent();

        $signing->signAs($envelope->refresh(), $signing->recipient($envelope, 'buyer'), 'session-buyer');

        return $envelope->refresh();
    }
}
