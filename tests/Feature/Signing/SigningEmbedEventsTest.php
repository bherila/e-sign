<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Sessions\SigningEmbedEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * The server half of the embedding event contract (issue #168, docs/signing/embedded-events.md).
 *
 * Nothing is announced unless the deployment names an origin; an origin is taken exactly or
 * not at all; and announcing does not make the page frameable.
 */
class SigningEmbedEventsTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_by_default_a_signing_page_announces_nothing(): void
    {
        config()->set('esign.signing.embed_origins', []);

        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));

        $response->assertOk();
        $response->assertDontSee('data-signing-events', false);
        $response->assertDontSee('signing-events', false);
    }

    public function test_only_exact_origins_are_kept(): void
    {
        config()->set('esign.signing.embed_origins', [
            'https://App.Example.test',
            'https://app.example.test',
            'https://ok.example.test:8443',
            'http://localhost:5173',
            '*',
            'https://*.example.test',
            'https://app.example.test/',
            'https://app.example.test/path',
            'http://plain.example.test',
            'https://user:pass@app.example.test',
        ]);

        $this->assertSame(
            ['https://app.example.test', 'https://ok.example.test:8443', 'http://localhost:5173'],
            $this->app->make(SigningEmbedEvents::class)->origins(),
        );

        // Anchored to the true end of the string: a trailing newline is not an origin.
        $this->assertFalse(SigningEmbedEvents::isOrigin("https://app.example.test\n"));
    }

    public function test_a_completed_signature_page_carries_its_event_and_the_page_stays_unframeable(): void
    {
        config()->set('esign.signing.embed_origins', ['https://app.example.test']);

        $scenario = GuestSigningScenario::sent();
        $scenario->recipient()->forceFill(['state' => 'signed', 'signed_at' => now()])->save();
        $cookie = $this->startSigningSessionForSignedRecipient($scenario);

        $response = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));

        $response->assertOk();
        $attribute = $this->eventsAttribute((string) $response->getContent());

        $this->assertSame(['https://app.example.test'], $attribute['origins']);
        $this->assertSame('signing.completed', $attribute['event']['type']);
        $this->assertSame($scenario->envelope->public_id, $attribute['event']['envelope_id']);
        $this->assertSame($scenario->recipient()->public_id, $attribute['event']['recipient_id']);
        $this->assertArrayNotHasKey('email', $attribute['event']);

        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_refusal_page_announces_a_code_and_no_identifiers(): void
    {
        config()->set('esign.signing.embed_origins', ['https://app.example.test']);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $scenario->envelope->forceFill(['state' => 'cancelled'])->save();

        $response = $this->get(GuestSigningScenario::landingUrl($issued));
        $attribute = $this->eventsAttribute((string) $response->getContent());

        $this->assertSame(['type' => 'signing.error', 'code' => 'link_unusable'], $attribute['event']);
    }

    /** Start the session while the recipient can still act, then mark them signed. */
    private function startSigningSessionForSignedRecipient(GuestSigningScenario $scenario): string
    {
        $recipient = $scenario->recipient();
        $recipient->forceFill(['state' => 'active', 'signed_at' => null])->save();
        $cookie = $this->startSigningSession($scenario);
        $recipient->forceFill(['state' => 'signed', 'signed_at' => now()])->save();

        return $cookie;
    }

    /** @return array{origins: list<string>, event: array<string, mixed>|null} */
    private function eventsAttribute(string $html): array
    {
        $matched = preg_match('/data-signing-events="(.*?)"/s', $html, $matches);
        $this->assertSame(1, $matched, 'The page carried no data-signing-events attribute.');

        /** @var array{origins: list<string>, event: array<string, mixed>|null} $decoded */
        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
