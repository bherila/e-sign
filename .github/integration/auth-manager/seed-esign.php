<?php

declare(strict_types=1);

/*
 * Creates e-sign's side of the auth-manager integration job (CI only).
 *
 * Run from the root of this repository with the state file `drive.php seed` wrote:
 *
 *   php .github/integration/auth-manager/seed-esign.php <state.json>
 *
 * The actor owns one workspace and the target is a sender in it, each bound to the auth-manager
 * account id as its subject, exactly as sign-in would bind them. The newcomer is left unbound so
 * the job can provision it. Every record is synthetic and lives only in the job's disposable
 * database.
 */

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ids = json_decode((string) file_get_contents($argv[1] ?? ''), true, 4, JSON_THROW_ON_ERROR);
$issuer = (string) config('bherila-auth.oauth_client.provider');

$workspace = Workspace::factory()->create(['name' => 'CI Workspace']);

foreach (['actor' => WorkspaceRole::Owner, 'target' => WorkspaceRole::Sender] as $who => $role) {
    $user = User::factory()->create(['name' => 'CI '.ucfirst($who)]);
    IdentityBinding::create(['user_id' => $user->getKey(), 'issuer' => $issuer, 'subject' => $ids[$who]]);
    WorkspaceMembership::create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
}

echo "seeded e-sign\n";
