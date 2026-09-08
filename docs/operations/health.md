# Runbook: health and readiness

BWH eSign exposes two HTTP probes. They answer different questions and have different
audiences; do not point a load balancer at the detailed one or an operator dashboard at the
minimal one.

| Route | Purpose | Checks | Audience |
|---|---|---|---|
| `GET /up` | Liveness | Only that the PHP process answers HTTP. No database, no dependencies. Laravel's built-in probe (`bootstrap/app.php`, `health: '/up'`). | Load balancers, container orchestrators |
| `GET /health/ready` | Readiness | Database, queue lag, scheduler heartbeat, storage, mail configuration, mail backlog, webhook backlog, signing material, TSA configuration. | Operators, uptime monitors |

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
| `webhook_backlog` | Always, for now | — | — (placeholder; see below) |
| `signing_material` | Certificate and private key paths are configured and readable, and the certificate expires more than `esign.health.cert_warn_days` (default 30) days out | Unset outside production, or the certificate expires within the warn window | Unset in production, unreadable, unparseable, or expired |
| `tsa` | `ESIGN_TSA_URL` unset, or set and parses as `http`/`https` | — | Set but not a valid `http(s)` URL |

The queue, scheduler, and certificate thresholds live in `config/esign.php` under the
`health` key and are each overridable by an `ESIGN_HEALTH_*` environment variable. The mail
backlog thresholds live under the `mail` key, overridable by `ESIGN_MAIL_BACKLOG_*` and
`ESIGN_MAIL_FAILED_*`.

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
- **`webhook_backlog` is a placeholder.** The Delivery module's webhook outbox table does not
  exist yet (tracked in issue #34). Until it lands, this probe always reports `ok` with the
  message "No outbox yet." — replace its implementation, not its shape, once the outbox table
  exists.
- **The scheduler heartbeat** is written by a task in `routes/console.php`
  (`Schedule::call(...)->everyMinute()`) that stores the current time under the cache key
  `App\Domain\Delivery\Health\SchedulerHeartbeat::CACHE_KEY`. If `scheduler` reports `fail`,
  the most common cause is that cron is not actually invoking `php artisan schedule:run` every
  minute on this deployment — check the cPanel/Docker scheduler wiring described in
  `docs/HANDOFF.md` §13 before assuming an application bug.

### Where the code lives

- Probe contracts and implementations: `app/Domain/Delivery/Health/` (interface `HealthProbe`,
  value objects `ProbeResult`/`HealthStatus`/`ReadinessReport`, orchestrator
  `ReadinessChecker`, and `Probes/*` for each check).
- HTTP surface: `app/Http/Controllers/HealthController.php`, routed from
  `routes/health.php`, registered via the `then:` hook in `bootstrap/app.php` (not merged into
  `web.php`/`api.php`, so it never picks up session or CSRF middleware).
- Probe list wiring: `app/Providers/HealthServiceProvider.php`.
- Configuration: `config/esign.php`.
