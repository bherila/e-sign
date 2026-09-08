<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Preparation\Templates\TemplateAliasSource;
use Illuminate\Validation\Rule;

/**
 * POST /workspaces/{workspace}/templates/{template}/aliases.
 *
 * Maps a foreign identifier — normally a provider template id the consumer hardcodes
 * (docs/HANDOFF.md section 2) — onto this template. The alias never becomes the template's
 * own id; native ids and imported-provider aliases stay separate fields.
 *
 * `source` is validated against App\Domain\Preparation\Templates\TemplateAliasSource rather
 * than accepted as free text, because "whose namespace is this id from" is the question a
 * migration audit has to answer.
 *
 * The character class is deliberately permissive on the inside and strict at the edges: a
 * provider's id is not ours to reformat, but an alias with leading or trailing whitespace,
 * a newline, or a control character would break every log line and header it appears in.
 */
class StoreTemplateAliasRequest extends WorkspaceTemplateRequest
{
    protected function permission(): WorkspacePermission
    {
        return WorkspacePermission::CreateTemplates;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alias' => ['required', 'string', 'min:1', 'max:191', 'regex:/^\S(?:[^\x00-\x1f\x7f]*\S)?$/u'],
            'source' => ['required', Rule::enum(TemplateAliasSource::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alias.required' => 'Give the alias the other system uses for this template.',
            'alias.regex' => 'An alias cannot start or end with whitespace or contain control characters.',
            'source.required' => 'Say which system this alias comes from: '
                .implode(' or ', TemplateAliasSource::values()).'.',
        ];
    }

    public function alias(): string
    {
        return trim((string) $this->input('alias'));
    }

    public function source(): TemplateAliasSource
    {
        return TemplateAliasSource::from((string) $this->input('source'));
    }
}
