<?php

declare(strict_types=1);

/*
 * Drives auth-manager's real delegated access transport against a running e-sign (CI only).
 *
 * Run from the root of an auth-manager checkout:
 *
 *   php drive.php seed <state.json>   create the actor, target and newcomer accounts, e-sign's
 *                                     registration and the actor's grant; write their ids
 *   php drive.php run <state.json>    call e-sign through DelegatedAccessTransport and check
 *                                     every answer
 *
 * The transport signs each request, sends it over HTTPS, and validates each answer with the
 * contract, so this exercises both sides of delegated access contract version 2 as deployed.
 * Every account is synthetic and lives only in the job's disposable database.
 */

use App\Http\Middleware\EnsureCredentialVersion;
use App\Http\Middleware\RequireRecentPasskeyAuthentication;
use App\Models\PassportClient;
use App\Models\RegisteredApplication;
use App\Models\User;
use App\Services\DelegatedAccess\DelegatedAccessTransport;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$step = $argv[1] ?? null;
$statePath = $argv[2] ?? null;

if (! in_array($step, ['seed', 'run'], true) || ! is_string($statePath)) {
    fwrite(STDERR, "usage: php drive.php seed|run <state.json>\n");
    exit(2);
}

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$application = 'e-sign';

if ($step === 'seed') {
    $ids = [];
    foreach (['actor', 'target', 'newcomer'] as $who) {
        $ids[$who] = (string) User::factory()->create(['user_role' => 'user'])->id;
    }

    $client = PassportClient::create([
        'id' => (string) Str::uuid(),
        'name' => 'CI e-sign',
        'secret' => Str::random(40),
        'grant_types' => ['authorization_code'],
        'redirect_uris' => ['https://esign.example.test/auth/callback'],
        'revoked' => false,
    ]);
    RegisteredApplication::create(['key' => $application, 'name' => 'CI e-sign', 'launch_url' => 'https://esign.example.test', 'enabled' => true])
        ->clients()->attach($client->id);
    DB::table('oauth_client_grants')->insert(['oauth_client_id' => $client->id, 'subject' => $ids['actor'], 'created_at' => now(), 'updated_at' => now()]);

    file_put_contents($statePath, json_encode($ids, JSON_THROW_ON_ERROR));
    echo "seeded auth-manager\n";
    exit(0);
}

// The application map is deployment-owned PHP configuration with no environment variable, so
// this run's entry is set here. Everything else the transport reads comes from the environment.
config(['delegated-access.applications' => [$application => [
    'endpoint' => 'https://esign.example.test/application-access',
    'contract_version' => 2,
]]]);

$ids = json_decode((string) file_get_contents($statePath), true, 4, JSON_THROW_ON_ERROR);
$actor = User::query()->findOrFail($ids['actor']);

// The transport acts only for a signed-in session with a current credential version and, for
// writes, a recent passkey confirmation. This is the state the application-access page runs in.
$request = Request::create('/ci-delegated-access', 'POST');
$request->setUserResolver(static fn (): User => $actor);
$request->setLaravelSession(app('session.store'));
$request->session()->put(EnsureCredentialVersion::SESSION_KEY, (int) $actor->credential_version);
RequireRecentPasskeyAuthentication::recordCredentialVerification($request);

$transport = app(DelegatedAccessTransport::class);
$send = static fn (array $operation): array => $transport->send($request, $application, $operation);

$check = static function (bool $passed, string $what): void {
    if (! $passed) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
    echo "ok: {$what}\n";
};

$refused = static function (callable $call, string $outcome, string $what) use ($check): void {
    try {
        $call();
    } catch (DelegatedAccessException $refusal) {
        $check($refusal->outcome === $outcome, "{$what} (outcome {$refusal->outcome})");

        return;
    }
    $check(false, "{$what} (it was accepted)");
};

try {
    $capabilities = $send(['operation' => 'capabilities']);
    $check(array_column($capabilities['controls']['workspace_roles'], 'id') === ['owner', 'admin', 'sender', 'auditor'], 'capabilities advertise the workspace roles');
    $check($capabilities['controls']['provisioning'] === true, 'capabilities advertise provisioning');

    $workspaces = $send(['operation' => 'workspaces']);
    $check(count($workspaces['workspaces']) === 1 && $workspaces['workspaces'][0]['label'] === 'CI Workspace', 'the actor sees exactly the workspace it owns');
    $workspace = $workspaces['workspaces'][0]['id'];

    $subjects = $send(['operation' => 'subjects']);
    $check(in_array($ids['target'], array_column($subjects['subjects'], 'subject'), true), 'the target is listed');

    $read = $send(['operation' => 'read', 'subject' => $ids['target']]);
    $check($read['provisioned'] === true && $read['access']['workspaces'] === [['id' => $workspace, 'role' => 'sender', 'editable' => true]], 'the target reads as a sender');

    $update = [
        'operation' => 'update',
        'subject' => $ids['target'],
        'expected_revision' => $read['revision'],
        'access' => ['application_admin' => false, 'workspaces' => [['id' => $workspace, 'role' => 'auditor']]],
    ];
    $saved = $send($update);
    $check($saved['access']['workspaces'][0]['role'] === 'auditor' && $saved['revision'] !== $read['revision'], 'an update changes the role and the revision');
    $refused(static fn (): array => $send($update), 'revision_conflict', 'the same update with the old revision is a conflict');

    $unprovisioned = $send(['operation' => 'read', 'subject' => $ids['newcomer']]);
    $check($unprovisioned['provisioned'] === false && $unprovisioned['allowed_edits']['provision'] === true, 'a newcomer is unprovisioned and may be provisioned');

    $provision = [
        'operation' => 'update',
        'subject' => $ids['newcomer'],
        'expected_revision' => null,
        'display_name' => 'CI Newcomer',
        'access' => ['application_admin' => false, 'workspaces' => [['id' => $workspace, 'role' => 'sender']]],
    ];
    $created = $send($provision);
    $check($created['provisioned'] === true && $created['access']['workspaces'][0]['role'] === 'sender', 'provisioning creates the account with its membership');
    $refused(static fn (): array => $send($provision), 'revision_conflict', 'provisioning the same subject twice is a conflict');
} catch (DelegatedAccessException $failure) {
    fwrite(STDERR, "FAIL: unexpected refusal {$failure->outcome} ({$failure->status})\n");
    exit(1);
}

echo "delegated access integration passed\n";
