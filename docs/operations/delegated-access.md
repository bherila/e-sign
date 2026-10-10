# Delegated application access

Lets the identity provider's application-access page manage who has which role in this
application's workspaces (issue #111). It uses delegated access contract version 3 from
`bherila/auth-laravel` (0.21 or later), whose endpoint does the transport work and serves version 3
only, and the provider side is auth-manager's application-access
page ([auth-manager#56](https://github.com/bherila/auth-manager/issues/56)).

This deployment depends on auth-manager for it: nothing here can be managed centrally without
it. The members page ([members.md](members.md)) works whether or not delegated access is
enabled, and whether or not the provider can be reached.

| | |
|---|---|
| Endpoint | `POST /application-access`, called by the provider's server only. Served by the package's `DelegatedAccessController`: body bound, assertion verification, nonce consumption, and contract validation of the request and the answer |
| Adapter | `App\Domain\Identity\DelegatedAccess\ApplicationAccessAdapter`, the package's `ApplicationAccessAdapter` bound in `AppServiceProvider`; configuration in `config/bherila-auth.php` under `delegated_access` |
| Rules | `App\Domain\Identity\Services\WorkspaceMembers`, the same service the members page uses |
| Tests | `tests/Feature/Identity/DelegatedAccessTest.php` (the endpoint) and `DelegatedAccessConformanceTest.php` (the package's conformance assertions) |

## How a request is trusted

1. **A signed actor assertion.** Every request carries an RS256 `application-access+jwt` from the
   provider. It is bound to this endpoint's exact URL, this application's registry key, `POST`, and
   the SHA-256 of the exact request body, and it lasts at most 60 seconds.
2. **Single use.** The assertion's nonce is consumed atomically in the
   `bherila_auth_delegated_nonces` table before the body is read. A replay is refused.
3. **No fallback.** No session, no CSRF, no API key and no OAuth token are accepted on this route.
4. **The subject names an account, not an authority.** The actor is matched to a local account
   through its identity binding under `OAUTH_PROVIDER`, exactly as sign-in matches it. That account
   must be active and must own or administer at least one workspace. Being an administrator at the
   provider grants nothing here.

## What the provider can see and change

- **Workspaces:** only those the actor owns or administers.
- **People:** accounts bound under this provider that belong to one of those workspaces.
- **Search:** both listings take an optional `query`, matched case-insensitively as a substring of
  the person's name or stored email, or of the workspace name. It narrows the actor's own listing
  and never reaches past it; a cursor works only with the search that issued it. (On SQLite, case
  is folded for ASCII letters only.)
- **Roles:** `owner`, `admin`, `sender` and `auditor`, as advertised in `capabilities`, each with a
  one-sentence description. There is no application-wide administrator.
- **Memberships:** only in workspaces the actor manages. A membership somebody holds anywhere else
  is neither shown nor changeable.
- **Editable:** exactly what the members page would allow. Only an owner changes an owner's
  membership, and the last owner of a workspace is never removed, so that membership is reported
  not editable.
- **Revisions:** a read returns a digest of what it showed. An update must name it, and it is
  compared under the same row locks the changes take, so a concurrent change is a `409`, never an
  overwrite.
- **Provisioning:** an update with a `null` revision creates an account for a subject nobody has
  bound yet. The account is bound to this provider and that exact subject, uses the provider's
  display name, and gets the memberships asked for. Its email is a placeholder until the person
  signs in. It never adopts an existing row found by address.
- **Removal:** `remove` takes away every membership the actor manages, through the same service,
  locks and revision check as an update. The account, its binding, its memberships in workspaces
  the actor cannot see and every envelope, artifact and audit event stay. If any membership shown is
  not editable, the whole removal is refused (`protected_membership`) and nothing changes. With
  nothing to remove it is a no-op that keeps the revision. `allowed_edits.remove` says in advance
  whether it would go through. Suspending an account or deleting data are not part of it.
- **Metadata:** a read and each listed person carry `provisioned_at` (when the account was created)
  and `last_seen_at` (the last sign-in through this provider, null until then). They are
  observations, never part of the revision and never authorization. A first sign-in is not recorded
  separately, so `first_sign_in_at` is not sent.

Every change writes the same audit events the members page does, with `via: delegated_access`,
the provider's `request_id` (the assertion's single-use id) and `operation_id` (the same on every
retry of one action) in the payload. Provisioning also writes `identity.user_provisioned`.

## Operation receipts

Every `update` and `remove` carries an `operation_id`. The package endpoint claims it in
`bherila_auth_delegated_receipts` before the adapter runs: a repeat of the same request is answered
from the stored receipt without reaching the adapter, the same id on a different request is
refused, and the provider can ask for the outcome of an uncertain write with the `receipt`
operation. Receipts are kept for 30 days.

## Configuration

| Variable | Meaning |
|---|---|
| `ESIGN_DELEGATED_ACCESS_ENABLED` | `true` to register the behaviour; the route answers 404 otherwise |
| `ESIGN_DELEGATED_ACCESS_WRITES_ENABLED` | `true` to accept updates, provisioning and removals; off by default, so reads can be piloted with writes impossible |
| `ESIGN_DELEGATED_ACCESS_ISSUER` | the provider's exact HTTPS issuer URL; must equal `OAUTH_PROVIDER_URL` (a trailing slash aside), or every request is refused |
| `ESIGN_DELEGATED_ACCESS_ENDPOINT` | this deployment's exact HTTPS `/application-access` URL, as the provider is configured to call it |
| `ESIGN_DELEGATED_ACCESS_APPLICATION` | this application's key in the provider's registry |
| `ESIGN_DELEGATED_ACCESS_PUBLIC_KEYS` | the public half of this application's own key at the provider, `key-id\|/path/to/public.pem`; a second entry only while rotating |

Use a key pair the provider holds for this application alone, never its OAuth signing keys, a key
shared with other applications, or another application's key: anyone holding a trusted private key
can mint requests this deployment accepts. To rotate, list both public keys, switch the provider to
the new key id, then remove the old one. If any listed key
file cannot be read, every request is refused: a partly loaded key set would silently stop
accepting one key.

At the provider, the application needs:
- a registry entry with this key;
- the endpoint URL above;
- `contract_version: 3` (this deployment refuses every other version);
- its own signing key;
- delegated access enabled, and writes listed for this application only once they are approved.

Both sides gate writes: the provider's per-application list and this deployment's
`ESIGN_DELEGATED_ACCESS_WRITES_ENABLED`. Either can stop them alone.

## Before enabling

- **Nonce table.** The migration `2026_09_07_000000_create_delegated_access_nonces.php` creates
  `bherila_auth_delegated_nonces`. It must be on the primary writable connection, shared by every
  web worker, and never restored to an earlier snapshot while assertions are live.
- **Receipts table.** The migration `2026_10_10_000000_create_delegated_access_receipts.php`,
  published from the package (`--tag=bherila-auth-delegated-access-migrations`), creates
  `bherila_auth_delegated_receipts` on the same connection. Until it exists every write is refused
  with `receipt_storage_unavailable`.
- **Pruning.** The scheduler runs `bherila-auth:prune-delegated-nonces` daily: expired nonces, and
  receipts older than 30 days.
- **Proxy.** Nothing in front of the application may rewrite the request body or strip the
  `Authorization` header. The assertion is bound to the exact bytes of the body.

## Integration test

The CI job `auth-manager-integration` checks both sides of the contract as deployed. It uses:
- auth-manager's `main` branch and this commit's e-sign;
- one disposable MySQL container;
- a certificate and an integration key pair generated for the run.

It seeds an owner, a sender and an unbound newcomer (`.github/integration/auth-manager/`), then
drives auth-manager's own `DelegatedAccessTransport` through a sequence of steps:
- capabilities, workspaces, subjects and a read;
- a role change;
- a stale revision, which must be a conflict;
- provisioning the newcomer;
- provisioning the newcomer again, which must also be a conflict.

A change on either side that breaks the other fails this job. The move to contract version 3 is
coordinated across both repositories: while auth-manager's `main` cannot speak version 3, the
job warns that it was skipped instead of failing, and runs in full once it can.
