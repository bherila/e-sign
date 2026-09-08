<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\Recipient;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateAlias;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\RenderSettings;
use App\Domain\Preparation\Templates\TemplateAliasSource;
use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Envelopes in each of the four states the recorded `firma-compat-v1` fixtures capture, with
 * a credential that can reach them through the compatibility facade.
 *
 * Built on {@see SigningScenario} and driven through the real state machine and the real
 * finalizer, because the whole claim being tested is that the facade is a projection of one
 * state machine (AGENTS.md). An envelope assembled by inserting rows would let a facade
 * serializer agree with a fixture while disagreeing with the product.
 *
 * The document is the committed `multi-page-mixed-size` fixture and the seal material is the
 * generated fixture key, so the completed cases really do publish artifacts and the
 * `/download` route really does stream retained bytes. Everything is synthetic (AGENTS.md).
 */
final class FirmaFacadeScenario
{
    /** Three pages, three sizes: room for both signers plus a spare. */
    public const DOCUMENT_FIXTURE = FinalizationScenario::DOCUMENT_FIXTURE;

    /**
     * What a facade credential normally holds: admission to the profile plus the resource
     * scopes each route needs. `compat:firma-v1` grants no resource authority of its own
     * ({@see Scope}), so it is never the whole list.
     *
     * @var list<Scope>
     */
    public const FULL_SCOPES = [
        Scope::EnvelopesRead,
        Scope::EnvelopesWrite,
        Scope::TemplatesRead,
        Scope::CompatFirmaV1,
    ];

    public readonly SigningScenario $signing;

    public readonly Workspace $workspace;

    private function __construct()
    {
        Storage::fake('documents');

        FinalizationScenario::configureSeal();

        $this->signing = SigningScenario::create(
            revisionBytes: PdfFixtures::bytes(self::DOCUMENT_FIXTURE),
        )->bind();

        $this->workspace = $this->signing->workspace;
    }

    public static function create(): self
    {
        return new self;
    }

    /**
     * @param  list<Scope>  $scopes
     */
    public function credential(
        array $scopes = self::FULL_SCOPES,
        ?DateTimeInterface $expiresAt = null,
        string $label = 'firma facade test',
    ): IssuedServiceCredential {
        return app(ServiceCredentialIssuer::class)->issue(
            $this->workspace,
            $label,
            array_map(static fn (Scope $scope): string => $scope->value, $scopes),
            AuditActor::system('tests'),
            $expiresAt,
        );
    }

    /**
     * The consumer's syntax: the key in `Authorization` with no scheme at all.
     *
     * This is the default here, and `Bearer` is the variant, which is the opposite of the
     * native suite's default. The facade's whole reason for accepting the raw form is that
     * the client this profile exists for sends it that way (docs/HANDOFF.md section 10).
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function headers(IssuedServiceCredential $issued, array $extra = []): array
    {
        return ['Authorization' => $issued->secret] + $extra;
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function bearerHeaders(IssuedServiceCredential $issued, array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$issued->secret] + $extra;
    }

    /**
     * Sent, nobody finished: the `platform-nda` fixture's state.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function sent(array $overrides = []): Envelope
    {
        return $this->signing->sent($overrides);
    }

    /**
     * Sent then withdrawn: the `order-form` fixture's state, where `status.sent` and
     * `status.cancelled` are both true.
     */
    public function cancelled(string $reason = 'Withdrawn before signature.'): Envelope
    {
        $envelope = $this->sent();

        $this->signing->machine()->cancel($envelope, $reason);

        return $envelope->refresh();
    }

    /**
     * Two signers, finished, artifacts published: the `practice-nda` fixture's state.
     */
    public function completed(): Envelope
    {
        $envelope = $this->sent();

        $this->signing->signAs($envelope, $this->signing->recipient($envelope, 'buyer'), 'session-buyer');
        $envelope->refresh();
        $this->signing->signAs($envelope, $this->signing->recipient($envelope, 'seller'), 'session-seller');

        return $this->finalize($envelope->refresh());
    }

    /**
     * One signer, finished: the `data-destruction` fixture's state.
     */
    public function completedSingleSigner(): Envelope
    {
        $envelope = $this->sent(['field_schema' => SigningFixtures::singleSigner()]);

        $this->signing->signAs($envelope, $this->signing->recipient($envelope, 'signer'), 'session-signer');

        return $this->finalize($envelope->refresh());
    }

    /**
     * A published template version over this scenario's document revision.
     *
     * Field aliases are what the profile's `variable_name` resolves against, so the default
     * schema gets one on the field the sender has to prefill before the request can go out —
     * which is exactly the field the consumer patches by name.
     *
     * @param  array<string, mixed>|null  $schema  Defaults to the sequential two-signer fixture.
     */
    public function publishedTemplate(?array $schema = null, string $name = 'Synthetic mutual NDA template'): TemplateVersion
    {
        $template = new Template([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'description' => 'Two-signer synthetic NDA.',
            'created_by' => $this->signing->user->getKey(),
        ]);
        $template->save();

        $document = FieldSchemaDocument::fromArray($schema ?? SigningFixtures::mutateField(
            SigningFixtures::sequentialTwoSigners(),
            'agreement_effective_date',
            ['alias' => 'agreement_date'],
        ));

        $version = new TemplateVersion([
            'template_id' => $template->getKey(),
            'version' => 1,
            'document_revision_id' => $this->signing->revision->getKey(),
            'field_schema' => $document->toArray(),
            'field_schema_sha256' => hash('sha256', $document->canonicalJson()),
            'recipients' => array_map(
                static fn (Recipient $recipient): array => $recipient->toArray(),
                $document->recipients,
            ),
            'consent_policy_version' => SigningFixtures::CONSENT_VERSION,
            'render_settings' => RenderSettings::defaults()->toArray(),
            'published_at' => CarbonImmutable::now(),
            'created_by' => $this->signing->user->getKey(),
        ]);
        $version->save();

        $template->current_version_id = $version->getKey();
        $template->save();

        return $version->refresh()->load('template', 'documentRevision.document');
    }

    /**
     * Record the provider id a consumer already has hardcoded, against one of our templates.
     *
     * `docs/HANDOFF.md` section 6 keeps native ids and imported aliases in separate fields;
     * this is the alias side, and the facade accepts either as `template_id`.
     */
    public function alias(Template $template, string $alias): TemplateAlias
    {
        return app(TemplateService::class)->addAlias(
            $template,
            $this->signing->user,
            $alias,
            TemplateAliasSource::ImportedProvider,
        );
    }

    /**
     * Run the real finalizer, resolved from the container so the provider's wiring stays on
     * the tested path.
     */
    private function finalize(Envelope $envelope): Envelope
    {
        app(EnvelopeFinalizer::class)->finalize($envelope);

        $envelope = $envelope->refresh();

        if ($envelope->state !== EnvelopeState::Completed) {
            throw new RuntimeException(
                'The scenario did not complete; the envelope is in '.$envelope->state->value.'.',
            );
        }

        return $envelope;
    }
}
