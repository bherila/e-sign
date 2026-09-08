<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * DELETE /workspaces/{workspace}/templates/{template}/aliases.
 *
 * The alias is in the body rather than the path on purpose: a provider template id is
 * somebody else's string and may contain characters that would have to be percent-encoded,
 * and an alias that round-trips through a URL segment is one encoding bug away from
 * unmapping the wrong template.
 */
class DestroyTemplateAliasRequest extends WorkspaceTemplateRequest
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
            'alias' => ['required', 'string', 'min:1', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alias.required' => 'Name the alias to remove.',
        ];
    }

    public function alias(): string
    {
        return trim((string) $this->input('alias'));
    }
}
