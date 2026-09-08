<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials\Console;

use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Shared plumbing for the `esign:credential:*` commands.
 *
 * The pattern follows BootstrapOwnerCommand: a refusal names the problem and then the remedy
 * on its own line, and no command ever guesses at a missing argument.
 */
abstract class ServiceCredentialCommand extends Command
{
    /**
     * Resolve `--workspace` by slug or public id. Never by autoincrement id: an operator
     * copying a number out of a database session is how the wrong tenant gets a credential.
     */
    protected function workspace(string $value): ?Workspace
    {
        $workspace = Workspace::query()
            ->where('slug', $value)
            ->orWhere('public_id', $value)
            ->first();

        if ($workspace === null) {
            $this->refuse(
                "No workspace matches '{$value}'.",
                'Pass --workspace=<slug> or --workspace=<public id>. Run esign:bootstrap-owner to create one.',
            );
        }

        return $workspace;
    }

    protected function credential(string $prefix): ?ServiceCredential
    {
        $credential = ServiceCredential::query()
            ->forPrefix(trim($prefix))
            ->with('workspace')
            ->first();

        if ($credential === null) {
            $this->refuse(
                "No credential has the prefix '{$prefix}'.",
                'Run esign:credential:list to see the prefixes that exist. The prefix is the public part of the key, '.
                'for example esk_k3n9x2ab7q1z — not the whole secret.',
            );
        }

        return $credential;
    }

    /**
     * Parse `--expires`.
     *
     * Accepts a shorthand interval (`30d`, `12h`, `45m`) or anything Carbon can parse
     * (`2027-01-31`, `2027-01-31 09:00`, `+6 months`). Returns null for "no expiry", which
     * is what an absent flag means.
     */
    protected function expiry(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $interval = $this->interval($value, quiet: true);

        if ($interval !== null) {
            return CarbonImmutable::instance(Carbon::now())->add($interval);
        }

        try {
            $expiry = CarbonImmutable::parse($value);
        } catch (Exception) {
            $this->refuse(
                "'{$value}' is not a date or an interval.",
                'Use an interval such as 30d, 12h, or 45m, or a date such as 2027-01-31 or "2027-01-31 09:00".',
            );

            return null;
        }

        return $expiry;
    }

    /**
     * Parse an interval such as `24h`. Returns null when the value is not in that shape.
     */
    protected function interval(string $value, bool $quiet = false): ?CarbonInterval
    {
        if (preg_match('/^(\d+)\s*([dhm])$/i', trim($value), $matches) !== 1) {
            if (! $quiet) {
                $this->refuse(
                    "'{$value}' is not an interval.",
                    'Use a whole number of days, hours, or minutes: 7d, 24h, 30m. Use 0h for no overlap at all.',
                );
            }

            return null;
        }

        $amount = (int) $matches[1];

        return match (mb_strtolower($matches[2])) {
            'd' => CarbonInterval::days($amount),
            'h' => CarbonInterval::hours($amount),
            default => CarbonInterval::minutes($amount),
        };
    }

    /**
     * Print a freshly minted secret. This is the only place a plaintext secret is ever
     * displayed, and it is displayed once: it is not stored, not logged, and not recoverable
     * from the database, so an operator who closes the terminal rotates.
     */
    protected function presentSecret(IssuedServiceCredential $issued): void
    {
        $this->newLine();
        $this->warn('Copy this secret now. It is shown once, it is not stored, and it cannot be recovered — only rotated.');
        $this->newLine();
        $this->line('  '.$issued->secret);
        $this->newLine();
        $this->line('Send it over a channel you would send a database password over, and put it straight into the');
        $this->line("consumer's secret store. Do not paste it into a ticket, a chat message, or a shell history.");
        $this->newLine();
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $extra
     */
    protected function presentCredential(ServiceCredential $credential, array $extra = []): void
    {
        $this->table(['Field', 'Value'], [
            ['workspace', $credential->workspace?->slug ?? (string) $credential->workspace_id],
            ['label', $credential->label],
            ['prefix', $credential->prefix],
            ['scopes', implode(', ', $credential->scopes)],
            ['status', $credential->status()],
            ['expires', $credential->expires_at?->toIso8601String() ?? 'never'],
            ['last used', $credential->last_used_at?->toIso8601String() ?? 'never'],
            ...$extra,
        ]);
    }

    protected function refuse(string $problem, ?string $remedy = null): int
    {
        $this->error($problem);

        if ($remedy !== null) {
            $this->line($remedy);
        }

        return self::FAILURE;
    }

    /**
     * Domain refusals carry their remedy after a newline so each part prints on its own
     * line instead of being wrapped into nonsense.
     */
    protected function refuseWith(string $message): int
    {
        [$problem, $remedy] = array_pad(explode("\n", $message, 2), 2, null);

        return $this->refuse((string) $problem, $remedy);
    }

    protected function trimmedOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
