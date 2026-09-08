<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Services\BootstrapOutcome;
use App\Domain\Identity\Services\BootstrapRefused;
use App\Domain\Identity\Services\OwnerBootstrapper;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Provision the first workspace owner.
 *
 * The application has no default administrator and never promotes the first person to sign
 * in. Owner authority is created here, by an operator who names the exact identity, and
 * nowhere else. See docs/operations/bootstrap.md for the runbook this command sits inside.
 *
 * Two modes, mutually exclusive:
 *
 *   SSO         --issuer= --subject=   bind an identity-provider subject
 *   Standalone  --user=                an existing local user, by id or email
 *
 * `--user` by email looks a user up; it never creates one. An address is contact data, so
 * "provision the owner with this email" is not a thing this command can be asked to do.
 */
class BootstrapOwnerCommand extends Command
{
    protected $signature = 'esign:bootstrap-owner
        {--issuer= : Identity provider this subject belongs to (SSO mode; requires --subject)}
        {--subject= : Stable provider subject identifier (SSO mode; requires --issuer)}
        {--user= : Existing local user id or email address (standalone mode)}
        {--workspace= : Workspace slug; created when it does not exist}
        {--name= : Display name used only when the workspace is created}';

    protected $description = 'Grant workspace owner to an explicitly named identity. Idempotent; never promotes by email or first login.';

    public function handle(OwnerBootstrapper $bootstrapper): int
    {
        $issuer = $this->trimmedOption('issuer');
        $subject = $this->trimmedOption('subject');
        $localUser = $this->trimmedOption('user');
        $slug = $this->trimmedOption('workspace');
        $name = $this->trimmedOption('name');

        if ($slug === null) {
            return $this->refuse(
                '--workspace is required.',
                'Pass the workspace slug to provision, for example --workspace=acme. It is created if it does not exist.',
            );
        }

        if (! $this->isValidSlug($slug)) {
            return $this->refuse(
                "'{$slug}' is not a valid workspace slug.",
                'Use lowercase letters, digits, and single hyphens, 1-191 characters, for example --workspace=acme-legal.',
            );
        }

        $ssoRequested = $issuer !== null || $subject !== null;

        if ($ssoRequested && $localUser !== null) {
            return $this->refuse(
                'Choose one mode: --issuer/--subject (SSO) or --user (standalone), not both.',
                'An SSO owner is identified by their provider subject; a standalone owner by an existing local user row.',
            );
        }

        if (! $ssoRequested && $localUser === null) {
            return $this->refuse(
                'No owner identity was given.',
                'Pass --issuer=<provider> --subject=<provider subject> for SSO, or --user=<id|email> for an existing local user. '.
                'This command never picks an owner for you.',
            );
        }

        $actor = AuditActor::console('esign:bootstrap-owner');

        try {
            if ($ssoRequested) {
                if ($issuer === null || $subject === null) {
                    return $this->refuse(
                        'SSO mode needs both --issuer and --subject; '.($issuer === null ? '--issuer' : '--subject').' is missing.',
                        'Identity binds on the (issuer, subject) tuple. Neither half identifies anyone on its own, and an email address is not a substitute.',
                    );
                }

                $missing = $this->missingOAuthSettings();

                if ($missing !== []) {
                    return $this->refuse(
                        'SSO mode was requested but the OAuth client is not configured: '.implode(', ', $missing).' '.
                        (count($missing) === 1 ? 'is' : 'are').' not set.',
                        'Register this application at the identity provider first, then set those values in .env. '.
                        'See step 1 of docs/operations/bootstrap.md. Use --user=<id|email> instead for a standalone installation.',
                    );
                }

                $provider = $this->configuredProvider();

                if ($issuer !== $provider) {
                    return $this->refuse(
                        "--issuer must be '{$provider}', the configured OAUTH_PROVIDER; '{$issuer}' was given.",
                        'Sign-in resolves a binding on the provider key, so a binding stored under any other issuer can '.
                        'never be matched: the owner would authenticate and find no membership, and re-running with the '.
                        'correct issuer would leave the first placeholder user orphaned. Pass the provider key, not its URL.',
                    );
                }

                $outcome = $bootstrapper->bootstrapWithBinding($issuer, $subject, $slug, $name, $actor);
            } else {
                $user = $this->resolveLocalUser((string) $localUser);

                if (! $user instanceof User) {
                    return self::FAILURE;
                }

                $outcome = $bootstrapper->bootstrapLocalUser($user, $slug, $name, $actor);
            }
        } catch (BootstrapRefused $exception) {
            // Only this domain's own refusals. Catching RuntimeException here
            // also caught QueryException, which extends PDOException extends
            // RuntimeException, so a dropped connection or a column-length
            // violation was printed as an operator refusal with the SQL and its
            // bindings in place of the problem. A database fault now propagates
            // with its stack trace, where it belongs.
            //
            // Refusals carry their remedy after a newline so each part is
            // printed on its own and neither is wrapped into nonsense.
            [$problem, $remedy] = array_pad(explode("\n", $exception->getMessage(), 2), 2, null);

            return $this->refuse((string) $problem, $remedy);
        }

        $this->report($outcome);

        return self::SUCCESS;
    }

    /**
     * Resolve `--user` to an existing row. A numeric value is an id; anything containing an
     * `@` is an email that must already belong to a user. Nothing here creates an account.
     */
    private function resolveLocalUser(string $value): ?User
    {
        if (ctype_digit($value)) {
            $user = User::find((int) $value);

            if ($user === null) {
                return $this->refuseAndReturnNull(
                    "No local user with id {$value} exists.",
                    'Create the user first, or use --issuer/--subject to provision an SSO identity binding.',
                );
            }

            return $user;
        }

        if (! str_contains($value, '@')) {
            return $this->refuseAndReturnNull(
                "'{$value}' is neither a numeric user id nor an email address.",
                'Pass --user=<id> or --user=<email of an existing local user>.',
            );
        }

        $user = User::where('email', $value)->first();

        if ($user === null) {
            return $this->refuseAndReturnNull(
                "No local user with email {$value} exists.",
                'This command never creates a user from an email address; identity binds on issuer and subject, and an address is only contact data. '.
                'Create the local user first, or use --issuer/--subject to provision an SSO identity binding.',
            );
        }

        return $user;
    }

    /**
     * Settings SSO mode needs that this deployment has not set.
     *
     * OAUTH_PROVIDER_URL is read from `esign.oauth_provider_url`, not from
     * `bherila-auth.oauth_client.base_url`: the package defaults that key to
     * `https://bherila.net`, so it is never empty and this guard could never
     * fire on it. An operator who set the client credentials and forgot the URL
     * therefore passed the check and provisioned an owner bound to somebody
     * else's provider — and the runbook advertises this refusal as the check
     * that the registration step actually happened.
     *
     * @return list<string>
     */
    private function missingOAuthSettings(): array
    {
        $missing = [];

        // Both provider settings are read from `esign.*`, which mirrors the raw
        // environment, rather than from the package keys, which default to
        // `https://bherila.net` and `bherila` respectively and so can never
        // read as unset.
        $settings = [
            'OAUTH_PROVIDER' => config('esign.oauth_provider'),
            'OAUTH_PROVIDER_URL' => config('esign.oauth_provider_url'),
            'OAUTH_CLIENT_ID' => config('bherila-auth.oauth_client.client_id'),
        ];

        foreach ($settings as $envName => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $envName;
            }
        }

        return $missing;
    }

    /**
     * The provider key a sign-in will resolve bindings on.
     *
     * Deliberately the package's resolved value, not `esign.oauth_provider`:
     * this has to be the string the callback will actually match a binding
     * against. missingOAuthSettings() has already established that the operator
     * chose one, so the two agree by the time this is compared.
     */
    private function configuredProvider(): string
    {
        $provider = config('bherila-auth.oauth_client.provider');

        return is_string($provider) ? trim($provider) : '';
    }

    private function report(BootstrapOutcome $outcome): void
    {
        $workspace = $outcome->workspace;

        foreach ($outcome->notes as $note) {
            $this->comment('Note: '.$note);
        }

        if ($outcome->changed()) {
            foreach ($outcome->changes as $change) {
                $this->line('  + '.$change);
            }

            $this->info('Owner provisioned.');
        } else {
            $this->info('Nothing to do; the requested state already exists.');
        }

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['workspace', $workspace->name],
            ['workspace slug', $workspace->slug],
            ['workspace public id', $workspace->public_id],
            ['user id', (string) $outcome->user->getKey()],
            ['role', $outcome->membership->role->value],
            ['identity binding', $outcome->binding === null
                ? 'none (standalone local user)'
                : $outcome->binding->issuer.' / '.$outcome->binding->subject],
            ['first sign-in', $outcome->binding === null
                ? 'n/a'
                : ($outcome->binding->last_seen_at?->toDateTimeString() ?? 'not yet — complete a browser login to verify')],
        ]);
    }

    private function refuse(string $problem, ?string $remedy = null): int
    {
        $this->error($problem);

        if ($remedy !== null) {
            $this->line($remedy);
        }

        return self::FAILURE;
    }

    private function refuseAndReturnNull(string $problem, ?string $remedy = null): null
    {
        $this->refuse($problem, $remedy);

        return null;
    }

    private function trimmedOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isValidSlug(string $slug): bool
    {
        return mb_strlen($slug) <= 191 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }
}
