<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Console\CreateUserCommand;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `esign:create-user` — how a standalone installation gets its first account.
 *
 * It creates an account and nothing else. Everything about authority stays where it was:
 * `esign:bootstrap-owner` grants owner, this command only prints the invitation to run it.
 */
class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'local'];

    public function test_it_creates_an_account_with_a_generated_password_and_prints_it_once(): void
    {
        $status = Artisan::call('esign:create-user', [
            '--name' => 'Ada Lovelace',
            '--email' => 'ada@example.test',
        ]);

        $this->assertSame(0, $status);

        $output = Artisan::output();
        $this->assertStringContainsString('Generated password', $output);
        $this->assertStringContainsString('esign:bootstrap-owner', $output);

        $user = User::where('email', 'ada@example.test')->sole();
        $this->assertSame('Ada Lovelace', $user->name);

        // Created with no authority whatsoever, which is the whole point of the separate
        // bootstrap step this command points at.
        $this->assertSame(0, $user->workspaceMemberships()->count());
        $this->assertDatabaseCount('workspaces', 0);

        $this->assertDatabaseHas('esign_audit_events', [
            'action' => 'identity.local_user_created',
            'subject_id' => (string) $user->getKey(),
        ]);
    }

    public function test_the_generated_password_is_the_one_that_signs_in(): void
    {
        Artisan::call('esign:create-user', ['--name' => 'Ada Lovelace', '--email' => 'ada@example.test']);

        preg_match('/Generated password[^\n]*\n\s*(\S+)/', Artisan::output(), $matches);
        $password = $matches[1] ?? '';

        $this->assertNotSame('', $password);
        $this->assertTrue(Hash::check($password, (string) User::sole()->password));

        $this->post('/login', ['email' => 'ada@example.test', 'password' => $password])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_generated_password_with_console_markup_characters_is_shown_verbatim(): void
    {
        // Str::password() draws from a symbol set that includes < and >; the console formatter
        // would otherwise treat "<b>...</b>" as a style tag and print a different password than
        // the one that was hashed.
        $pinned = 'Ab<b>cd</b>Ef<info>12345678!';

        $this->app[Kernel::class]->registerCommand(new class($pinned) extends CreateUserCommand
        {
            public function __construct(private readonly string $pinned)
            {
                parent::__construct();
            }

            protected function generatePassword(): string
            {
                return $this->pinned;
            }
        });

        Artisan::call('esign:create-user', ['--name' => 'Grace Hopper', '--email' => 'grace@example.test']);

        $this->assertStringContainsString('  '.$pinned, Artisan::output());
        $this->assertTrue(Hash::check($pinned, (string) User::sole()->password));
    }

    public function test_a_supplied_password_is_used_and_never_echoed(): void
    {
        Artisan::call('esign:create-user', [
            '--name' => 'Ada Lovelace',
            '--email' => 'ada@example.test',
            '--password' => 'a-sufficiently-long-secret',
        ]);

        $output = Artisan::output();
        $this->assertStringNotContainsString('a-sufficiently-long-secret', $output);
        $this->assertStringNotContainsString('Generated password', $output);

        $this->assertTrue(Hash::check('a-sufficiently-long-secret', (string) User::sole()->password));
    }

    public function test_it_refuses_a_short_password(): void
    {
        $status = Artisan::call('esign:create-user', [
            '--name' => 'Ada Lovelace',
            '--email' => 'ada@example.test',
            '--password' => 'short',
        ]);

        $this->assertSame(1, $status);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_an_address_that_already_has_a_local_account(): void
    {
        User::factory()->create(['email' => 'ada@example.test']);

        $status = Artisan::call('esign:create-user', [
            '--name' => 'Someone Else',
            '--email' => 'ada@example.test',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('already exists', Artisan::output());
        $this->assertSame(1, User::count());
    }

    public function test_it_refuses_an_invalid_address(): void
    {
        $status = Artisan::call('esign:create-user', ['--name' => 'Ada', '--email' => 'not-an-address']);

        $this->assertSame(1, $status);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_when_a_name_or_address_is_missing(): void
    {
        $this->assertSame(1, Artisan::call('esign:create-user', ['--email' => 'ada@example.test']));
        $this->assertSame(1, Artisan::call('esign:create-user', ['--name' => 'Ada Lovelace']));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_created_account_can_then_be_made_the_first_owner(): void
    {
        Artisan::call('esign:create-user', ['--name' => 'Ada Lovelace', '--email' => 'ada@example.test']);

        $user = User::sole();

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $this->assertSame('owner', $user->workspaceMemberships()->sole()->role->value);
    }
}
