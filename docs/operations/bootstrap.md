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

> **Not yet complete.** Steps 1, 2, and 3 work today. Step 4 — the browser login that turns
> the provisioned binding into a real session — needs the SSO login and callback routes,
> which are **not implemented yet** (issue #12). Until that lands, an SSO owner can be
> provisioned and verified in the database but cannot sign in, and `last_seen_at` on their
> identity binding stays null. A standalone owner uses the local login provided by
> `bherila/auth-laravel`.

---

## Step 1 — register the OAuth client at the identity provider

*SSO mode only. Skip to step 3 for a standalone installation.*

At the identity provider, register this deployment as an application. Provide:

| Field | Value |
|---|---|
| Application label | the name operators and signers will see, e.g. `Acme eSign` |
| Homepage | the application's `APP_URL` |
| Redirect URI | exactly `<APP_URL>/oauth/callback`, or whatever `OAUTH_REDIRECT_URI` is set to — an exact string, no wildcards |
| Role-management deep link | the application's workspace members page, so the provider can link an operator straight to it |

Registration returns a client id and client secret. Put them in `.env`:

```dotenv
OAUTH_PROVIDER=your-provider-key
OAUTH_PROVIDER_URL=https://identity.example.com
OAUTH_CLIENT_ID=...
OAUTH_CLIENT_SECRET=...
OAUTH_REDIRECT_URI="${APP_URL}/oauth/callback"
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

The local user must already exist. The command looks users up; it never creates one from an
email address.

```bash
php artisan esign:bootstrap-owner --user=1 --workspace="acme" --name="Acme"
# or
php artisan esign:bootstrap-owner --user="admin@example.com" --workspace="acme"
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

*SSO mode: **not available yet.*** The login and callback routes that exchange an
authorization code for a session, resolve the `(issuer, subject)` binding to the local user,
and stamp `last_seen_at` are issue #12 and are not implemented. Provisioning is finished and
correct without them; the owner simply cannot sign in until that issue lands. Nothing in this
runbook needs to be re-run afterwards.

Once issue #12 is deployed, the owner opens `APP_URL`, is redirected to the identity
provider, authenticates there, and returns signed in. Their real name and email replace the
placeholders, and `last_seen_at` is set.

*Standalone mode:* the owner signs in at the local login page with their existing
credentials.

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

A null `last_seen_at` means the owner has not signed in yet. Before issue #12 lands, that is
the expected state for an SSO owner.

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
