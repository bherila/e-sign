# Provider identity enforcement

Lets the identity provider end access here. With enforcement on, an administrator's browser
session ends at the next request once the provider has disabled, deleted or reset that person,
or removed their grant to this application, instead of lasting until the session expires. The
mechanism is `bherila/auth-laravel`'s provider session verification (its
`docs/provider-session-verification.md` and `docs/provider-identity-cutover.md`); this page is
what it covers in eSign, and how an operator turns it on and locks it.

**It is off by default, and nothing changes until an operator turns it on.** Shipping the wiring
changed no behaviour: with `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=false` and no recorded
cutover, the middleware passes every request through and sign-in admits exactly who it admitted
before.

It applies to **SSO mode only**. It needs the identity provider's identity-status endpoint
(`POST /api/reconciliation/identity-status`, authenticated with this application's OAuth client
credential) and the OAuth client settings in `.env`.

## What it does

| Where | With enforcement on |
|---|---|
| SSO callback (`OAuthLoginController`) | Records the provider credential generation the login was made under (also while off, so turning it on later ends nothing). A login the provider cannot verify (an older provider that reports no generation, or the status client unconfigured) is undone and answered 503 with `Retry-After`, audited as `Provider session unverified` |
| Every session-authenticated person route (`provider-session` middleware) | Asks the provider at most once per person every five minutes (shared across that person's sessions), and freshly on every write (non-GET). An ended identity is signed out: session invalidated, CSRF token rotated, a redirect to `/login` (or JSON 401 `Your sign-in has ended. Sign in again.`). An unavailable provider answers a retryable 503 and keeps the session |
| Sign-out (`logout` in both modes) | Never checked, so a person can always sign out, even during a provider outage |
| Local credentials (package password reset/change/confirm, passkeys, two-factor) | Refused for an account bound to the provider (`EsignLocalCredentialPolicy`). Unbound accounts keep theirs |
| Unbound accounts (standalone local administrators) | Unaffected: they have no provider identity to check |

Name and email are refreshed only at sign-in, from the login response; a status check never
changes them.

## Credential inventory

Every way a request is authenticated or authorized as a person or principal, and what
enforcement does to it.

| Credential | Represents | Provider-bound? | Treatment |
|---|---|---|---|
| Browser session from the SSO callback (`/auth/callback`, `OAuthLoginController`) | An administrator (workspace owner, admin, sender, auditor) | Yes: an `identity_bindings` row, `(issuer, subject)` | **Covered.** Baseline recorded at sign-in; `provider-session` on `routes/web.php` (dashboard), `routes/documents.php`, `routes/members.php` (members page, accept/redeem invitation), `routes/templates.php`, `routes/editor.php`, and the package's own `auth` routes (passkey management, change/confirm password, audit log) through `bherila-auth.routes.middleware` |
| Browser session from the standalone password form (`POST /login`, `LocalLoginController`) | A local administrator | No. The form excludes every account that has an identity binding, whatever enforcement says | **Not applicable**: unbound. Unchanged |
| Browser session from the package's password reset, passkey sign-in or two-factor sign-in | Whoever holds the local credential | Can be, if a bound account acquired one | **App policy**: refused for bound accounts while enforcement is on (`EsignLocalCredentialPolicy`); `BHERILA_AUTH_LOCAL_CREDENTIALS_PROVIDER_BOUND=refuse` refuses them with enforcement off too. A bound session that somehow exists without a baseline is signed out by the middleware |
| Remember-me cookie | The account it was issued to | eSign's own controllers never set one; only the package's passkey and two-factor paths can | A bound account cannot reach those paths under enforcement; a session revived from a cookie has no baseline and is signed out by the middleware. Unbound: unaffected |
| Workspace invitation link (`/invitations/{token}`, emailed) | Nobody: the token moves to an encrypted cookie; accepting needs a signed-in session | n/a | The link route has no session and is not checked. **Accepting** (`/invitations/accept`) is behind `auth` + `provider-session`: covered |
| Guest signing invitation (`/sign/{envelope}/{token}`, emailed) and signing session (`esign_signing` cookie) | A recipient of one envelope, proven by the token and, where required, a mailed one-time code | No. Recipients are not provider identities | **Not applicable.** `routes/signing.php` never carries `provider-session`, even when an administrator's browser opens a link |
| Legacy recipient resolver (`/signing/{recipient}`) | Nobody until mailbox verification starts a signing session | No | **Not applicable** |
| Embedded signing (the same signing pages in a popup, `esign.signing.embed_origins`) | The recipient, as above | No | **Not applicable** |
| Service credentials (`esk_…`, `service-credential` on `/api/v1/*` and the Firma facade) | A workspace integration, not a person. Issued by an operator command, scoped, revocable | No | **Not applicable**: a machine principal with its own expiry and revocation (`docs/operations/service-credentials.md`). Not affected by any person's provider status |
| Signed facade URLs (`signed` download and large-upload routes) | One document of one signing request, for a limited time | No | **Not applicable**: capability URLs minted for a service credential's consumer |
| Mail provider webhooks (`/webhooks/mail/brevo`, `/webhooks/mail/ses`) | The mail provider | No | **Not applicable**: shared token / SNS signature, no session |
| Outbound webhooks (`DeliverWebhook`) | eSign, to an integrator | No | **Not applicable** |
| Delegated access (`POST /application-access`) | The identity provider, acting for one of its signed-in administrators | The actor is matched through its identity binding | **Not covered by the middleware**, by design: no session exists. Each request is a provider-signed, single-use, 60-second assertion naming the actor, so the provider is the party deciding the actor is live; the adapter still refuses a disabled local account (`docs/operations/delegated-access.md`) |
| Health readiness (`/health/ready`) | A monitor holding `ESIGN_HEALTH_TOKEN` | No | **Not applicable** |
| Queued jobs (`FinalizeEnvelope`, `ScheduleEnvelopeMail`, `DispatchOutboxEvent`, `DeliverWebhook`, `AttestSealReadiness`) | Nobody: they carry an envelope or row id and finish work a request already decided | No | **Not applicable**: no job acts with a person's authority |
| Artisan commands (`esign:create-user`, `esign:bootstrap-owner`, service-credential commands) | The operator with shell access | n/a | **Not applicable** |
| OAuth bearer tokens (Passport) | — | — | **None exist.** eSign does not run Passport or the package's OAuth server, so `ProviderIdentityTokens` has nothing to enforce |

Two things enforcement does not do, and nothing here claims it does:

- **Disabling a local account** (`users.disabled_at`) refuses new sign-ins through
  `EsignUserPolicy`. It does not end an existing session; the provider ending the identity does,
  once enforcement is on.
- **Deleting an `identity_bindings` row** makes the account unbound. Unbound accounts are left to
  the login policy, so it does not end a session either. Disable the person at the provider
  instead.

## Bindings

The package's default resolver reads `oauth_provider` / `oauth_subject` columns on `users`, which
eSign does not have. `App\Domain\Identity\Auth\IdentityBindingResolver`, bound in
`AppServiceProvider`, reads `identity_bindings` instead:

- no binding: unbound (null), left to the login policy;
- exactly one binding whose issuer is `OAUTH_PROVIDER`: that binding;
- a binding under another issuer, more than one binding, or an empty subject: a broken binding,
  which signs the session out rather than being treated as unbound. Changing `OAUTH_PROVIDER` on
  a live installation therefore signs every bound administrator out under enforcement, and the
  next SSO sign-in resolves to a new account (bindings are never rewritten).

## Cache store

Status observations are shared by every session of a person, in the cache store named by
`BHERILA_AUTH_PROVIDER_IDENTITY_CACHE_STORE` (empty: `CACHE_STORE`). It must be shared by every
web worker and must support locks. Every supported deployment sets `CACHE_STORE=database` (the
Docker environment contract and the cPanel `.env`), which is shared by construction, so leave the
variable empty. Never use `array`, or `file` behind more than one web host: workers would ask the
provider separately and a late, older answer could stand for a newer disable.

## Turning it on

1. **Deploy this release.** It carries the cutover migration
   (`2026_11_18_000000_create_provider_identity_cutover_table`) and starts recording sign-in
   baselines. Sessions started before it have no baseline; they are asked to sign in once when
   enforcement turns on. That is harmless, but it can be avoided by waiting until those sessions
   have ended (`SESSION_LIFETIME`, 120 minutes by default, is an idle timeout, so a session in
   continuous use can outlast it).
2. **Confirm the provider side.** The identity provider must serve the identity-status endpoint
   for this application's static OAuth client, and its login identity response must include
   `credential_version`. Without it every sign-in fails 503 under enforcement. Check that
   `OAUTH_PROVIDER`, `OAUTH_PROVIDER_URL` (HTTPS), `OAUTH_CLIENT_ID` and `OAUTH_CLIENT_SECRET` are
   set.
3. **Enable:** set `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true` and deploy (or, on cPanel, edit
   `.env` and rebuild the configuration cache).
4. **Verify in production**, with a test account at the provider:
   - sign in, use the dashboard and a document page;
   - disable the account at the provider: within five minutes (at once on any write) the session
     is signed out here;
   - re-enable it and reset its password at the provider: the old session is signed out;
   - remove its grant to eSign at the provider: the session is signed out and a new sign-in is
     refused;
   - confirm a guest signing link and a service-credential API call still work, and that signing
     out works.

   To back out at this stage, set the variable to `false` again: before step 5 it is an ordinary
   switch.
5. **Record the cutover**, once, on the production host:

   ```bash
   php artisan bherila-auth:provider-identity-cutover record
   ```

   It refuses unless the table exists, the setting is on and the OAuth client is configured. From
   then on enforcement stays on even if the setting is later false (a stale `.env`, a restored
   configuration). Confirm with `php artisan bherila-auth:provider-identity-cutover` (status).
6. **Deploys check it.** Every deploy path runs
   `php artisan bherila-auth:provider-identity-cutover check` after migrations and before the
   release serves traffic. It passes while no cutover is recorded. Once one is, it fails when the
   setting is off or the OAuth client is incomplete, because that release would answer every
   protected request and every sign-in with a 503:

   | Deploy path | Where the check runs |
   |---|---|
   | cPanel, GitHub deployment (`.github/workflows/deploy.yml`) | `scripts/deploy/verify-candidate.sh`, the shared action's `post-deploy-script`: after the candidate's migrations, before it is selected. A failure keeps the previous release |
   | Docker (`docker-compose.prod.example.yml`) | The `esign-migrate` release job runs the image's `release` role: `migrate --force`, then `check`. A failure stops the pipeline before step 4 of `deploy-docker.md` restarts anything |
   | Manual cPanel or release-bundle install (`cpanel.md`, `scripts/release/INSTALL.md`) | By hand, right after `php artisan migrate --force` |

## Failure modes

| Situation | Result |
|---|---|
| Provider status endpoint down or slow | Retryable 503 on protected pages and on sign-in; sessions are kept. Not a reason to withdraw |
| Shared cache store failing | The same 503: an unreadable store never authorizes a request |
| Cutover recorded, setting off | Enforcement stays on; the deploy check fails |
| Cutover recorded, OAuth client incomplete | Every protected request and sign-in is 503; the deploy check fails |
| Database restored from before the cutover | The record is gone with it: run `record` again |

## Withdrawing

Only to leave the identity provider on purpose (for example, moving the installation to
standalone mode), never for an outage:

1. Make sure administrators have another way to sign in (`esign:create-user`,
   `docs/operations/bootstrap.md`).
2. `php artisan bherila-auth:provider-identity-cutover withdraw` and confirm.
3. Then set `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=false` and deploy.

## Code and tests

| | |
|---|---|
| Sign-in baseline | `app/Http/Controllers/Auth/OAuthLoginController.php` |
| Middleware alias | `provider-session` in `bootstrap/app.php`; package routes via `bherila-auth.routes.middleware` |
| Binding resolver | `app/Domain/Identity/Auth/IdentityBindingResolver.php` |
| Local-credential policy | `app/Domain/Identity/Auth/EsignLocalCredentialPolicy.php` |
| Configuration | `config/bherila-auth.php`, `provider_identity` |
| Tests | `tests/Feature/Auth/ProviderIdentityEnforcementTest.php`, `ProviderIdentityLocalCredentialsTest.php`, `ProviderIdentityCutoverTest.php`, `ProviderSessionRouteCensusTest.php` (fails any `auth` route without `provider-session`), `tests/Unit/Identity/ProviderIdentityEnforcementCensusTest.php` (application code asks `ProviderIdentityEnforcement::enabled()`, never the setting) |
