# Runbook: health and readiness

BWH eSign exposes two HTTP probes. They answer different questions and have different
audiences; do not point a load balancer at the detailed one or an operator dashboard at the
minimal one.

| Route | Purpose | Checks | Audience |
|---|---|---|---|
| `GET /up` | Liveness | Only that the PHP process answers HTTP. No database, no dependencies. Laravel's built-in probe (`bootstrap/app.php`, `health: '/up'`). | Load balancers, container orchestrators |
| `GET /health/ready` | Readiness | Database, queue lag, scheduler heartbeat, storage, mail configuration, mail backlog, webhook backlog, signing material, TSA configuration, artifact integrity. | Operators, uptime monitors |

## `/health/ready`

Every response, from every caller, has an HTTP status:

- **200** — overall status `ok` or `degraded` (some probe warned).
- **503** — overall status `fail` (some probe failed).

The **body** depends on who is asking:

- An **unauthenticated or unrecognized caller** gets exactly `{"status": "ok"}`,
  `{"status": "degraded"}`, or `{"status": "fail"}` — enough for a load balancer or uptime
  monitor to act on, nothing else.
- An **authorized caller** gets the full body: overall status plus a `status` and short
  `message` per probe. The message is always a plain human sentence — never a secret, a
  hostname, a filesystem path, a DSN, or a document name. `tests/Feature/Delivery/HealthReadyEndpointTest.php`
  asserts the detailed body contains none of `APP_KEY`, a database password, a configured
  path, or a mail host, and every probe's own tests cover its specific "never leak this"
  cases (e.g. `SigningMaterialProbeTest` never returns the private key's contents or path).

### Authorizing as an operator

A caller is authorized if either is true:

1. It presents `Authorization: Bearer <ESIGN_HEALTH_TOKEN>`, compared with `hash_equals()`
   (timing-safe) against `config('esign.health_token')`. Leave `ESIGN_HEALTH_TOKEN` unset to
   disable the token path entirely.
2. It connects from a source IP inside `ESIGN_HEALTH_ALLOW_CIDRS` (comma-separated CIDRs,
   IPv4 or IPv6). The default, `127.0.0.1/32,::1/128`, allows only loopback — i.e. a sidecar
   or a monitoring agent running on the same host. Widen it deliberately; it is not
   authentication, only a source-address filter.

Both are configured in `.env`:

```dotenv
ESIGN_HEALTH_TOKEN=
ESIGN_HEALTH_ALLOW_CIDRS=127.0.0.1/32,::1/128
```

### Probes

| Probe key | `ok` | `warn` | `fail` |
|---|---|---|---|
| `database` | Connects; no pending migrations | Pending migrations exist | Connection failed |
| `queue` | Oldest pending job's `available_at` is recent; no jobs failed in the last 24h | Oldest job age exceeds `esign.health.queue_warn_seconds` (default 120s), or ≥1 job failed in the last 24h | Oldest job age exceeds `esign.health.queue_fail_seconds` (default 600s) |
| `scheduler` | Heartbeat cache key refreshed within `esign.health.scheduler_warn_seconds` (default 180s) | Heartbeat older than that, within `esign.health.scheduler_fail_seconds` (default 600s) | Heartbeat older than that, or never recorded |
| `storage` | Write, read-back, and delete of a small probe file on the default disk succeed | — | Any step fails |
| `mail` | `mail.default` is a delivering transport, or the app is not in production | — | `mail.default` is `log` or `array` while `APP_ENV=production` |
| `mail_backlog` | No message is stuck in `queued` beyond `esign.mail.backlog_warn_seconds` (default 300s) and fewer than `esign.mail.failed_warn_count` (default 1) reached `failed` in the last 24h | Oldest `queued` message is older than the warn threshold, or the 24h `failed` count has reached `failed_warn_count` | Oldest `queued` message is older than `esign.mail.backlog_fail_seconds` (default 1800s), the 24h `failed` count has reached `esign.mail.failed_fail_count` (default 25), or the outbox table is unreadable |
| `webhook_backlog` | No overdue delivery, and no disabled endpoint | Oldest overdue delivery exceeds `esign.delivery.webhooks.backlog_warn_seconds` (default 300s), or ≥1 endpoint is disabled | Oldest overdue delivery exceeds `esign.delivery.webhooks.backlog_fail_seconds` (default 1800s), or the outbox tables are unavailable |
| `signing_material` | Certificate and private key paths are configured and readable, and the certificate expires more than `esign.health.cert_warn_days` (default 30) days out | Unset outside production, or the certificate expires within the warn window | Unset in production, unreadable, unparseable, or expired |
| `tsa` | `ESIGN_TSA_URL` unset, or set and parses as `http`/`https` | — | Set but not a valid `http(s)` URL |
| `artifact_integrity` | The last completed `esign:artifacts:verify` run passed and finished within `esign.retention.verification_warn_days` (default 8) | That run passed but is older than the window, or no verification has ever completed | The last completed run found a digest mismatch, a missing object, or a seal that no longer validates |
| `finalization_backlog` | No envelope has been waiting to finalize longer than `esign.finalization.resume_after_minutes` (default 10) | At least one has | More than 10 have, one of them has waited an hour, or the tables are unreadable |

The queue, scheduler, and certificate thresholds live in `config/esign.php` under the
`health` key and are each overridable by an `ESIGN_HEALTH_*` environment variable. The mail
backlog thresholds live under the `mail` key, overridable by `ESIGN_MAIL_BACKLOG_*` and
`ESIGN_MAIL_FAILED_*`. The artifact-integrity window lives under `retention`, overridable by
`ESIGN_RETENTION_VERIFICATION_WARN_DAYS`. The finalization window lives under `finalization`,
overridable by `ESIGN_FINALIZATION_RESUME_AFTER_MINUTES`; the count and the age at which that
probe fails are fixed in the probe.

**Overall status** is the worst of every probe: `ok` only if all probes are `ok`, `degraded`
if the worst is a `warn`, `fail` if any probe `fail`s.

### Notable design choices

- **`signing_material` never reads the private key past `is_readable()`.** It only opens and
  parses the certificate (via `openssl_x509_parse`) to report expiry.
- **`tsa` never makes a network call.** It only checks that the configured URL is
  well-formed. Actual TSA reachability is checked by the worker at signing time, where an
  unreachable TSA is a signing error, not a silent downgrade to a B-B signature — see
  `docs/HANDOFF.md` §9.
- **`mail_backlog` is not the same check as `mail`.** `mail` asks whether a delivering
  transport is configured; `mail_backlog` asks whether messages are actually leaving. The
  failure it exists to catch is the one where configuration is perfect and nothing is being
  sent because no queue worker is running on the `mail` queue. It counts `queued` only: a
  message at `sent_to_provider` is out of the application's hands, and counting it would make
  a working deployment look broken whenever a provider was slow with feedback. `sent_to_provider`
  is not delivery — see `docs/delivery/mail.md`.
- **`webhook_backlog` measures overdue work, not scheduled work.** A retry deliberately
  waiting twelve hours for a broken receiver is the retry schedule doing its job; counting it
  as backlog would make a healthy instance with one bad endpoint look like a stalled queue.
  Only deliveries whose `next_attempt_at` has passed count towards the age. A disabled
  endpoint warns rather than fails: delivery to it has stopped and an operator needs to know,
  but the instance is not unready. The message names no endpoint and no URL;
  `php artisan esign:webhook:backlog` prints the same snapshot with more detail, and
  `docs/delivery/webhooks.md` is the runbook.
- **`artifact_integrity` verifies nothing itself.** Re-hashing every published artifact is
  minutes of I/O, so an endpoint that did it would either time out or become a way to make
  the instance unavailable by requesting it repeatedly. `esign:artifacts:verify` does the
  work on the weekly schedule in `routes/console.php` and records the outcome in
  `artifact_verification_runs`; the probe reads the last completed row. That split creates a
  second failure mode and the probe treats it as the more important one: a verification that
  **stopped running** is worse than one that ran and failed, because a failure is visible and
  a silence is not — so an old result warns even when it passed. A verification that has
  never run warns rather than fails, because a freshly provisioned instance has no artifacts
  and no schedule history, and failing readiness there would make a correct deployment look
  broken on its first day. Artifacts belonging to an envelope retention soft-deleted are
  skipped: bytes removed on purpose are not an integrity failure
  (`docs/operations/retention.md`).
- **`finalization_backlog` is not the `queue` probe.** `queue` measures work that is on the
  queue and late. The failure this one exists to catch is the work that is not on the queue at
  all: an envelope everybody signed, whose `FinalizeEnvelope` job was lost with a worker, a
  restore, or a purge. The queue is then empty and green while a signer waits for an agreement
  nothing will ever seal. It counts exactly the set `esign:finalization:resume` re-dispatches
  every five minutes — both read the same reader, so an operator can never see a backlog the
  sweep would not clear. Any waiting envelope warns rather than being ignored, because one
  sweep should have cleared it: a backlog still there at the next scrape means the sweep is not
  running or the work is not being picked up. Envelopes in `finalization_failed` are not
  counted; that is a visible state with its own event, retried deliberately
  (`docs/evidence/finalization.md`). The message carries a count and an age, never an envelope
  id, a title, or a workspace.
- **The scheduler heartbeat** is written by a task in `routes/console.php`
  (`Schedule::call(...)->everyMinute()`) that stores the current time under the cache key
  `App\Domain\Delivery\Health\SchedulerHeartbeat::CACHE_KEY`. If `scheduler` reports `fail`,
  the most common cause is that cron is not actually invoking `php artisan schedule:run` every
  minute on this deployment — check the cPanel/Docker scheduler wiring described in
  `docs/HANDOFF.md` §13 before assuming an application bug.

### Where the code lives

- Probe contracts and implementations: `app/Domain/Delivery/Health/` (interface `HealthProbe`,
  value objects `ProbeResult`/`HealthStatus`/`ReadinessReport`, orchestrator
  `ReadinessChecker`, and `Probes/*` for each check). `ArtifactIntegrityProbe` lives there with
  the others and reads `App\Domain\Evidence\Retention\ArtifactVerificationRun`, which the
  Evidence module writes.
- HTTP surface: `app/Http/Controllers/HealthController.php`, routed from
  `routes/health.php`, registered via the `then:` hook in `bootstrap/app.php` (not merged into
  `web.php`/`api.php`, so it never picks up session or CSRF middleware).
- Probe list wiring: `app/Providers/HealthServiceProvider.php`.
- Configuration: `config/esign.php`.
