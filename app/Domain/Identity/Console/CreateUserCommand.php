<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\AuthMode;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Create a local user account for a standalone installation.
 *
 * This exists so a fresh install can reach a first sign-in without anyone opening a
 * database client. It creates an account and nothing else: no workspace, no membership, no
 * role. `esign:bootstrap-owner --user=<id>` is still the only way to grant owner, and this
 * command prints that next step rather than taking it.
 *
 * There is no registration page anywhere in the application, in either mode. Accounts are
 * created here or provisioned by the identity provider, so "sign up" is not a state an
 * unauthenticated visitor can reach.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'esign:create-user
        {--name= : The person\'s display name}
        {--email= : Contact address, and the address they will type at the login form}
        {--password= : Password to set; omit to be prompted, or to have one generated}';

    protected $description = 'Create a local user for a standalone installation. Grants no role; run esign:bootstrap-owner next.';

    /**
     * Short enough to type from a screen, long enough that the generated value is not the
     * weak link. Applied to operator-supplied passwords too — a command that accepts
     * `--password=admin` has a default credential in all but name.
     */
    private const MINIMUM_PASSWORD_LENGTH = 12;

    public function handle(AuditRecorder $audit): int
    {
        $name = $this->trimmedOption('name');
        $email = $this->trimmedOption('email');

        if ($name === null || $email === null) {
            return $this->refuse(
                'Both --name and --email are required.',
                'For example: php artisan esign:create-user --name="Ada Lovelace" --email="ada@example.com"',
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            return $this->refuse(
                "'{$email}' is not a valid email address.",
                'Pass a deliverable address; it is how this person identifies themselves at the login form.',
            );
        }

        // Email carries no unique index — it is contact data, and two identity-provider
        // subjects reporting the same address are two people. Local password login has no
        // such second key, so an address that already exists is refused here instead: the
        // login form would otherwise find two candidates and be unable to say which.
        if (User::where('email', $email)->exists()) {
            return $this->refuse(
                "A user with the email address {$email} already exists.",
                'Local sign-in resolves an account by address, so addresses must not repeat among local users. '.
                'Use a different address, or reset the existing account instead of creating a second one.',
            );
        }

        $password = $this->resolvePassword($generated);

        if ($password === null) {
            return self::FAILURE;
        }

        if (AuthMode::current()->isSso()) {
            $this->warn(
                'This deployment runs in single sign-on mode, so there is no password login form. '.
                'The account will exist but cannot sign in until ESIGN_AUTH_MODE=local is set.',
            );
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $audit->record(
            AuditActor::console('esign:create-user'),
            'identity.local_user_created',
            $user,
            ['email' => $email, 'password_generated' => $generated],
        );

        $this->info("Created user #{$user->getKey()} ({$name} <{$email}>).");

        if ($generated) {
            $this->newLine();
            $this->line('Generated password (shown once, not recoverable):');
            $this->line('  '.$password);
        }

        $this->newLine();
        $this->comment('This account has no workspace and no role. To make it the first owner:');
        $this->line("  php artisan esign:bootstrap-owner --user={$user->getKey()} --workspace=<slug>");

        return self::SUCCESS;
    }

    /**
     * The password to set, and whether this command invented it.
     *
     * Three routes in, one rule out: nothing shorter than the minimum is accepted, however
     * it arrived. A prompt is preferred to `--password` because an option is in the shell
     * history and in the process list; a generated value is preferred to a prompt when
     * there is nobody at the terminal to type one.
     */
    private function resolvePassword(?bool &$generated): ?string
    {
        $generated = false;
        $supplied = $this->option('password');

        if (is_string($supplied) && $supplied !== '') {
            if (mb_strlen($supplied) < self::MINIMUM_PASSWORD_LENGTH) {
                $this->refuse(
                    'The supplied password is shorter than '.self::MINIMUM_PASSWORD_LENGTH.' characters.',
                    'Pass a longer one, or omit --password to have a strong one generated.',
                );

                return null;
            }

            return $supplied;
        }

        if ($this->canPrompt()) {
            $typed = (string) $this->secret('Password (leave blank to generate one)');

            if ($typed !== '') {
                if (mb_strlen($typed) < self::MINIMUM_PASSWORD_LENGTH) {
                    $this->refuse('That password is shorter than '.self::MINIMUM_PASSWORD_LENGTH.' characters.');

                    return null;
                }

                return $typed;
            }
        }

        $generated = true;

        return Str::password(24);
    }

    /**
     * Whether there is actually somebody at a terminal to answer a prompt.
     *
     * `isInteractive()` alone is not enough: it stays true when the command is invoked
     * programmatically with standard input attached to a pipe, and reading that pipe means
     * reading somebody else's data and blocking forever. A terminal check makes the
     * generated-password path the automatic answer for scripts and test runners, which is
     * the behaviour they want anyway.
     */
    private function canPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        return @stream_isatty($stream ?? STDIN);
    }

    private function refuse(string $problem, ?string $remedy = null): int
    {
        $this->error($problem);

        if ($remedy !== null) {
            $this->line($remedy);
        }

        return self::FAILURE;
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
}
