# Delegated application access

Lets the identity provider's application-access page manage who has which role in this
application's workspaces (issue #111). It uses delegated access contract version 2 from
`bherila/auth-laravel` (0.14 or later), and the provider side is auth-manager's application-access
page ([auth-manager#56](https://github.com/bherila/auth-manager/issues/56)).

This deployment depends on auth-manager for it: nothing here can be managed centrally without
it. The members page ([members.md](members.md)) works whether or not delegated access is
enabled, and whether or not the provider can be reached.

| | |
|---|---|
| Endpoint | `POST /application-access`, called by the provider's server only |
| Adapter | `App\Domain\Identity\DelegatedAccess\ApplicationAccessAdapter` |
| Rules | `App\Domain\Identity\Services\WorkspaceMembers`, the same service the members page uses |
| Tests | `tests/Feature/Identity/DelegatedAccessTest.php` |

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
- **Roles:** `owner`, `admin`, `sender` and `auditor`, as advertised in `capabilities`. There is no
  application-wide administrator.
- **Memberships:** only in workspaces the actor manages. A membership somebody holds anywhere else
  is neither shown nor changeable.
- **Editable:** exactly what the members page would allow. Only an owner changes an owner's
  membership, and the last owner of a workspace is never removed.
- **Revisions:** a read returns a digest of what it showed. An update must name it, and it is
  compared under the same row locks the changes take, so a concurrent change is a `409`, never an
  overwrite.
- **Provisioning:** an update with a `null` revision creates an account for a subject nobody has
  bound yet. The account is bound to this provider and that exact subject, uses the provider's
  display name, and gets the memberships asked for. Its email is a placeholder until the person
  signs in. It never adopts an existing row found by address.

Every change writes the same audit events the members page does, with `via: delegated_access` in
the payload. Provisioning also writes `identity.user_provisioned`.

## Configuration

| Variable | Meaning |
|---|---|
| `ESIGN_DELEGATED_ACCESS_ENABLED` | `true` to register the behaviour; the route answers 404 otherwise |
| `ESIGN_DELEGATED_ACCESS_ISSUER` | the provider's exact HTTPS issuer URL |
| `ESIGN_DELEGATED_ACCESS_ENDPOINT` | this deployment's exact HTTPS `/application-access` URL, as the provider is configured to call it |
| `ESIGN_DELEGATED_ACCESS_APPLICATION` | this application's key in the provider's registry |
| `ESIGN_DELEGATED_ACCESS_PUBLIC_KEYS` | the provider's integration public keys, `key-id\|/path/to/public.pem`, comma-separated |

Use the provider's dedicated integration key pair, never its OAuth signing keys. To rotate, list
both public keys, switch the provider to the new key id, then remove the old one. If any listed key
file cannot be read, every request is refused: a partly loaded key set would silently stop
accepting one key.

At the provider, the application needs:
- a registry entry with this key;
- the endpoint URL above;
- `contract_version: 2`;
- delegated access (and writes) enabled.

## Before enabling

- **Nonce table.** The migration `2026_09_07_000000_create_delegated_access_nonces.php` creates
  `bherila_auth_delegated_nonces`. It must be on the primary writable connection, shared by every
  web worker, and never restored to an earlier snapshot while assertions are live.
- **Proxy.** Nothing in front of the application may rewrite the request body or strip the
  `Authorization` header. The assertion is bound to the exact bytes of the body.
