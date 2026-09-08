<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

use App\Domain\Identity\Models\Workspace;
use RuntimeException;

/**
 * The service principal acting in the current request.
 *
 * Registered as a scoped binding in AppServiceProvider, so there is one instance per request
 * and per queue job and never a value left over from the previous caller.
 * App\Http\Middleware\AuthenticateServiceCredential is the only thing that binds it.
 *
 * Downstream code asks this object for the workspace *before* it looks a resource up:
 *
 *     $envelope = Envelope::query()
 *         ->where('workspace_id', $principal->workspaceIdOrFail())
 *         ->where('public_id', $request->route('id'))
 *         ->firstOrFail();
 *
 * and not the other way round. Loading a record by its identifier and then comparing its
 * workspace is the bug docs/HANDOFF.md section 10 rules out ("identifiers and foreign keys
 * must not bypass scope"): it leaks existence through timing and error shape even when it
 * ends in a 403, and one forgotten comparison is a cross-tenant read.
 *
 * The credential is also on `$request->attributes`, which is the right place for middleware
 * and controllers that already have a Request. This service is for everything that does not
 * — domain services, jobs, policies — so that "which workspace am I in" never has to be
 * threaded through a constructor argument that someone can forget to pass.
 */
final class CurrentPrincipal
{
    private ?ServiceCredential $credential = null;

    /**
     * Called once, by the authentication middleware, after the secret has been verified.
     */
    public function bind(ServiceCredential $credential): void
    {
        $this->credential = $credential;
    }

    public function isServiceCredential(): bool
    {
        return $this->credential !== null;
    }

    public function credential(): ?ServiceCredential
    {
        return $this->credential;
    }

    public function credentialOrFail(): ServiceCredential
    {
        return $this->credential ?? throw new RuntimeException(
            'No service credential is bound to this request. '.
            'Routes that read the current principal must run behind the service-credential middleware.'
        );
    }

    public function workspace(): ?Workspace
    {
        return $this->credential?->workspace;
    }

    public function workspaceOrFail(): Workspace
    {
        $workspace = $this->credentialOrFail()->workspace;

        if (! $workspace instanceof Workspace) {
            throw new RuntimeException('The bound service credential has no workspace.');
        }

        return $workspace;
    }

    public function workspaceId(): ?int
    {
        return $this->credential?->workspace_id;
    }

    public function workspaceIdOrFail(): int
    {
        return $this->credentialOrFail()->workspace_id;
    }

    /**
     * @return list<Scope>
     */
    public function grantedScopes(): array
    {
        return $this->credential?->grantedScopes() ?? [];
    }

    public function hasScope(Scope $scope): bool
    {
        return $this->credential !== null && $this->credential->hasScope($scope);
    }

    /**
     * Fail closed unless the current principal holds `$scope`.
     *
     * @throws MissingScope
     */
    public function requireScope(Scope $scope): void
    {
        $scope->requiresScope($this->grantedScopes());
    }
}
