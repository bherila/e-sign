# Runbook: bootstrap the first owner

BWH eSign ships with no administrator. There is no seeded account, no default password, and
no code path that promotes the first person to sign in. Owner authority exists only after an
operator runs `esign:bootstrap-owner` and names the exact identity that should hold it.

This runbook covers both installation shapes:

- **SSO mode** — the application delegates authentication to an OAuth identity provider
  through the `bherila/auth-laravel` client. Local identities bind to an `(issuer, subject)`
  tuple.
- **Standalone mode** — a self-hosted installation with local accounts and no external
  identity provider. Nothing here requires access to any hosted identity endpoint.

Run every command as the application user, from the application root, with the deployment's
`.env` in place.

---

## Step 0 — choose the mode

`ESIGN_AUTH_MODE` decides which sign-in surface exists. Exactly one mode's routes are
registered; the other mode's endpoints are absent, not merely disabled, so a standalone
installation has no OAuth callback to probe and an SSO installation has no password form to
attack.

| Value | Behaviour |
|---|---|
| `auto` (default) | SSO when `OAUTH_CLIENT_ID` and `OAUTH_PROVIDER_URL` are set, standalone when they are not. |
| `sso` | Always SSO. A missing OAuth setting is then a 503, not a silent fall back to a password form. |
| `local` | Always standalone password login, even where an OAuth client is configured. |

Set `ESIGN_AUTH_MODE=sso` on a deployment that is meant to use a provider. Under `auto`, an
`.env` that failed to load would quietly serve a password form instead of an outage.

Routes are decided at boot, so after changing this:

```bash
php artisan config:clear
php artisan route:clear
```

In either mode the person who signs in is not an administrator. They land on a page telling
them they have no workspace until an owner grants them a membership.

---

## Step 1 — register the OAuth client at the identity provider

*SSO mode only. Skip to step 3 for a standalone installation.*

At the identity provider, register this deployment as an application. Provide:

| Field | Value |
|---|---|
| Application label | the name operators and signers will see, e.g. `Acme eSign` |
| Homepage | the application's `APP_URL` |
| Redirect URI | exactly `<APP_URL>/auth/callback`, or whatever `OAUTH_REDIRECT_URI` is set to — an exact string, no wildcards |
| Role-management deep link | the application's workspace members page, so the provider can link an operator straight to it |
| Post-logout redirect | `<APP_URL>/`, which is where the application asks the provider to return people after sign-out |

Registration returns a client id and client secret. Put them in `.env`:

```dotenv
OAUTH_PROVIDER=your-provider-key
OAUTH_PROVIDER_URL=https://identity.example.com
OAUTH_CLIENT_ID=...
OAUTH_CLIENT_SECRET=...
OAUTH_REDIRECT_URI="${APP_URL}/auth/callback"
```

`OAUTH_PROVIDER` is the issuer key you will pass to `--issuer`. It is stored on every
identity binding, so changing it later invalidates every existing binding — decide it once.

Then reload configuration:

```bash
php artisan config:clear
```

`esign:bootstrap-owner` refuses to run in SSO mode until `OAUTH_PROVIDER_URL` and
`OAUTH_CLIENT_ID` are both set, and it names the ones that are missing. That refusal is the
check that this step actually happened.

## Step 2 — grant application access at the identity provider

*SSO mode only.*

Grant the future owner access to this application in the provider's directory, and note
their **subject identifier** — the provider's stable, opaque id for that person. It is not
their email address.

A directory grant admits someone to the application. It confers no workspace role: an
admitted person with no membership row can authenticate and then see nothing. That
separation is deliberate, and it is why step 3 exists.

Do not use an email address as the subject. Email is contact data — it changes hands, it
gets reused, and binding on it would let a renamed mailbox inherit an owner's authority.

## Step 3 — provision the local owner

### SSO mode

```bash
php artisan esign:bootstrap-owner \
  --issuer="your-provider-key" \
  --subject="<the subject identifier from step 2>" \
  --workspace="acme" \
  --name="Acme"
```

- `--workspace` is a slug: lowercase letters, digits, single hyphens. It is created if it
  does not exist.
- `--name` is the workspace display name, used only when the workspace is created. The
  command never renames an existing workspace; it says so and continues.
- The command creates a placeholder local user row to hang the binding on, with a
  placeholder name and an unroutable `@invalid` address. The owner's real name and email
  arrive from the provider at their first sign-in.

### Standalone mode

The local user must already exist. `esign:bootstrap-owner` looks users up; it never creates
one from an email address, because an address is contact data and "provision the owner with
this email" is not a thing it can be asked to do.

Create the account first:

```bash
php artisan esign:create-user --name="Ada Lovelace" --email="ada@example.com"
```

`esign:create-user` creates an account and nothing else — no workspace, no membership, no
role. It prompts for a password when there is a terminal to prompt at, generates a strong one
otherwise, and prints a generated password exactly once. There is no way to recover it
afterwards; if it scrolls away, delete the account and run the command again.

| Option | Meaning |
|---|---|
| `--name` | required; the display name |
| `--email` | required; contact address, and what this person types at the login form |
| `--password` | optional. Prefer the prompt: an option value is in your shell history and in the process list. Minimum 12 characters either way, so there is no such thing as `--password=admin`. |

It refuses an address that already belongs to a local account. Local sign-in resolves an
account by address, so local addresses must not repeat — `users.email` deliberately carries
no unique index (two identity-provider subjects reporting one address are two people), which
is why this is enforced here and in the login controller rather than by the database.

Then grant owner:

```bash
php artisan esign:bootstrap-owner --user=1 --workspace="acme" --name="Acme"
# or
php artisan esign:bootstrap-owner --user="ada@example.com" --workspace="acme"
```

### What to expect

On success the command prints what it changed and then the resulting state — workspace name,
slug, public id, user id, role, identity binding, and whether the owner has signed in yet.

The command is idempotent. Run it again with the same arguments and it prints
`Nothing to do; the requested state already exists.`, changes nothing, and appends no audit
event. That makes it safe in a deployment script.

Every run that changes something appends one `identity.owner_bootstrapped` row to
`esign_audit_events` with the console actor, the workspace, the issuer and subject, and the
list of changes. That table is append-only.

### If it refuses

Every refusal names the problem and what to do about it. Exit status is non-zero and nothing
is written — the whole run is one transaction.

| Message | Cause | Fix |
|---|---|---|
| `--workspace is required.` | no workspace given | pass `--workspace=<slug>` |
| `'…' is not a valid workspace slug.` | uppercase, spaces, or punctuation | use `lowercase-with-hyphens` |
| `No owner identity was given.` | neither mode selected | pass `--issuer`/`--subject` or `--user` |
| `Choose one mode: …` | both modes given | pick one |
| `SSO mode needs both --issuer and --subject` | half a tuple | supply both; neither half identifies anyone alone |
| `the OAuth client is not configured: …` | step 1 incomplete | set the named variables, then `php artisan config:clear` |
| `No local user with email … exists.` | standalone `--user=<email>` with no such row | create the user first; this command never creates an account from an address |
| `No local user with id … exists.` | wrong id | check the id |
| `Workspace '…' exists but is deleted.` | slug belongs to a soft-deleted workspace | restore it, or choose another slug |

## Step 4 — complete a browser login

### SSO mode

The owner opens `<APP_URL>/login` and clicks the single sign-on button. That starts an
authorization-code flow with PKCE at `/auth/redirect`; the provider authenticates them and
returns them to `/auth/callback`, which resolves the `(issuer, subject)` tuple to the local
user, stamps `last_seen_at`, and opens a session.

Their real name and email replace the placeholders the bootstrap command left. The email is
written as contact data and read by nothing that decides who they are.

Signing out posts to `/auth/logout`, which ends the local session and then hands off to the
provider's end-session endpoint. Ending only the local session would leave the provider still
recognising them, so the next protected page would hand them straight back with no prompt.

| Route | Method | Purpose |
|---|---|---|
| `/login` | GET | the sign-in page |
| `/auth/redirect` | GET | begins the authorization request |
| `/auth/callback` | GET | exchanges the code, resolves the binding, opens the session |
| `/auth/logout` | POST | signs out here and at the provider |

There is no `/login` POST, no registration route, and no way to become an administrator by
signing in.

### Standalone mode

The owner signs in at `<APP_URL>/login` with the address and password from
`esign:create-user`, and signs out by posting to `/logout`.

Failed attempts are counted in `auth_audit_log` and lock the account+source pair out after
five failures in fifteen minutes (`BHERILA_AUTH_THROTTLE_*`). Behind a proxy or CDN,
configure Laravel's trusted proxies as well, or every request resolves to the proxy's address
and the source half of that key groups every visitor together.

### Somebody who is admitted but has no workspace

Both modes land such a person on a page that says so and tells them to ask an owner. That is
the expected result of a directory grant on its own: authentication admits somebody to the
application, and a membership row is the only thing that gives them anything to do in it.

### Withdrawing access

Set `users.disabled_at` to refuse an account at every entry point — the SSO callback, the
password form, and the package's own middleware all consult the same gate. Deleting the
identity binding stops them authenticating at all. Neither touches an envelope, an artifact,
or an audit event.

## Step 5 — verify

Confirm the provisioning actually took effect, rather than assuming it did.

**Idempotency.** Rerun step 3 verbatim. It must print `Nothing to do` and exit zero.

**The membership exists and is `owner`.**

```bash
php artisan tinker --execute="
  \$w = App\Domain\Identity\Models\Workspace::where('slug', 'acme')->firstOrFail();
  \$w->memberships->each(fn (\$m) => print(\$m->user_id.' '.\$m->role->value.PHP_EOL));
"
```

**The identity binding is on issuer and subject, and no email is involved.**

```bash
php artisan tinker --execute="
  App\Domain\Identity\Models\IdentityBinding::all()
    ->each(fn (\$b) => print(\$b->issuer.' | '.\$b->subject.' | last_seen_at='.(\$b->last_seen_at ?? 'never').PHP_EOL));
"
```

A null `last_seen_at` means the owner has not signed in yet. After step 4 it should carry a
timestamp; if it is still null, the browser login did not reach the callback.

**The audit trail recorded it.**

```bash
php artisan tinker --execute="
  App\Domain\Identity\Audit\AuditEvent::where('action', 'identity.owner_bootstrapped')
    ->get()->each(fn (\$e) => print(\$e->created_at.' '.\$e->actor_type.' '.\$e->subject_id.PHP_EOL));
"
```

**No one else has authority.** Every other person needs a membership row granted by the
owner. There is no implicit administrator to find:

```bash
php artisan tinker --execute="
  print(App\Domain\Identity\Models\WorkspaceMembership::count().' membership(s) total'.PHP_EOL);
"
```

## Afterwards

- The owner grants further roles — `admin`, `sender`, `auditor` — from the members page.
  Only `owner` and `admin` can manage members; only `owner` can delete a workspace or rotate
  service credentials.
- Revoking someone's access means deleting their membership, and their identity binding if
  they should no longer be able to authenticate. Neither deletes or alters an envelope, an
  artifact, or an audit event: executed instruments and historical signer evidence outlive
  access, and the schema enforces that with `RESTRICT` foreign keys rather than trusting
  application code.
- Rotating `OAUTH_CLIENT_SECRET` does not affect existing bindings. Changing
  `OAUTH_PROVIDER` does — it is part of the binding tuple.
- `config/bherila-auth.php`'s `routes.password_resets`, `routes.change_password`,
  `routes.two_factor`, and `routes.passkeys` follow the resolved auth mode automatically:
  enabled in standalone mode, disabled in SSO mode. Nothing in SSO mode can use a local
  password — there is no password login route, and the standalone controller refuses any
  account that has an identity binding — but an endpoint that sets a credential nobody needs
  is still an endpoint worth not having. Set `ESIGN_LOCAL_AUTH_ROUTES=on` or `=off` to
  override the automatic choice; the default, `auto`, is what makes this automatic.
- Authentication events (sign-in, sign-out, failures, lockouts) are in `auth_audit_log`.
  Provisioning and other application events are in the append-only `esign_audit_events`.
  Retention for the first is off by default; set `BHERILA_AUTH_AUDIT_RETENTION_DAYS` and
  schedule `bherila-auth:prune-audit-log` if a deployment needs it.
