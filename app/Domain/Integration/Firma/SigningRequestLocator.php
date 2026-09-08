<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\EnvelopeService;
use App\Domain\Integration\Native\TemplateCatalog;
use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Signing\Models\Envelope;

/**
 * Resolving the identifiers a facade caller uses.
 *
 * ## `{id}` is the envelope's public ULID
 *
 * Upstream types every id as a UUID and the recorded fixtures carry UUIDs. This facade
 * returns the envelope's own public id, which is a ULID, and that is an
 * **intentional difference** rather than an oversight: minting a second identifier space so
 * that a compatibility surface could show UUIDs would mean two ids for one agreement, a
 * mapping table to keep them in step, and a support conversation every time they disagreed.
 * A consumer that treats the id as an opaque string — which the fixtures' own README says it
 * must, since one stored id turned out not to be a Firma id at all — is unaffected.
 *
 * The lookup itself is App\Domain\Integration\Native\EnvelopeService::find(), unchanged, so
 * the workspace constrains the query *before* the id from the URL is used and another
 * tenant's id is a 404 exactly like one that does not exist
 * (`docs/HANDOFF.md` section 10).
 *
 * ## `template_id` is ours **or** theirs
 *
 * A template can be named two ways:
 *
 * 1. by this application's own `templates.public_id`, or
 * 2. by an alias in `template_aliases` — the provider id the consumer already has hardcoded.
 *
 * Both are accepted, and the native id is tried first because it is the namespace this
 * application controls. `docs/HANDOFF.md` section 6 requires native ids and imported
 * provider aliases to stay separate fields, which is what makes this possible without
 * either id ever becoming the other.
 *
 * A template **version** public id is accepted as a third form. Nothing on this surface
 * emits one — the create response echoes the template's id, precisely so that a round trip
 * works — but an integration that read `envelopes.source_template_version_id` from anywhere
 * else should not get a `404` for naming something real, and a version resolves to exactly
 * one template.
 *
 * A template that resolves but has no published version is a `422`, not a `404`: the caller
 * named something real and it is not ready, and telling them it does not exist would send
 * them looking for a typo.
 */
final readonly class SigningRequestLocator
{
    public function __construct(
        private EnvelopeService $envelopes,
        private TemplateCatalog $templates,
        private TemplateService $templateService,
    ) {}

    /**
     * @throws FirmaException 404, whether the id is unknown or belongs to another workspace.
     */
    public function find(Workspace $workspace, string $id): Envelope
    {
        try {
            return $this->envelopes->find($workspace, $id);
        } catch (ApiException) {
            throw FirmaException::notFound('signing request');
        }
    }

    /**
     * The published template version a `template_id` names.
     *
     * @throws FirmaException
     */
    public function templateVersion(Workspace $workspace, string $templateId): TemplateVersion
    {
        $template = $this->template($workspace, trim($templateId));
        $versions = $this->templates->publishedVersions($template);

        if ($versions === []) {
            throw FirmaException::of(
                FirmaErrorCode::UnprocessableEntity,
                'Template "'.$templateId.'" has no published version, so nothing can be sent from it yet.',
                ['template' => $template->public_id],
            );
        }

        // The newest published version. An integration that stored a template id expects the
        // current agreement text, and an envelope copies its source, so a later edit cannot
        // change an agreement already out for signature.
        return end($versions)->load('template', 'documentRevision.document');
    }

    /**
     * @throws FirmaException
     */
    private function template(Workspace $workspace, string $templateId): Template
    {
        try {
            return $this->templates->find($workspace, $templateId);
        } catch (ApiException) {
            // Not one of ours. Try the imported-provider namespace.
        }

        $byAlias = $this->templateService->resolveAlias($workspace, $templateId);

        if ($byAlias instanceof Template) {
            return $byAlias->load(['versions' => static fn ($query) => $query->published()]);
        }

        // Constrained by workspace before the id is compared, like every other lookup here.
        $byVersion = TemplateVersion::query()
            ->whereIn('template_id', Template::query()->inWorkspace($workspace)->select('id'))
            ->where('public_id', $templateId)
            ->first();

        if ($byVersion instanceof TemplateVersion) {
            return $this->templates->find($workspace, (string) $byVersion->template()->value('public_id'));
        }

        throw FirmaException::notFound('template');
    }

    /**
     * The field a caller addressed by `variable_name` or by `id`.
     *
     * Both addressing modes have to work: the consumer patches prefills by variable name
     * while its own template field ids are hardcoded (`docs/HANDOFF.md` §2). An unresolvable
     * name is an **error**, never ignored — upstream silently drops an override it cannot
     * match, which is precisely the vendor behaviour the matrix rules out, because a dropped
     * prefill is a blank in an executed agreement nobody noticed.
     *
     * A name that matches more than one field is also an error. Two fields can legitimately
     * share a variable name (the recorded fixtures show `full_name` twice on one agreement,
     * once read-only and once editable), so the caller has to say which by id.
     *
     * @throws FirmaException
     */
    public function fieldOf(Envelope $envelope, ?string $id, ?string $variableName): FieldDefinition
    {
        return $this->field($envelope->fieldSchema(), $id, $variableName);
    }

    /**
     * The same resolution against a schema that has no envelope yet — a template version's,
     * on create, where prefills arrive before anything is persisted.
     *
     * @throws FirmaException
     */
    public function field(FieldSchemaDocument $schema, ?string $id, ?string $variableName): FieldDefinition
    {
        if ($id !== null && trim($id) !== '') {
            $field = $schema->field(trim($id)) ?? $schema->fieldByAlias(trim($id));

            if ($field instanceof FieldDefinition) {
                return $field;
            }

            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'There is no field with id "'.trim($id).'". An override that matches nothing '
                .'is refused rather than ignored: a dropped prefill is a blank in a signed agreement.',
                ['field_ids' => array_map(static fn (FieldDefinition $f): string => $f->id, $schema->fields)],
            );
        }

        if ($variableName === null || trim($variableName) === '') {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'A field must be addressed by `id` or by `variable_name`.',
            );
        }

        $wanted = trim($variableName);
        $normalised = FieldPlacement::normaliseVariableName($wanted);
        $matches = [];

        foreach ($schema->fields as $field) {
            $candidates = array_filter([
                SigningRequestFields::variableName($field),
                $field->alias,
                $field->prefill?->variable,
            ]);

            foreach ($candidates as $candidate) {
                if ($candidate === $wanted || FieldPlacement::normaliseVariableName($candidate) === $normalised) {
                    $matches[$field->id] = $field;

                    break;
                }
            }
        }

        if ($matches === []) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'There is no field with variable name "'.$wanted.'". An override that matches '
                .'nothing is refused rather than ignored: a dropped prefill is a blank in a signed agreement.',
                ['variable_names' => array_values(array_filter(array_map(
                    static fn (FieldDefinition $f): ?string => SigningRequestFields::variableName($f),
                    $schema->fields,
                )))],
            );
        }

        if (count($matches) > 1) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Variable name "'.$wanted.'" matches '.count($matches).' fields, so it '
                .'does not say which one to change. Address it by `id` instead.',
                ['field_ids' => array_keys($matches)],
            );
        }

        return array_values($matches)[0];
    }
}
