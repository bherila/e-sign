<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * GET /workspaces/{workspace}/templates/{template}/versions/{version} and its
 * `schema.json` export.
 *
 * `view`, so an auditor can read a version and export its canonical field schema — which is
 * most of what auditing a template means — without being able to draft or publish one.
 */
class ShowTemplateVersionRequest extends WorkspaceTemplateRequest
{
    protected function permission(): WorkspacePermission
    {
        return WorkspacePermission::View;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
