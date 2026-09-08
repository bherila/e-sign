<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\Recipient;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\RenderSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One workspace with everything a native API test needs, and a credential to reach it with.
 *
 * Built on {@see SigningScenario} rather than beside it, so an API test and a state-machine
 * test are looking at the same rows: the same synthetic document revision, the same field
 * schema fixtures, the same helpers for signing an envelope through to completion. A second
 * set of builders would drift, and the first thing to drift would be the field schema, which
 * is what every value assertion depends on.
 *
 * All data is synthetic (AGENTS.md).
 */
final class NativeApiScenario
{
    public readonly SigningScenario $signing;

    public readonly Workspace $workspace;

    private function __construct(?Workspace $workspace = null)
    {
        $this->signing = SigningScenario::create()->bind();
        $this->workspace = $workspace ?? $this->signing->workspace;
    }

    public static function create(): self
    {
        return new self;
    }

    /**
     * Issue a credential in this scenario's workspace.
     *
     * @param  list<Scope>  $scopes
     */
    public function credential(
        array $scopes = [Scope::EnvelopesRead, Scope::EnvelopesWrite, Scope::TemplatesRead, Scope::WebhooksManage],
        ?DateTimeInterface $expiresAt = null,
        string $label = 'native api test',
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
     * The Bearer form. The raw form is exercised on its own in the auth test.
     *
     * @return array<string, string>
     */
    public static function headers(IssuedServiceCredential $issued, array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$issued->secret] + $extra;
    }

    /**
     * A published template version over this scenario's document revision.
     *
     * @param  array<string, mixed>|null  $schema  Defaults to the sequential two-signer fixture.
     */
    public function publishedTemplateVersion(
        ?array $schema = null,
        string $name = 'Synthetic mutual NDA template',
        bool $published = true,
    ): TemplateVersion {
        $template = new Template([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'description' => 'Two-signer synthetic NDA.',
            'created_by' => $this->signing->user->getKey(),
        ]);
        $template->save();

        $document = FieldSchemaDocument::fromArray($schema ?? SigningFixtures::sequentialTwoSigners());

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
            'published_at' => $published ? CarbonImmutable::now() : null,
            'created_by' => $this->signing->user->getKey(),
        ]);
        $version->save();

        if ($published) {
            $template->current_version_id = $version->getKey();
            $template->save();
        }

        return $version->refresh()->load('template', 'documentRevision.document');
    }
}
