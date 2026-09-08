<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Shared resolution and authorization for every document route.
 *
 * Route parameters are resolved here rather than by implicit model binding, because
 * implicit binding would find a workspace the caller is not a member of and then rely on
 * the policy to say no — which distinguishes "forbidden" from "absent" and lets an outsider
 * confirm that a given ULID exists. Every lookup instead goes through
 * `Workspace::whereMemberOf()`, exactly as the Identity module requires, so a
 * cross-workspace probe by autoincrement id or by public ULID gets a 404 either way.
 *
 * The nesting is enforced, not assumed: a document is looked up inside the resolved
 * workspace and a revision inside the resolved document, so a valid id from another tenant
 * is a 404 rather than a download.
 *
 * A 403 is therefore reserved for the case it actually describes: a member of this
 * workspace whose role does not carry the required permission.
 */
abstract class WorkspaceDocumentRequest extends FormRequest
{
    private ?Workspace $workspace = null;

    private ?Document $document = null;

    private ?DocumentRevision $revision = null;

    /** The permission the caller's workspace role must carry for this route. */
    abstract protected function permission(): WorkspacePermission;

    public function authorize(): bool
    {
        return Gate::forUser($this->currentUser())->allows($this->permission()->value, $this->workspace());
    }

    public function workspace(): Workspace
    {
        return $this->workspace ??= Workspace::query()
            ->whereMemberOf($this->currentUser())
            ->where('public_id', $this->routeValue('workspace'))
            ->firstOrFail();
    }

    public function document(): Document
    {
        return $this->document ??= Document::query()
            ->inWorkspace($this->workspace())
            ->where('public_id', $this->routeValue('document'))
            ->firstOrFail();
    }

    public function revision(): DocumentRevision
    {
        return $this->revision ??= $this->document()
            ->revisions()
            ->where('public_id', $this->routeValue('revision'))
            ->firstOrFail();
    }

    public function currentUser(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            // The `auth` middleware runs first, so this is unreachable over HTTP; it exists
            // so the failure is loud rather than an authorization check against null.
            throw new RuntimeException('A document route ran without an authenticated user.');
        }

        return $user;
    }

    private function routeValue(string $name): string
    {
        $value = $this->route($name);

        return is_string($value) ? $value : '';
    }
}
