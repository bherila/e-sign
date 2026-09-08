<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\Recipient;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\RenderSettings;
use App\Domain\Preparation\Templates\TemplateStateException;
use App\Domain\Signing\Envelopes\EnvelopeSourceSnapshot;
use App\Domain\Signing\Envelopes\EnvelopeState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The seam between Preparation and Signing.
 *
 * `TemplateVersion::snapshotForEnvelope()` says the Signing module calls it instead of
 * reading columns, and `EnvelopeSourceSnapshot::fromTemplateVersion()` is that caller. The
 * two use different names for two of the same facts, so this asserts the translation rather
 * than assuming it — a silent mismatch here would produce an envelope whose declared digest
 * is not the digest of the bytes it points at, which the factory refuses but only after the
 * caller has already got it wrong.
 *
 * The dependency points one way on purpose: Signing knows what a template snapshot looks
 * like, and Preparation knows nothing about envelopes.
 */
class EnvelopeFromTemplateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_published_version_becomes_an_envelope_that_can_be_sent(): void
    {
        $scenario = SigningScenario::create();
        $version = $this->publishedVersion($scenario);

        $snapshot = EnvelopeSourceSnapshot::fromTemplateVersion(
            $version->snapshotForEnvelope(),
            ['expiration_hours' => 72],
        );

        $envelope = $scenario->factory()->fromSnapshot($scenario->workspace, $snapshot, $scenario->user);

        $this->assertSame('Synthetic mutual NDA template', $envelope->title);
        $this->assertSame($version->public_id, $envelope->source_template_version_id);
        $this->assertSame($scenario->revision->getKey(), $envelope->document_revision_id);
        $this->assertSame($scenario->revision->sha256, $envelope->document_sha256);
        $this->assertSame($version->field_schema_sha256, $envelope->field_schema_sha256);
        $this->assertSame(SigningFixtures::CONSENT_VERSION, $envelope->consent_policy_version);
        $this->assertSame(RenderSettings::defaults()->toArray(), $envelope->render_settings);
        $this->assertSame(72, $envelope->expiration_hours);
        $this->assertSame(['buyer', 'seller'], $envelope->recipients()->pluck('schema_recipient_id')->all());

        $scenario->machine()->setSenderValues($envelope, SigningFixtures::senderPrefills($envelope));
        $scenario->machine()->send($envelope->refresh());

        $this->assertSame(EnvelopeState::Sent, $envelope->state);
    }

    public function test_the_title_can_be_overridden_without_touching_the_template(): void
    {
        $scenario = SigningScenario::create();
        $version = $this->publishedVersion($scenario);

        $snapshot = EnvelopeSourceSnapshot::fromTemplateVersion(
            $version->snapshotForEnvelope(),
            ['title' => 'NDA — Synthetic Counterparty Ltd'],
        );

        $this->assertSame('NDA — Synthetic Counterparty Ltd', $snapshot->title);
        $this->assertSame('Synthetic mutual NDA template', $version->template->name);
    }

    /**
     * The templates module refuses to snapshot a draft, and it has to: a draft can still
     * change, so an envelope copied from one would be a request the template's later edits
     * did reach.
     */
    public function test_a_draft_version_cannot_be_turned_into_an_envelope(): void
    {
        $scenario = SigningScenario::create();
        $version = $this->publishedVersion($scenario, published: false);

        $this->expectException(TemplateStateException::class);

        $version->snapshotForEnvelope();
    }

    private function publishedVersion(SigningScenario $scenario, bool $published = true): TemplateVersion
    {
        $template = new Template([
            'workspace_id' => $scenario->workspace->getKey(),
            'name' => 'Synthetic mutual NDA template',
            'description' => 'Two-signer synthetic NDA.',
            'created_by' => $scenario->user->getKey(),
        ]);
        $template->save();

        $schema = FieldSchemaDocument::fromArray(SigningFixtures::sequentialTwoSigners());

        $version = new TemplateVersion([
            'template_id' => $template->getKey(),
            'version' => 1,
            'document_revision_id' => $scenario->revision->getKey(),
            'field_schema' => $schema->toArray(),
            'field_schema_sha256' => hash('sha256', $schema->canonicalJson()),
            'recipients' => array_map(
                static fn (Recipient $recipient): array => $recipient->toArray(),
                $schema->recipients,
            ),
            'consent_policy_version' => SigningFixtures::CONSENT_VERSION,
            'render_settings' => RenderSettings::defaults()->toArray(),
            'published_at' => $published ? CarbonImmutable::now() : null,
            'created_by' => $scenario->user->getKey(),
        ]);
        $version->save();

        return $version->refresh()->load('template', 'documentRevision.document');
    }
}
