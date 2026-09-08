<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials\Console;

use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;

/**
 * List credentials and their state. Prefixes only — there is no stored secret to print.
 */
class ListServiceCredentialsCommand extends ServiceCredentialCommand
{
    protected $signature = 'esign:credential:list
        {--workspace= : Workspace slug or public id; omit to list every workspace}';

    protected $description = 'List API credentials with their scopes, status, and last use.';

    public function handle(): int
    {
        $workspaceValue = $this->trimmedOption('workspace');
        $workspace = null;

        if ($workspaceValue !== null) {
            $workspace = $this->workspace($workspaceValue);

            if (! $workspace instanceof Workspace) {
                return self::FAILURE;
            }
        }

        $credentials = ServiceCredential::query()
            // Eager-loaded so a long list is two queries rather than one per row.
            ->with(['workspace', 'rotatedFrom'])
            ->when($workspace !== null, fn ($query) => $query->where('workspace_id', $workspace?->getKey()))
            ->orderBy('workspace_id')
            ->orderByDesc('id')
            ->get();

        if ($credentials->isEmpty()) {
            $this->info($workspace === null
                ? 'No API credentials have been issued.'
                : "No API credentials have been issued in '{$workspace->slug}'.");
            $this->line('Issue one with esign:credential:issue --workspace=<slug> --label=<label> --scope=<scope>.');

            return self::SUCCESS;
        }

        $this->table(
            ['Workspace', 'Prefix', 'Label', 'Scopes', 'Status', 'Expires', 'Last used', 'Rotated from'],
            $credentials->map(fn (ServiceCredential $credential): array => [
                $credential->workspace?->slug ?? (string) $credential->workspace_id,
                $credential->prefix,
                $credential->label,
                implode(' ', $credential->scopes),
                $credential->status(),
                $credential->expires_at?->toDateTimeString() ?? '—',
                $credential->last_used_at?->toDateTimeString() ?? 'never',
                $credential->rotatedFrom?->prefix ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
