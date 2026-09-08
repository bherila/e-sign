<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Shared resolution and authorization for every template route.
 *
 * The same rules the document routes follow, for the same reasons
 * (App\Http\Requests\Documents\WorkspaceDocumentRequest): route parameters are resolved here
 * rather than by implicit model binding, because implicit binding would find a workspace the
 * caller is not a member of and then rely on the policy to say no — which distinguishes
 * "forbidden" from "absent" and lets an outsider confirm that a ULID exists. Every lookup
 * goes through `Workspace::whereMemberOf()`, so a cross-workspace probe by autoincrement id
 * or by public ULID is a 404 either way.
 *
 * The nesting is enforced, not assumed: a template is looked up inside the resolved
 * workspace and a version inside the resolved template, so a valid identifier from another
 * tenant is a 404 at every level. A 403 is reserved for the case it describes — a member of
 * this workspace whose role does not carry the permission.
 *
 * ## Two ways to name a version
 *
 * `{version}` accepts either the per-template number a human quotes (`/versions/2`) or the
 * version's public ULID. Both resolve inside the already-resolved template, so neither can
 * reach another tenant's version, and integration code that stores "v2" does not have to
 * look a ULID up first. The route pattern in routes/templates.php admits exactly these two
 * shapes, so anything else is a 404 at the router.
 */
abstract class WorkspaceTemplateRequest extends FormRequest
{
    /** A version number: 1 to 999999999, no leading zero. */
    public const VERSION_NUMBER_PATTERN = '/^[1-9][0-9]{0,8}$/';

    private ?Workspace $workspace = null;

    private ?Template $template = null;

    private ?TemplateVersion $templateVersion = null;

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

    public function template(): Template
    {
        return $this->template ??= Template::query()
            ->inWorkspace($this->workspace())
            ->where('public_id', $this->routeValue('template'))
            ->firstOrFail();
    }

    public function templateVersion(): TemplateVersion
    {
        if ($this->templateVersion instanceof TemplateVersion) {
            return $this->templateVersion;
        }

        $value = $this->routeValue('version');
        $versions = $this->template()->versions();

        return $this->templateVersion = preg_match(self::VERSION_NUMBER_PATTERN, $value) === 1
            ? $versions->where('version', (int) $value)->firstOrFail()
            : $versions->where('public_id', $value)->firstOrFail();
    }

    public function currentUser(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            // The `auth` middleware runs first, so this is unreachable over HTTP; it exists
            // so the failure is loud rather than an authorization check against null.
            throw new RuntimeException('A template route ran without an authenticated user.');
        }

        return $user;
    }

    protected function routeValue(string $name): string
    {
        $value = $this->route($name);

        return is_string($value) ? $value : '';
    }
}
