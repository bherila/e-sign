# Runbook: cPanel / shared-hosting release and deployment

The second of the two supported deployment profiles (`README.md` "Deployment profiles"). No
container runtime, no persistent daemon, no Redis: one shared-hosting account, one PHP-FPM/
LSAPI web vhost, and cron. This runbook is the shared-hosting counterpart to
[`docs/operations/deploy-docker.md`](deploy-docker.md) — read that one first if a side-by-side
comparison of what each profile can and cannot provide is useful; the "What the shared-hosting
(cPanel) profile cannot provide" section there (key isolation) applies here without
qualification and is not repeated in full below.

## Prerequisites

- **PHP 8.4 or newer**, on **both** the CLI binary (`php artisan …`) and the account's web vhost
  handler — they are not automatically the same version on a cPanel account, and a mismatch is
  the single most common cause of "the deploy pipeline reported success but the site 500s"
  (see `.github/workflows/deploy.yml`'s "Verify production health" step and
  `htaccess-append.txt`). Required extensions on both: `pdo_mysql`, `mbstring`, `gd`, `zip`,
  `intl`, `bcmath`, `openssl`, `exif`.
- **MySQL 8 or MariaDB 10.6+**, a dedicated database and user for this instance —
  `docs/adr/0002-supported-databases.md` is the supported-engine list; do not run against an
  engine outside it merely because the host offers it.
- `public/` as the document root; `app/`, `config/`, `storage/`, `.env`, and signing key
  material all live **outside** the web-served tree.

## Getting the release bundle

`scripts/build-release.sh [version]` produces `dist/bwh-esign-<version>.tar.gz` and a
`.sha256` sums file: a `git archive` of the committed tree (never the working tree — untracked
files and a stray `.env` cannot leak in), with `composer install --no-dev` and `pnpm run build`
run inside a scratch copy, assembled into exactly what
`.github/workflows/deploy.yml` rsyncs to a live account (`app`, `bootstrap`, `config`,
`database`, `public` — including the built `public/build/` assets, `resources`, `routes`,
`storage`, `vendor`, `artisan`, `composer.json`, `composer.lock`, `LICENSE`,
`THIRD_PARTY_NOTICES.md`) plus `htaccess-append.txt` and an `INSTALL.md` quick-start. The
`release-bundle` job in `.github/workflows/release-bundle.yml` runs this on every published
GitHub Release and attaches both files to it, so a production install does not need to run the
script itself — download the Release asset and verify its checksum instead:

```bash
sha256sum -c bwh-esign-<version>.tar.gz.sha256
```

## Install

1. **Unpack outside the webroot**, e.g. `~/bwh-esign/`, and point the account's document root
   (or a `public_html` symlink, provisioned once — see the `deploy.yml` comment on why the
   webroot symlink is one-time provisioning, not deploy-managed, on an account that hosts more
   than this one site) at `~/bwh-esign/public`.
2. **Configure.** `cp .env.example .env` (not shipped in the bundle — copy it from the
   repository or from a previous install's template) and `php artisan key:generate`. Fill in
   database credentials, mail transport (never `log`/`array` in production —
   `docs/delivery/mail.md`), storage (`local`, which keeps documents under
   `storage/app/documents`, or an S3-compatible disk per `docs/BLOB_STORAGE.md`), and, if the
   web vhost's PHP version might differ from the CLI's, `ESIGN_CPANEL_WEB_PHP_VERSION` (used by
   `esign:doctor` below — see its own doc comment for exactly what it can and cannot verify).
3. **Diagnose before touching the database:**

   ```bash
   php artisan esign:doctor
   ```

   Checks PHP CLI version and extensions, the CLI-vs-configured-web PHP version, that
   `storage/` and `bootstrap/cache` are writable, that `.env` is present and `APP_KEY` is set,
   database connectivity and pending migrations, that the queue connection is `database`,
   `memory_limit`/`max_execution_time` against the minimums below, the scheduler heartbeat
   (see "What `esign:doctor` cannot detect" below), seal material readability and expiry, and
   that mail is not `log`/`array` in production. Exits non-zero on any failure and never prints
   a secret. Fix everything it reports before continuing — a deployment is not "working" merely
   because its home page loads (`docs/HANDOFF.md` section 13).
4. **Migrate — an explicit step, never automatic:**

   ```bash
   php artisan migrate --force
   ```
5. **Bootstrap the first owner.** There is no default administrator and the first person to
   sign in is never promoted automatically — see
   [`docs/operations/bootstrap.md`](bootstrap.md) for the full SSO/standalone walkthrough:

   ```bash
   php artisan esign:bootstrap-owner --help
   ```
6. **Cron** — see below.
7. **Smoke test** — see below.

### What `esign:doctor` cannot detect

Two of its checks are proxies, not direct observations, and it says so in its own output:

- **The web PHP runtime.** A CLI script cannot ask the web SAPI what version it is running;
  they can be, and on cPanel sometimes are, two different `ea-phpNN` installations entirely.
  The check compares this CLI's version against the operator-supplied
  `ESIGN_CPANEL_WEB_PHP_VERSION`, not against a live read of the vhost. Keep that value in sync
  with the `AddHandler application/x-httpd-ea-phpNN` line `htaccess-append.txt` maintains in
  `public/.htaccess` on every deploy.
- **Cron presence.** There is no reliable, portable way for a PHP process to read another
  account's crontab. Instead it reuses the same scheduler-heartbeat probe `/health/ready` uses
  (`App\Domain\Delivery\Health\Probes\SchedulerHeartbeatProbe`,
  [`docs/operations/health.md`](health.md)): a cache key a scheduled task refreshes every
  minute. A `fail` here almost always means cron is not actually invoking `schedule:run`, but
  it is inference from an effect, not a direct read of the crontab — and a **brand new**
  install legitimately fails this check until cron has ticked at least once after step 6 below.

## Cron

Exactly two lines, both every minute:

```cron
* * * * * cd /home/USER/bwh-esign && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/bwh-esign && php artisan esign:queue:work-bounded >> /dev/null 2>&1
```

**Edit crontabs via a file, never a pipeline through `crontab`.** `crontab -l | ... | crontab -`
silently replaces the *entire* crontab — every other job already on the account, not just this
one — the moment anything in that pipeline fails or emits something unexpected (a warning line,
a locale-dependent header, a truncated read). Write the desired lines to a file, review it, and
install it with `crontab <file>` (or edit in place with `crontab -e`), never a `-l | -` round
trip.

### Why two lines and not one, and what each does

- **`schedule:run`** drives Laravel's scheduler: expiry (`signing:expire`, hourly), reminders
  (`signing:remind`, daily), the API idempotency-key prune (hourly), and the scheduler
  heartbeat itself (every minute — the fact `esign:doctor` and `/health/ready` both read).
- **`esign:queue:work-bounded`** is this profile's substitute for a persistent queue daemon
  (`docs/HANDOFF.md` section 13; `App\Domain\Delivery\Queue\Console\WorkBoundedCommand`). Cron
  fires it once a minute; it takes a database-backed lease (the `worker_leases` table) before
  doing anything, so a tick that fires while the previous one is still running refuses to start
  a second worker rather than racing it. Once it holds the lease it runs
  `queue:work --stop-when-empty --max-time=<n> --max-jobs=<n>` on the `default`, mail, and
  webhook queues, heartbeating the lease between jobs, and releases it on exit.

### Queue-delay characteristics

Because there is no persistent worker, a job's delay has two components: however long it sits
in `jobs` until the next cron tick picks it up (**up to ~60 s**, cron's own cadence — the
`queue` readiness probe's warn/fail thresholds, `esign.health.queue_warn_seconds` /
`queue_fail_seconds`, are calibrated against that, not against a daemon's near-zero pickup
latency), plus however long it takes the bounded worker to reach it once running (immediate,
unless the previous tick's worker is still draining a backlog). A burst well beyond what one
`--max-jobs`/`--max-time` window can drain in a minute empties over several cron ticks, not
instantly — expected and not a fault, as long as `/health/ready`'s `queue` probe is not
reporting `fail`.

### `--max-time` is not a hard per-job interrupt

`queue:work` only checks its time/job-count budget **between** jobs. A single job already
running when the budget expires finishes uninterrupted before the process exits — this command
cannot forcibly kill a running job, and does not try to; a host that lacks pcntl/process-
spawning capabilities cannot be assumed to offer that kind of control at all. This is exactly
why `esign.queue.lease_ttl` (default 15 minutes) must be configured comfortably larger than
`esign.queue.max_time` (default 50 s) plus the longest job's own timeout: a lease that expired
right at `max_time` would let the *next* cron tick decide a still-legitimately-running worker
was abandoned and start a second one over the same queue rows. The practical consequence for
document processing: **an oversized PDF must fail preflight before an invitation is ever sent**,
not depend on this mechanism to interrupt a hung sealing job after the fact (`docs/HANDOFF.md`
section 13; upload/preflight ceilings are `config/esign.php` → `documents`).

## Memory and time limits

`esign:doctor`'s `resource_limits` check compares `memory_limit` and `max_execution_time`
against `config('esign.cpanel.min_memory_bytes')` / `min_execution_seconds`, defaulting to
**512 MiB / 300 s**. Nothing in `docs/evidence/finalization.md` or the sealer's own
configuration states a measured floor for these — this is this diagnostic's own conservative
proposal for sealing a large synthetic PDF, not a number derived from a production corpus, and
`ESIGN_CPANEL_MIN_MEMORY_BYTES` / `ESIGN_CPANEL_MIN_EXECUTION_SECONDS` exist so a deployment
with real measurements can replace it. `-1` (memory) and `0` (execution time) both mean
"unlimited" to PHP and are treated as satisfying any minimum.

Set these in `php.ini` (or an account-level `.user.ini` next to `public/index.php`, the usual
cPanel mechanism when there is no access to the shared `php.ini`) for the **CLI** SAPI the cron
lines run under. The web SAPI's own limits govern request-time work (uploads, the editor) and
are configured the same way, but separately — see the CLI-vs-web split
`esign:doctor`'s `web_php_version` check exists to catch in the first place.

## Key isolation on shared hosting

The PHP-FPM/LSAPI web process and the cron-driven queue worker run as **the same OS user** on
this profile — there is no second, separately-privileged process to mount the signing key into
the way Docker's `esign-worker` role gets it and nothing else does. Restrictive file permissions
(`0440`, owned by the account) keep the key unreadable to *other* accounts on the box, but not to
a code-execution bug in this account's own web-facing PHP. This is a property of the shared-
hosting model, not something a configuration change on this repository's side can close. Full
detail: `docs/operations/deploy-docker.md` → "What the shared-hosting (cPanel) profile cannot
provide", and the key rotation/compromise runbook: `docs/operations/seal-key-management.md`.

## Backups

Four things, four distinct handling policies — never one "back everything up together" job:

1. **Database.** Standard MySQL/MariaDB dump/backup tooling for the account's engine.
2. **Artifact storage.** The `documents` disk (local directory or S3-compatible bucket per
   `docs/BLOB_STORAGE.md`). Garage and R2 have no Object Lock, so this is a durability backup,
   not WORM or legal hold — application-level deletion restrictions are the legal-hold
   mechanism, not the storage layer.
3. **Encryption secrets.** `APP_KEY` — decrypts every encrypted column. Losing it makes that
   data unrecoverable; regenerating it against a populated database does the same thing.
4. **Signing material.** The seal certificate/private key, handled per
   `docs/operations/seal-key-management.md` — versioned, rotated on its own schedule, and never
   backed up alongside `APP_KEY` or application data.

Test restores into an isolated environment with outbound mail/webhooks suppressed, and verify
restored documents against their recorded SHA-256 digests and signatures — a restore that
"looks" complete but silently corrupted a document's bytes is worse than an obvious failure.

## End-to-end smoke test

`docs/HANDOFF.md` section 13: a deployment is not "working" merely because its home page loads.
Run this after every install and after every worker-affecting change:

1. **Authenticate** as the bootstrapped owner (SSO or standalone, per `docs/operations/
   bootstrap.md`).
2. **Upload** a synthetic PDF and confirm preflight accepts it.
3. **Invite** a recipient and confirm the invitation mail is sent (not just queued — check
   `esign:mail:backlog` or `/health/ready`'s `mail_backlog` probe).
4. **Sign** as the guest recipient: open the mail-preview link (GET must be harmless — it must
   not consume the token), start the signing session, and submit.
5. **Seal**: confirm the envelope reaches `completed` once the bounded worker's next cron tick
   picks up the finalization job — allow up to the queue-delay window above.
6. **Download** the executed PDF and the evidence bundle.
7. **Validate** the sealed artifact independently (`scripts/validate-seal.sh` against a copy of
   the executed PDF, or an external tool) — a passing in-process check alone is not sufficient
   proof (`docs/HANDOFF.md`'s crypto/trust release gate).
8. **Deliver a verified webhook**: register a receiver (`esign:webhook:endpoint:create`), confirm
   the completion event arrives with a valid signature, and confirm the receiver's 2xx clears the
   delivery (`docs/delivery/webhooks.md`).
9. **Recover after a worker interruption**: kill the bounded worker mid-job (or simulate by
   letting a cron tick's `--max-time` lapse) and confirm the next tick's lease takeover resumes
   work rather than duplicating it or losing the envelope — `docs/evidence/finalization.md`'s
   Recovery table describes exactly what a crash at each point leaves behind and what the retry
   does.

## See also

- [`docs/operations/deploy-docker.md`](deploy-docker.md) — the Docker profile, for comparison.
- [`docs/operations/health.md`](health.md) — `/up`, `/health/ready`, and every probe
  `esign:doctor` reuses.
- [`docs/operations/seal-key-management.md`](seal-key-management.md) — signing key rotation and
  compromise response.
- [`docs/operations/bootstrap.md`](bootstrap.md) — first-owner provisioning.
- [`docs/delivery/webhooks.md`](../delivery/webhooks.md), [`docs/delivery/mail.md`](../delivery/mail.md).
