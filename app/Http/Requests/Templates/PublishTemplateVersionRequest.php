<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * POST /workspaces/{workspace}/templates/{template}/versions/{version}/publish.
 *
 * A POST with no body, because publishing takes no options: it stamps `published_at` on the
 * version the URL names and makes it the template's current version. It is not idempotent
 * and does not pretend to be — publishing an already published version is a 409, not a
 * silent success, because the second caller's intent (probably "publish my newer draft") was
 * not carried out.
 */
class PublishTemplateVersionRequest extends WorkspaceTemplateRequest
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
        return [];
    }
}
