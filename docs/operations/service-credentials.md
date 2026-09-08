# Runbook: service credentials (API keys)

An API caller is a **service principal**, not a person. It has no membership row, no local
role, and no identity binding. It has exactly one workspace, a fixed list of scopes, and a
secret. That is the whole of its authority, and it is decided before any resource is looked
up.

Credentials are created, rotated, and revoked from the console. There is no self-service UI
for them in this release, and there is deliberately no way to read a secret back: only a
salted digest is stored, so a lost secret is rotated, never recovered.

Run every command as the application user, from the application root, with the deployment's
`.env` in place.

---

## Scopes

| Scope | Grants |
|---|---|
| `envelopes:read` | Read envelopes, recipients, fields, and downloads |
| `envelopes:write` | Create, send, patch, and cancel envelopes |
| `templates:read` | Read templates and template versions |
| `templates:write` | Create and version templates |
| `webhooks:manage` | Administer webhook endpoints and inspect deliveries |
| `compat:firma-v1` | Call the Firma-compatible facade under `/functions/v1/signing-request-api` |

Two rules, both deliberate:

- **Nothing is implied.** `envelopes:write` does not grant `envelopes:read`. An integration
  that reads and writes is granted both. Implication rules read as a convenience and behave
  as privilege escalation the first time a scope is added in the wrong place.
- **`compat:firma-v1` is an admission ticket, not a grant.** It says "this credential may
  call the compatibility facade". The facade's routes still require the same resource scopes
  as the native API, because both surfaces call the same domain services
  (`docs/ARCHITECTURE.md`). A credential for the consumer's migration therefore looks like
  `--scope=compat:firma-v1 --scope=envelopes:read --scope=envelopes:write`.

An unknown scope is rejected at issue time and the whole command fails; it is never silently
dropped. `App\Domain\Identity\Credentials\Scope` is the authority.

---

## Issue

```bash
php artisan esign:credential:issue \
  --workspace=acme \
  --label="consumer production" \
  --scope=compat:firma-v1 \
  --scope=envelopes:read \
  --scope=envelopes:write
```

`--workspace` takes a slug or a public id — never the autoincrement id. `--scope` repeats or
comma-separates. `--expires` is optional and takes an interval (`30d`, `12h`, `45m`) or a
date (`2027-01-31`, `"2027-01-31 09:00"`); omitted, the credential does not expire.

The command prints the secret **once**:

```
Issued credential esk_k3n9x2ab7q1z in workspace 'acme'.

Copy this secret now. It is shown once, it is not stored, and it cannot be recovered — only rotated.

  esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
```

Put it straight into the consumer's secret store. Do not paste it into a ticket, a chat
message, or a shell history. If it ends up somewhere it should not be, rotate with
`--overlap=0h` or revoke; do not hope.

The `esk_k3n9x2ab7q1z` part is the **prefix**: the public half of the key. It is stored in
the clear, printed in tables, and recorded in audit events and logs. It is safe to quote in
a ticket, and it is what every other command identifies a credential by.

`--label` is not decoration. It is how you will know what you are about to revoke six months
from now.

## Rotate

```bash
php artisan esign:credential:rotate esk_k3n9x2ab7q1z
```

Rotation mints a **successor** and gives the predecessor a deadline instead of killing it.
For 24 hours by default, both secrets authenticate, so a consumer can be redeployed with the
new one without a synchronised restart. After that the old one fails closed with 401.

- `--overlap=0h` cuts over immediately. That is the right flag when you are rotating
  *because* a secret leaked.
- `--overlap=7d` widens the window for a slow deployment.
- The successor inherits the predecessor's workspace, label, and scopes, and its expiry
  unless `--expires` says otherwise. A credential issued to expire on a fixed date does not
  become immortal by being rotated, and the overlap never pushes the old secret past the
  expiry it already had.
- `rotated_from_id` links successor to predecessor, so the chain is readable afterwards.

A revoked or already-expired credential is not rotated — there is nothing to overlap with.
Issue a new one.

## Revoke

```bash
php artisan esign:credential:revoke esk_k3n9x2ab7q1z --reason="secret pasted into a ticket"
```

Immediate and final. The next request presenting that secret gets 401. There is no un-revoke
— only a new credential — and revoking twice is a no-op that writes no second audit event.

Revocation never touches an envelope, an artifact, or an audit event. Executed instruments
outlive the credential that created them.

## List

```bash
php artisan esign:credential:list --workspace=acme   # omit --workspace for every workspace
```

Prefixes, labels, scopes, status (`active` / `expired` / `revoked`), expiry, last use, and
the credential each one was rotated from. No secrets, because there are none to print.

`last used` is written at most once a minute per credential: it answers "is this key still in
use, can I revoke it?", and writing it on every call would turn every read-only API request
into a database write.

---

## The two Authorization header syntaxes

Both of these authenticate, and they are equivalent:

```http
Authorization: esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
Authorization: Bearer esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
```

**Why the raw form is accepted.** The API this application is compatible with sends its key
in `Authorization` with no scheme, and the consumer's existing clients do the same.
`docs/HANDOFF.md` section 10 makes accepting it a requirement: "The authorization header must
accept the consumer's raw API key, not require Bearer-only syntax." Requiring `Bearer` would
mean either patching every consumer or shipping a facade that is not actually compatible —
and the point of the facade is that the consumer's code does not have to change.

**Why that is not sloppy.** There is no ambiguity to resolve: one of ours always starts with
`esk_`, and a bearer token never does. A `Basic` header is left alone rather than being
treated as a mangled key. `Bearer` is matched case-insensitively because HTTP auth schemes
are.

Native callers and standard tooling should use `Bearer`. The 401 response advertises
`WWW-Authenticate: Bearer realm="esign", error="invalid_token"` for that reason; the raw form
stays accepted regardless.

## What a route gets, and what it must still do

`service-credential` middleware puts the verified credential and its workspace on the
request, and binds `App\Domain\Identity\Credentials\CurrentPrincipal`. `require-scope` then
enforces the scope the route names:

```php
Route::middleware(['service-credential', 'require-scope:envelopes:read'])
    ->get('/api/v1/envelopes/{id}', ...);
```

Several scopes mean **all** of them (`require-scope:envelopes:read,envelopes:write`). There
is no "any of" form.

A route then constrains its query by the principal's workspace **before** it uses the
identifier from the URL:

```php
$envelope = Envelope::query()
    ->where('workspace_id', $principal->workspaceIdOrFail())
    ->where('public_id', $request->route('id'))
    ->firstOrFail();
```

and never the other way round. Loading a record by id and comparing its workspace afterwards
leaks existence through timing and error shape even when it ends in a 403, and one forgotten
comparison is a cross-tenant read. `docs/HANDOFF.md` section 10: "identifiers and foreign
keys must not bypass scope."

## Failure responses

| Situation | Status | Body `message` |
|---|---|---|
| No `Authorization` header | 401 | `No API credential was presented.` |
| Malformed, unknown prefix, or wrong secret | 401 | `Invalid API credential.` |
| Revoked | 401 | `This API credential has been revoked.` |
| Expired (including a lapsed rotation overlap) | 401 | `This API credential expired at <time>.` |
| Workspace soft-deleted | 401 | `Invalid API credential.` |
| Valid credential, scope not granted | 403 | `This API credential is not granted '<scope>'.` |

An unknown prefix and a wrong secret give the same answer on purpose: a caller that has not
proved it holds a secret learns nothing about which prefixes exist. Once the secret has
matched, a specific reason is safe and saves an integrator an afternoon.

## How secrets are stored, and what is in the logs

`secret_hash` is `hash('sha256', secret_salt.':'.<full plaintext>)` with a fresh 128-bit
per-row salt, compared with `hash_equals`. It is not bcrypt or argon2, and that is a
considered choice: those buy cost against offline brute force of a *human-chosen* secret, and
there is none here — the plaintext is 43 characters drawn from a 36-character alphabet
(~222 bits) minted by the server. What they would cost is real, since a lookup by prefix
finds exactly one row, so the work factor would land on the latency of every API request. The
salt is still per row so a stolen table yields no precomputation and no cross-row comparison.
The reasoning lives in full on `App\Domain\Identity\Credentials\CredentialSecret`, which is
the only place that would have to change if operator-chosen secrets ever became a thing.

Failed authentications log a warning with the **prefix and a reason** — never the secret, in
the message or in the context, and an exception that quotes a presented secret has its
message withheld before it can be reported. Keep PHP's default
`zend.exception_ignore_args=On` in production so a stack trace cannot carry it either.

## Verify

**The credential exists and is what you meant.**

```bash
php artisan esign:credential:list --workspace=acme
```

**Nothing stored it in the clear.** No column contains the plaintext:

```bash
php artisan tinker --execute="
  App\Domain\Identity\Credentials\ServiceCredential::all()
    ->each(fn (\$c) => print(\$c->prefix.' '.\$c->status().' '.implode(',', \$c->scopes).PHP_EOL));
"
```

**The audit trail recorded it.**

```bash
php artisan tinker --execute="
  App\Domain\Identity\Audit\AuditEvent::whereIn('action', [
    'identity.service_credential_issued',
    'identity.service_credential_rotated',
    'identity.service_credential_revoked',
  ])->get()->each(fn (\$e) => print(\$e->created_at.' '.\$e->action.' '.(\$e->payload['credential_prefix'] ?? '?').PHP_EOL));
"
```

Audit payloads carry the prefix, the label, the scopes, and the workspace. They never carry a
secret.

## Afterwards

- Only `owner` can rotate service credentials through the application
  (`WorkspacePermission::RotateCredentials`); the console commands are operator tools and
  answer to shell access, so treat shell access accordingly.
- Rotate on a schedule you decide, and immediately on any suspicion. `--overlap=0h` is the
  suspicious-secret path.
- Deleting a workspace does not delete its credentials: the foreign key is `RESTRICT` and
  workspaces are soft-deleted. A credential in a soft-deleted workspace stops authenticating
  and stays on the record.
