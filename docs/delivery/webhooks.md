# Webhook delivery

How BWH eSign publishes events, what a receiver has to do to verify one, and what an
operator can do when delivery goes wrong.

Scope note: this document is the transport — the envelope, the signature, retries, rotation,
replay, and the destination policy. *Which* events exist, what their `data` object contains,
and which message each one also produces is `docs/delivery/envelope-events.md`. The one caller
of `OutboxWriter::record()` in production code is
`App\Domain\Delivery\Events\DeliveryEnvelopeEventSink`, which the signing state machine
invokes inside the transaction that made the transition.

## The contract at a glance

| | |
|---|---|
| Method, content type | `POST`, `application/json` |
| Signature header | `X-Firma-Signature: t=<unix seconds>,v1=<hex>[,v1=<hex>]` |
| Rotation header | `X-Firma-Signature-Old: t=<unix seconds>,v1=<hex>` (only during an overlap) |
| Event type header | `X-Firma-Event: <event name>` |
| Attempt id header | `X-Firma-Delivery: <ULID>` |
| Event id header | `X-Esign-Event-Id: <ULID>` (extension) |
| Attempt number header | `X-Esign-Attempt: <n>` (extension) |
| Success | Any 2xx, within the configured timeout (default 5 s) |
| Redirects | Never followed |

## The envelope

```json
{
  "id": "01K4S6ZT4Q1S9WB0Y8V3H2N7XD",
  "type": "signing_request.completed",
  "created_at": "2026-09-08T10:11:12Z",
  "workspace_id": "01K4S6ZQ8F0J5C7A2M6P4R9TQE",
  "data": { }
}
```

- **`id`** is the logical event identity. It is a ULID, it is stable across every retry and
  every replay, and it is the thing to deduplicate on. The consumer's own resolver reads
  `event_id` first and falls back to a payload hash
  (`docs/compatibility/firma-capability-matrix.md`, disagreement D13); we emit `id`, the
  name the upstream envelope uses, and the consumer must normalise the two names.
- **`created_at`** is when the domain transition *occurred*, not when the attempt was made.
  Deliveries can arrive out of order — two events sent seconds apart can be reordered by a
  retry — and this field is what survives that. Do not infer order from arrival.
- **`company_id`** appears in the upstream envelope and is deliberately absent here: this
  product has no company above the workspace, and emitting a null or an invented value
  would be worse than its absence.
- **`data`** is the event's payload, shaped per event family.

The JSON is encoded once, when the event is recorded, and stored on the event row. Every
attempt sends those exact bytes. Re-encoding at send time could reorder a key or change an
escape and produce a signature the receiver cannot reproduce.

## Verifying a signature

```text
signed_payload = ASCII(timestamp) . "." . exact_raw_json_body
expected       = hex(HMAC-SHA256(secret, signed_payload))
```

```php
[$timestamp, $signatures] = parseFirmaSignature($request->header('X-Firma-Signature'));

// 1. Freshness. Reject anything outside your replay window before doing crypto.
abort_if(abs(time() - $timestamp) > 300, 400);

// 2. Constant-time compare against the RAW body, not a re-encoded one.
$expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
$ok = false;
foreach ($signatures as $candidate) {
    $ok = hash_equals($expected, $candidate) || $ok;   // no early return: keep it constant-time
}
abort_unless($ok, 400);
```

Three rules that are not optional on the receiving side:

1. **Verify the raw body.** Framework-decoded-and-re-encoded JSON is a different byte string.
2. **Enforce a replay window.** The upstream guide calls the five-minute tolerance optional;
   for this profile it is not (`docs/HANDOFF.md` §11). Each attempt gets a fresh `t`, so a
   legitimate retry two days later still lands inside the window.
3. **Compare in constant time**, and treat a mismatch as a 400, not a 500 — a 500 asks us to
   retry a request that will never verify.

### Why two `v1=` entries instead of a second header

During a rotation overlap, `X-Firma-Signature` carries one `t=` and one `v1=` per live
secret, current first. That is the Stripe convention rather than the upstream one, chosen
because a receiver that already loops over the `v1=` entries needs no change to survive a
rotation, and a receiver that reads only the first `v1=` still verifies, since the current
secret is first. For receivers written literally against the upstream guide, the documented
`X-Firma-Signature-Old` header is emitted alongside during the overlap, carrying the
previous secret's signature on its own. Both are recorded in the capability matrix.

## Rotation

```bash
php artisan esign:webhook:endpoint:rotate-secret <endpoint-ulid> --grace-hours=168
```

The new secret is printed once and never again. Until the grace window expires
(`ESIGN_WEBHOOK_ROTATION_GRACE_HOURS`, default 168 h = 7 days), both secrets sign every
attempt, so the receiver can be reconfigured at any point inside the window without dropping
an event. One second past the deadline the old secret stops signing, which is the point of
having a deadline. A second rotation inside the window discards the secret-before-last: only
ever two secrets are live.

## Retries

| | Default | Configuration |
|---|---|---|
| Backoff after attempt 1, 2, … | 1 m, 5 m, 30 m, 2 h, 12 h, 24 h | `ESIGN_WEBHOOK_RETRY_DELAYS` |
| Jitter | ±10 % of each delay | `ESIGN_WEBHOOK_RETRY_JITTER` |
| Attempts | 7 (six retries), ~40 h | length of the delay list + 1 |
| Timeout | 5 s | `ESIGN_WEBHOOK_TIMEOUT` |
| Auto-disable | 10 consecutive undeliverable events | `ESIGN_WEBHOOK_AUTO_DISABLE_AFTER` |

The upstream profile documents immediate, +5 min, +1 h, three attempts, and auto-disable at
50 consecutive failures. Ours is longer, jittered and configurable; the difference is
recorded in the capability matrix rather than hidden. Jitter matters more than it looks: an
outage that fails every endpoint at once would otherwise produce a retry stampede at exactly
the same instant, knocking a recovering receiver over a second time.

**What is retried.** Any 5xx, a connection failure, a timeout, and the two 4xx codes that
mean "later": 408 and 429.

**What is not.** Every other 4xx. A 400, 403, 404 or 410 is the receiver saying the request
itself is wrong — a bad path, a rejected signature, a payload it will not accept. Repeating
it unchanged for two days cannot help, so the attempt is marked `failed` with no successor
and the endpoint's failure streak advances. Fix the receiver, then replay.

**A timeout is not a failure to deliver.** The receiver may well have processed the event
before the connection dropped. This is exactly why the event id is stable and deduplication
is the receiver's job.

### Attempt states

Each attempt is its own row in `webhook_deliveries`.

| State | Meaning |
|---|---|
| `pending` | Due at `next_attempt_at`; not yet attempted |
| `succeeded` | 2xx |
| `failed` | The attempt failed. `next_attempt_at` set → a retry follows; null → the response said retrying cannot help |
| `exhausted` | The last attempt in the schedule failed |

`response_excerpt` and `error` are redacted (anything that looks like a token, a JWT, an
`Authorization` header, or a long opaque run) and truncated to 1 KB before they are stored.
A receiver's error page routinely echoes the request it did not like; our diagnostics table
is not a credential store.

## Replay

```bash
php artisan esign:webhook:replay <event-ulid> [--endpoint=<endpoint-ulid>]
```

A replay is a **new attempt, never a new event**: the next attempt number, its own attempt
id, a fresh signature timestamp, the same event id, and the same body bytes. An idempotent
receiver recognises the event it already processed, which is what makes replay safe to hand
an administrator. Replay to a disabled endpoint is refused with the command that re-enables
it; events recorded while an endpoint was disabled are not queued for it at all, so replay
is how they are delivered afterwards.

## Destination policy

Every attempt — not just endpoint creation — passes
`App\Domain\Delivery\Outbound\DestinationPolicy` before a packet leaves the process. The
same policy guards the RFC 3161 timestamp authority, so there is one place to configure and
one place to audit.

Refused:

- any scheme but `http`/`https`, and a URL that does not parse;
- credentials in the URL;
- plaintext HTTP;
- a host that resolves to a loopback, private (RFC 1918), link-local (including
  `169.254.169.254` and every other metadata address), IPv6 unique-local, carrier-grade-NAT
  (RFC 6598), IETF-assigned (RFC 6890) or benchmarking (RFC 2544) address;
- a host that resolves inside an IPv6 transition prefix — NAT64 (RFC 6052, RFC 8215), 6to4
  (RFC 3056) and its relay anycast block (RFC 7526), Teredo (RFC 4380), or discard-only
  (RFC 6666). These carry an embedded IPv4 address, so a v6 literal can otherwise name a v4
  destination the v4 checks refuse. Each prefix is refused whole rather than decoded and
  re-checked; a receiver is never inside one, and an administrator allowlist entry is the
  only way to a destination there;
- a host that resolves to *both* a public and an internal address — refused outright rather
  than left to connection ordering;
- a host that does not resolve.

An AAAA lookup that fails rather than returning nothing (SERVFAIL, a timeout, a resolver that
refuses the query type) is indistinguishable from "no AAAA record" and is treated as the
latter, because refusing on it would break delivery on any network whose resolver filters
AAAA. Address pinning is what makes that safe: a record nobody validated cannot be reached
even if one existed.

Enforced on the connection:

- **no redirects**, at both the Guzzle and cURL layers: only the first hop was validated, so
  a 302 to the metadata service would walk past every check above;
- **address pinning** via `CURLOPT_RESOLVE` to exactly the addresses that were checked,
  which closes the DNS-rebinding window between validation and connection;
- TLS verification on, protocol set narrowed to http/https.

Because the policy runs per attempt, a host that was public when the endpoint was created
and starts answering with an internal address is caught at the next attempt.

### The administrator allowlist

The only way past the private-address and plaintext refusals. It is **deployment
configuration**, never a per-endpoint or per-request flag: a workspace administrator who can
create a webhook endpoint must not be able to aim delivery at the instance's own metadata
service.

```dotenv
ESIGN_DELIVERY_ALLOWLIST="consumer.internal.example|private|plaintext,10.8.0.0/24|private"
```

Comma-separated entries; each is a host or a CIDR followed by any of the `private` and
`plaintext` flags. An entry grants only what it lists — `private` without `plaintext` means
the internal address is reachable but the body still has to travel over TLS. A `plaintext`
grant expressed as a CIDR only covers a URL written with an IP literal, because the scheme
is judged before DNS is consulted; name the host to grant plaintext to a hostname.

## Recording an event (for callers inside the application)

`OutboxWriter::record()` **must** be called inside the same database transaction as the
domain transition it describes, and refuses to run otherwise:

```php
DB::transaction(function () use ($envelope, $outbox): void {
    $envelope->markCompleted();

    $outbox->record($envelope->workspace, 'signing_request.completed', [
        'signing_request' => [...],
    ]);
});
```

The event row and the transition then commit together or roll back together. Fan-out is a
job dispatched *after* the commit, so a worker can never read an event row a rollback is
about to remove.

Event names are checked against a closed list: the exact `signing_request.*` names the
profile documents, or a name of ours prefixed `esign.`. A typo, or an event upstream does not
have (there is no `signing_request.declined` — disagreement D12), is refused rather than
recorded as an event no receiver will ever handle.

`signing_request.completed` carries one further rule that lives with its caller: it may only
be recorded once the validated final PDF is durably stored and retrievable
(`AGENTS.md`, "Fail closed"). This is deliberately later than upstream, which ties the event
to signing completion.

## Operations

```bash
php artisan esign:webhook:endpoint:create <workspace> <url> [--description=] [--event=…]
php artisan esign:webhook:endpoint:list [--workspace=]
php artisan esign:webhook:endpoint:rotate-secret <endpoint> [--grace-hours=]
php artisan esign:webhook:endpoint:disable <endpoint> [--reason=]
php artisan esign:webhook:endpoint:enable <endpoint>
php artisan esign:webhook:replay <event> [--endpoint=]
php artisan esign:webhook:backlog
```

Every command that changes state writes an audit event (`webhook.endpoint.created`,
`…secret_rotated`, `…disabled`, `…enabled`, `…auto_disabled`, `webhook.event.replayed`).
Secrets never appear in an audit payload, a log line, an exception message, or the list
output — only the fact that a rotation happened and when the old secret stops signing.

`--event` may be repeated to filter an endpoint to specific events; omitting it delivers
every event. Filters match exactly, with no wildcards, so an event family added later is
never silently delivered to an endpoint that did not ask for it by name.

The `webhook_backlog` readiness probe reports the age of the oldest **overdue** delivery
against `ESIGN_WEBHOOK_BACKLOG_WARN_SECONDS` / `…_FAIL_SECONDS`, plus the number of disabled
endpoints (a warn, not a fail: delivery has stopped and someone needs to know, but the
instance is not unready). A retry scheduled for twelve hours from now is the schedule
working, not a backlog, and is counted separately. `esign:webhook:backlog` prints the same
snapshot.

## Tests

| Behaviour | Test |
|---|---|
| Signature over the raw body, verified by an independent HMAC | `tests/Feature/Delivery/Webhooks/DeliverWebhookTest.php` |
| Scheme, header shape, rotation entries | `tests/Unit/Delivery/WebhookSignerTest.php` |
| Timestamp freshness per attempt | `DeliverWebhookTest::test_the_signature_timestamp_is_fresh_at_the_moment_of_the_attempt` |
| Rotation overlap and expiry | `DeliverWebhookTest`, `WebhookConsoleTest` |
| Stable event id across retries and replay | `DeliverWebhookTest` |
| Retry schedule, exhaustion, auto-disable | `DeliverWebhookTest`, `tests/Unit/Delivery/RetryScheduleTest.php` |
| Timeout / lost response, 5xx vs 4xx | `DeliverWebhookTest` |
| Reordering (distinct `occurred_at`) | `tests/Feature/Delivery/Webhooks/OutboxWriterTest.php` |
| Transactional-outbox rule | `OutboxWriterTest` |
| SSRF: private, loopback, link-local, metadata, DNS answer, plaintext, allowlist | `tests/Unit/Delivery/DestinationPolicyTest.php` |
| Redirects refused, address pinning | `tests/Unit/Delivery/WebhookTransportOptionsTest.php` |
| Redaction of stored responses and errors | `tests/Unit/Delivery/TextRedactorTest.php`, `DeliverWebhookTest` |
| Administration and audit | `tests/Feature/Delivery/Webhooks/WebhookConsoleTest.php` |
| Backlog probe thresholds | `tests/Feature/Delivery/WebhookBacklogProbeTest.php` |
