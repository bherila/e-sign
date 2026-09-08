<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * GET /workspaces/{workspace}/documents/{document}/revisions/{revision}/{download,view}.
 *
 * Same permission as reading the metadata: the bytes are the document. The authorization
 * check runs on this request, for this user, on every single hit — which is the whole
 * argument for streaming rather than presigning. A presigned URL is checked once, at
 * minting time, and then works for anyone holding it until it expires.
 */
class DownloadRevisionRequest extends WorkspaceDocumentRequest
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
