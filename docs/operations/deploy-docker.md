# Runbook: Docker release and deployment

This repository publishes the `production` image to GHCR (`.github/workflows/publish-image.yml`)
and stops there. It never deploys the image to a host. This runbook is written for the
**consumer's** deploy pipeline: the steps it performs, the contract of the environment file and
key directory it must provide, and how it rolls back. See `docker-compose.prod.example.yml` for
the shape those steps assume, and `DOCKER.md` for how this repository builds and publishes the
image in the first place.

## Release steps

Performed by the consumer's pipeline on every staging (push to `main`) and production (GitHub
Release) deploy, the same way it already refreshes its other service images:

1. **Resolve and pin the digest.** Read the digest from the publish-image.yml run summary (or
   `docker buildx imagetools inspect ghcr.io/bherila/e-sign:<tag>`) and set `ESIGN_IMAGE` to
   `ghcr.io/bherila/e-sign@sha256:...`. Never deploy a mutable tag (`:main`, `:latest`) directly;
   resolve it to a digest once and record that digest as the release artifact.
2. **Pull.** `docker compose -f docker-compose.prod.example.yml pull` (or the pipeline's
   equivalent) fetches the new digest for all four service definitions before anything restarts.
3. **Migrate.** Run the one-shot release job to completion before touching the long-running
   services:
   ```bash
   docker compose -f docker-compose.prod.example.yml --profile release run --rm esign-migrate
   ```
   This runs `php artisan migrate --force` once. It must complete successfully before step 4;
   a failed migration aborts the release and the previous containers keep running unchanged.
4. **Restart.** Recreate `esign`, `esign-worker`, and `esign-scheduler` on the new digest:
   ```bash
   docker compose -f docker-compose.prod.example.yml up -d esign esign-worker esign-scheduler
   ```
5. **Wait for liveness, then readiness.** Poll `GET /up` on the web container until it answers
   200 (this is the same liveness check the image's `HEALTHCHECK` uses internally), then poll
   `GET /health/ready` — authorized, per `docs/operations/health.md` — until its body reports
   `"status": "ok"`. A `"degraded"` status is not a release blocker by itself but should be
   investigated; a `"fail"` status (or the endpoint never reaching `ok`/`degraded` within a
   reasonable window) means the release did not succeed and should be rolled back.

Nothing above runs automatically inside the image or the compose file: no container migrates
itself, and no container restarts another. Each step is invoked by the pipeline, in order, so
concurrent replicas never race on a migration and a bad migration never reaches production
traffic silently.

## Environment file contract (`/etc/esign/esign.env`)

One `env_file` shared by `esign`, `esign-worker`, `esign-scheduler`, and `esign-migrate` (see
`docker-compose.prod.example.yml`). Every variable below is one the application actually reads
in the Docker profile; it mirrors `.env.example`, minus the local-development-only defaults.
**Secret** means: generate it once per environment, never log it, never commit it, and rotate it
independently of code deploys.

### App identity

| Variable | Notes |
|---|---|
| `APP_NAME` | Display name. |
| `APP_ENV` | `production`. Controls whether the entrypoint builds config/route/view caches. |
| `APP_KEY` | **Secret.** `php artisan key:generate --show`, generated once per environment. Never regenerate against a populated database — see `.env.example`. Not a signing key. |
| `APP_DEBUG` | `false`. `true` leaks stack traces to responses. |
| `APP_URL` | Public base URL, used for absolute links (invitations, webhooks). |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Defaults are fine unless localizing. |
| `APP_MAINTENANCE_DRIVER` | `file` (default) is fine; the image has no Redis. |

### Logging

| Variable | Notes |
|---|---|
| `LOG_CHANNEL` | `stderr` in containers — the platform's log collector reads container stdout/stderr, not a file inside a read-only filesystem. |
| `LOG_LEVEL` | `info` or `warning` in production. |

### Database (MySQL 8 / MariaDB)

| Variable | Notes |
|---|---|
| `DB_CONNECTION` | `mysql`. |
| `DB_HOST`, `DB_PORT` | The host's existing MySQL/MariaDB server, reused across instances — never share schemas between them. |
| `DB_DATABASE`, `DB_USERNAME` | Dedicated database and user for this instance. |
| `DB_PASSWORD` | **Secret.** |

### Session, queue, cache

| Variable | Notes |
|---|---|
| `SESSION_DRIVER` | `database`. |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH` | Defaults are fine. |
| `SESSION_DOMAIN` | `null` — never a shared parent domain; see `AGENTS.md`. |
| `SESSION_COOKIE` | Distinct per instance if multiple eSign instances share a domain. |
| `QUEUE_CONNECTION` | `database`. No Redis in the production path. |
| `CACHE_STORE` | `database`. |

### Storage (documents; never local disk in production)

| Variable | Notes |
|---|---|
| `FILESYSTEM_DISK` | `s3` (any S3-compatible store: Garage, R2, AWS). |
| `AWS_ACCESS_KEY_ID` | **Secret.** Dedicated key for this instance's bucket only. |
| `AWS_SECRET_ACCESS_KEY` | **Secret.** |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Per `docs/BLOB_STORAGE.md`. Garage/R2 have no Object Lock: this is not WORM or legal hold. |

The bucket is one of the four things that need backing up, and it needs a different backup
from the database. See [`backups.md`](backups.md).

### Retention and the restore drill

| Variable | Notes |
|---|---|
| `ESIGN_RETENTION_AUTH_LOGS_DAYS`, `ESIGN_RETENTION_ABANDONED_DRAFTS_DAYS` | Finite defaults (400, 90). Applied only when `esign:retention:run` is invoked. |
| `ESIGN_RETENTION_EXECUTED_DOCUMENTS_DAYS` | **Leave blank.** Blank means executed agreements are never deleted automatically, which is the shipped default; setting it is a reviewed decision. See [`retention.md`](retention.md). |
| `ESIGN_RETENTION_PURGE_GRACE_DAYS` | How long a soft-deleted envelope keeps its bytes (30). |
| `ESIGN_BACKUP_MANIFEST_PATH` | Where `esign:backup:manifest` writes. |
| `ESIGN_RESTORE_DRILL` | **Never `1` here.** Only in the throwaway environment a backup is restored into, where it also makes mail and webhook delivery refuse to send. |

### Mail

| Variable | Notes |
|---|---|
| `MAIL_MAILER` | `hybrid` or `ses` in production; never `log`. |
| `MAILER_DSN` | **Secret.** Brevo API DSN (`brevo+api://KEY@default`) when `hybrid`/`brevo` is in use. |
| `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT` | SMTP relay fallback for `hybrid`. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | **Secret**, if the SMTP relay requires auth. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Must be a domain this deployment can actually send as. |

### SSO (optional — omit for standalone local-admin mode)

| Variable | Notes |
|---|---|
| `OAUTH_PROVIDER`, `OAUTH_PROVIDER_URL` | Issuer. |
| `OAUTH_CLIENT_ID` | Per-environment client id. |
| `OAUTH_CLIENT_SECRET` | **Secret.** |
| `OAUTH_REDIRECT_URI` | Must match the registered callback for `APP_URL`. |

### Document sealing — **worker only in principle; every container gets these vars, but only `esign-worker` mounts the files they point at**

| Variable | Notes |
|---|---|
| `ESIGN_SEAL_KEY_ID` | Versioned identifier recorded on every artifact. |
| `ESIGN_SEAL_CERTIFICATE_PATH` | `/keys/seal.crt` — see key directory contract below. |
| `ESIGN_SEAL_PRIVATE_KEY_PATH` | `/keys/seal.key`. |
| `ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE` | **Secret.** Empty if the key is unencrypted (not recommended). |
| `ESIGN_SEAL_CHAIN_PATH` | `/keys/seal-chain.pem`, optional. |
| `ESIGN_SEAL_DIGEST_ALGORITHM` | `sha256`, `sha384`, or `sha512`. |

`esign` and `esign-scheduler` receive these same variables (one shared env file) but never mount
`/keys`, so the paths simply do not resolve there — `signing_material` in `/health/ready`
correctly reports `fail` if a non-worker container is ever asked to seal, which cannot happen in
the state machine but is a second line of defense.

### RFC 3161 timestamp authority

| Variable | Notes |
|---|---|
| `ESIGN_TSA_URL` | Blank means B-B only. A configured but unreachable TSA is a signing error, never a silent downgrade. |
| `ESIGN_TSA_TIMEOUT` | Seconds. |
| `ESIGN_TSA_ALLOW_PLAINTEXT_HTTP`, `ESIGN_TSA_ALLOW_SHA1_TOKEN` | Leave `false` unless a specific public TSA requires it; see `.env.example`. |

### Health and readiness

| Variable | Notes |
|---|---|
| `ESIGN_HEALTH_TOKEN` | **Secret.** Bearer token an operator/monitor presents for the detailed `/health/ready` body. Blank disables the token path (CIDR allowlist still applies). |
| `ESIGN_HEALTH_ALLOW_CIDRS` | Defaults to loopback only. Widen deliberately for a monitoring agent that is not on the same host. |

## Key directory contract (`/etc/esign/keys`, mounted `:ro` into `esign-worker` only)

| File | Env var it satisfies | Permissions |
|---|---|---|
| `seal.crt` | `ESIGN_SEAL_CERTIFICATE_PATH=/keys/seal.crt` | `0440`, owned by `root:<container-gid>` |
| `seal.key` | `ESIGN_SEAL_PRIVATE_KEY_PATH=/keys/seal.key` | `0440`, owned by `root:<container-gid>` |
| `seal-chain.pem` (optional) | `ESIGN_SEAL_CHAIN_PATH=/keys/seal-chain.pem` | `0440`, owned by `root:<container-gid>` |

`<container-gid>` is the numeric GID of `www-data` inside the image (`33` on the Debian base
`php:8.5-fpm` uses; confirm with `docker run --rm <image> id -g www-data` after a build, since a
future base-image bump could change it). Mode `0440` with that group ownership means: the
container's `www-data` process can read the file via group membership, root on the host can
still manage it, and no other host account or process can read it at all. The directory itself
should be `0550` for the same reason. Never make these files world-readable, and never `chown`
them to `www-data` by name on the host — the host's `www-data` (if it has one, e.g. from a
locally installed Apache/nginx) is a different account with a different UID/GID than the
container's.

Only `esign-worker` mounts this directory. `esign`, `esign-scheduler`, and `esign-migrate` never
see it — that is the isolation property this deployment shape exists to provide (`AGENTS.md`:
"Signing keys are separate from `APP_KEY`, versioned, and never in source, images, logs, or
document storage"; mounting scope is how "never in images" extends to "never in the wrong
container" at runtime).

## Rollback

Docker deploys roll back by digest, not by re-running a migration backwards:

1. Set `ESIGN_IMAGE` back to the previous release's digest (the pipeline should already have
   this recorded from the prior release's step 1).
2. `docker compose -f docker-compose.prod.example.yml up -d esign esign-worker esign-scheduler`
   recreates the three long-running services on the old digest.
3. **Migrations are not rolled back automatically.** Laravel migrations in this codebase are
   additive by default; a rollback that depends on `php artisan migrate:rollback` against a
   schema newer versions of the code already wrote to is a case-by-case judgment call, not a
   scripted step. If the new release's migration was additive and backward-compatible (the
   common case — see the migration guidance in `AGENTS.md`), the previous image version runs
   unaffected against the new schema and no rollback migration is needed. If it was not, restore
   from the database backup instead of rolling the schema back live.
4. Re-run steps 5 (`/up`, then `/health/ready`) from the release steps above against the rolled-
   back deployment before considering the rollback complete.

## What the shared-hosting (cPanel) profile cannot provide

`docs/HANDOFF.md` §13 documents the cPanel profile as a first-class, supported deployment target
— but the Docker profile's key isolation (`esign-worker` is the only process on the machine that
can ever open the signing key) has no equivalent there. On shared/cPanel hosting, the web
request handler (PHP-FPM under the account's Apache/LSAPI vhost) and the cron-driven queue worker
(`php artisan queue:work` invoked by a cron job) run as **the same OS user**. Restrictive file
permissions (owned by that account, mode `0440` or tighter) keep the key unreadable to other
accounts on the box, but they cannot keep it unreadable to the account's own web-facing PHP
process the way a separate container boundary does — a code-execution bug in the web role on
that host has the same OS-level reach to the key file as the worker role does, because they are
the same principal. There is no second, separately-privileged process to mount the key into
instead: the profile is one shared-hosting account, one filesystem, one user. Document this
limitation to anyone evaluating cPanel for a deployment where key isolation from the web-facing
process is a hard requirement; it is a property of the hosting model, not something a
configuration change on this repository's side can close.

## See also

- `DOCKER.md` — image build, targets, the standalone compose stack this repository runs itself,
  and the reverse-proxy snippet for this profile.
- `docs/operations/health.md` — `/up` vs `/health/ready`, probe semantics, authorization.
- `docker-compose.prod.example.yml` — the compose shape these steps assume.
