<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials\Console;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Credentials\UnknownScope;
use App\Domain\Identity\Models\Workspace;
use InvalidArgumentException;
use RuntimeException;

/**
 * Issue an API credential for a workspace. See docs/operations/service-credentials.md.
 *
 * The secret is printed once, here, and never again: there is no `show` command because
 * there is nothing to show — only a salted digest is stored.
 */
class IssueServiceCredentialCommand extends ServiceCredentialCommand
{
    protected $signature = 'esign:credential:issue
        {--workspace= : Workspace slug or public id}
        {--label= : What this credential is for, e.g. "consumer production"}
        {--scope=* : Scope to grant; repeat the flag or comma-separate}
        {--expires= : Optional expiry: an interval (30d, 12h, 45m) or a date (2027-01-31)}';

    protected $description = 'Issue a workspace-scoped API credential and print its secret once.';

    public function handle(ServiceCredentialIssuer $issuer): int
    {
        $workspaceValue = $this->trimmedOption('workspace');
        $label = $this->trimmedOption('label');

        if ($workspaceValue === null) {
            return $this->refuse(
                '--workspace is required.',
                'A credential is a principal inside exactly one workspace; there is no global API key. '.
                'Pass --workspace=<slug|public id>.',
            );
        }

        if ($label === null) {
            return $this->refuse(
                '--label is required.',
                'The label is how you will know what you are revoking six months from now, '.
                'for example --label="consumer production".',
            );
        }

        $scopes = $this->requestedScopes();

        if ($scopes === []) {
            return $this->refuse(
                'At least one --scope is required.',
                'Known scopes: '.implode(', ', Scope::values()).'. '.
                'Grant only what the integration calls; write does not imply read.',
            );
        }

        $workspace = $this->workspace($workspaceValue);

        if (! $workspace instanceof Workspace) {
            return self::FAILURE;
        }

        $expiry = $this->expiry($this->trimmedOption('expires'));

        if ($this->trimmedOption('expires') !== null && $expiry === null) {
            return self::FAILURE;
        }

        try {
            $issued = $issuer->issue(
                $workspace,
                $label,
                $scopes,
                AuditActor::console($this->name ?? 'esign:credential:issue'),
                $expiry,
            );
        } catch (UnknownScope|InvalidArgumentException|RuntimeException $exception) {
            return $this->refuseWith($exception->getMessage());
        }

        $this->info("Issued credential {$issued->credential->prefix} in workspace '{$workspace->slug}'.");
        $this->presentSecret($issued);
        $this->presentCredential($issued->credential);

        return self::SUCCESS;
    }

    /**
     * `--scope=envelopes:read --scope=envelopes:write` and
     * `--scope=envelopes:read,envelopes:write` both work. Unknown values are rejected by the
     * issuer, not silently dropped here.
     *
     * @return list<string>
     */
    private function requestedScopes(): array
    {
        /** @var array<int, string> $values */
        $values = (array) $this->option('scope');
        $scopes = [];

        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $scopes[] = $part;
                }
            }
        }

        return $scopes;
    }
}
