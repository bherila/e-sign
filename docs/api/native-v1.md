# The native API (`/api/v1`)

The HTTP surface an integration is meant to build against. It and the Firma-compatible facade
under `/functions/v1/signing-request-api` call the same domain services; there is one signing
state machine and neither surface holds signing rules
([docs/ARCHITECTURE.md](../ARCHITECTURE.md)).

Implements issue #32.

| | |
|---|---|
| Contract | [`resources/api/openapi-v1.json`](../../resources/api/openapi-v1.json), served at `GET /api/v1/openapi.json` |
| Routes | `routes/api.php` |
| HTTP | `app/Http/Controllers/Api/V1`, `app/Http/Requests/Api/V1`, `app/Http/Resources/Api/V1`, `app/Http/Middleware/Api` |
| Services | `app/Domain/Integration/Native` |
| Tables | `api_idempotency_keys` |
| Tests | `tests/Feature/Integration/Native` |

---

## The one rule to read first

**There is no workspace parameter anywhere in this API.** The credential you present belongs
to exactly one workspace, and that is the tenant for every lookup — never an id from a URL,
never a field in a body. Every query is constrained by it *before* the identifier from the
request is used.

The visible consequence is that an id belonging to another workspace answers **404**, exactly
like an id that does not exist anywhere. That is deliberate: a 403 would confirm the id
exists, and one forgotten comparison is a cross-tenant read
(`docs/HANDOFF.md` §10, "identifiers and foreign keys must not bypass scope").

---

## Authentication

A service credential in `Authorization`. Both syntaxes authenticate and are equivalent:

```http
Authorization: esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
Authorization: Bearer esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
```

Use `Bearer`. The raw form is accepted because the API this application is compatible with
sends its key with no scheme, and the facade would not be compatible without it
([docs/operations/service-credentials.md](../operations/service-credentials.md)).

Credentials are issued, rotated, and revoked from the console. There is no self-service route,
and there is no way to read a secret back — only a salted digest is stored, so a lost secret is
rotated, never recovered.

`GET /api/v1/me` is the first call to make: it tells you which workspace you are in, which you
cannot otherwise discover, and which scopes you hold.

**Failed authentications are counted, successful ones are not.** A client address that presents
an unusable credential more than `ESIGN_API_AUTH_FAILURES_PER_MINUTE` times (30) in a minute is
answered `429 too_many_requests` with `Retry-After` until the window passes. A working
integration never meets this: authenticating correctly costs nothing against the budget, however
often you call. It exists because nothing else on this surface limited anything, and each failed
attempt cost a query and a log line
([docs/security/review-2026-09.md](../security/review-2026-09.md), finding A-1). Set the value to
`0` where an edge proxy already owns the ceiling.

There is deliberately **no** overall request limit on this API. Choosing one without knowing an
integration's polling volume is an outage waiting for a busy afternoon; rate limiting the whole
surface belongs at the edge, where the operator can see the traffic.

### Scopes

| Scope | Endpoints |
|---|---|
| `templates:read` | `GET /templates`, `GET /templates/{template}` |
| `envelopes:read` | `GET /envelopes/{envelope}` and everything under it |
| `envelopes:write` | `POST /envelopes`, `PATCH`, `send`, `cancel` |
| `webhooks:manage` | everything under `/webhooks/endpoints` |
| *(none)* | `GET /me`, `GET /openapi.json` |

**Nothing is implied.** `envelopes:write` does not grant `envelopes:read`; an integration that
reads and writes is granted both. Implication rules read as convenience and behave as privilege
escalation the first time a scope is added in the wrong place.

`GET /me` needs authentication and no scope: every credential may ask what it is, and the
answer contains nothing the caller did not already hold. `GET /openapi.json` needs neither,
because a client generator has to read the contract before anyone has issued a key.

---

## Errors

Every failure, on every endpoint:

```json
{
  "error": {
    "code": "illegal_transition",
    "message": "Cannot send an envelope in state \"sent\".",
    "details": { "transition": "send", "from": "sent" }
  }
}
```

Switch on `code`. It is stable and renaming one is a breaking change; the message is for a
human reading a log, and `details` appears only when there is something structured to say.

| Code | Status | Means |
|---|---|---|
| `invalid_credential` | 401 | No credential, or one that is unknown, revoked, or expired |
| `insufficient_scope` | 403 | Valid credential, not granted the scope this route names (`details.required_scope`) |
| `not_found` | 404 | No such resource **in your workspace** — identical to one that does not exist |
| `method_not_allowed` | 405 | |
| `illegal_transition` | 409 | The move is illegal from this state, whoever asks (`details.transition`, `details.from`) |
| `conflict` | 409 | The move was legal and somebody else made it first — re-read and retry |
| `not_completed` | 409 | Artifacts asked for before the envelope completed |
| `idempotency_key_in_flight` | 409 | A request with this key is still running |
| `validation_failed` | 422 | The body did not validate (`details.fields`) |
| `idempotency_key_reused` | 422 | The same key with a different request |
| `invalid_cursor` | 422 | A cursor this API did not issue |
| `send_preconditions_failed` | 422 | Not sendable; `details.problems` lists **every** reason |
| `field_rejected` | 422 | A value was refused (`details.field`, `details.reason`) |
| `invalid_snapshot` | 422 | The envelope could not be built from what was given |
| `unknown_recipient` | 422 | A recipient id the schema does not declare |
| `template_state` | 422 | A template, version, or document is not usable for this call |
| `destination_refused` | 422 | A webhook URL the outbound policy will not call |
| `unknown_event_name` | 422 | An event name that is neither in the profile nor prefixed `esign.` |
| `too_many_requests` | 429 | Too many **failed** authentications from this client address in the last minute; `Retry-After` says how long |
| `unsupported` | 501 | Declared here and not implemented in this build — never a successful no-op |
| `internal_error` | 500 | Something unanticipated; the detail is in the server's log, not in your body |

A `409` is never retried into success by the same request. A `422` usually is, after you fix
what it named.

**Nothing leaks.** No body carries a stack trace, a file path, SQL, or the class name of an
unexpected failure. That is asserted in
`tests/Feature/Integration/Native/NativeApiErrorShapeTest.php` against production settings.

---

## Idempotency

Send `Idempotency-Key` on any `POST`, `PATCH`, or `DELETE`:

```http
POST /api/v1/envelopes
Idempotency-Key: 3f7d1c2e-...
```

- **Same key, same request** → the first response, byte for byte, with
  `Idempotency-Replayed: true`. Same envelope id, same everything.
- **Same key, different request** → `422 idempotency_key_reused`. That is a client bug —
  usually a key reused across two calls — and answering it with the first call's response
  would be a silent no-op on a call you believe you made.
- **Same key while the first attempt is still running** → `409 idempotency_key_in_flight`.
  Retry once it finishes.
- **Keys expire after 24 hours.** Past that the key is not a record of anything, so a retry is
  a real attempt rather than a replay of day-old content.

Two details worth knowing:

- **Only successful responses are replayed.** A failure releases the key, so a request you fix
  and retry under the same key is a real attempt. Replaying a transient 500 would make it
  permanent, and replaying a 422 would freeze a validation error you have since fixed.
- **The request digest covers the method, the path, the query string, and the body.** The query
  string is part of it because every endpoint here validates `$request->all()`, which merges the
  query — so `?grace_hours=24` and `?grace_hours=0` are two different requests and must not
  share an answer. They did, until
  [docs/security/review-2026-09.md](../security/review-2026-09.md) finding A-3.
- **The digest ignores JSON object key order, and query parameter order.** A client that
  serialises its map or its query differently on the retry still replays rather than being told
  it reused the key.
- **A recorded body is encrypted at rest.** Two endpoints return a webhook signing secret, so a
  cached copy in cleartext would have contradicted "only ciphertext is stored" one table over
  (finding A-2).

Keys are scoped to the **credential**, not the workspace: two integrations sharing a tenant
generate keys independently, and a collision between them must not make one replay the other's
response.

The table is pruned hourly by `esign:api:prune-idempotency-keys`
(scheduled in `routes/console.php`).

Idempotency is ours, not the upstream contract's; `docs/HANDOFF.md` §10 calls for exactly this
where the upstream contract lacks it.

---

## Pagination

Cursor-based, on every list:

```http
GET /api/v1/templates?limit=50
GET /api/v1/templates?limit=50&cursor=djE6NDI
```

```json
{ "data": [ … ], "meta": { "next_cursor": "djE6NDI" } }
```

`next_cursor` is null on the last page. Pass it back unchanged; it is opaque, and a value this
API did not issue is `422 invalid_cursor`. `limit` defaults to 25 and is **clamped** to 100
rather than refused, because a client asking for 500 wants as many as it can have.

There is no total and no page number. Counting a tenant's rows on every request buys a number
that is stale before it renders, and there are no pages to number in a keyset scheme — which is
the point: a cursor cannot skip or repeat a row when something is inserted mid-scan.

Collections bounded by an envelope's own field schema — recipients, values, artifacts — are
returned whole and still carry `meta.next_cursor: null`, so one paging loop works everywhere.

---

## Endpoints

| Method | Path | Scope | Notes |
|---|---|---|---|
| `GET` | `/openapi.json` | — | This API's OpenAPI 3.1 document. Unauthenticated. |
| `GET` | `/me` | — | Credential, workspace, scopes. |
| `GET` | `/templates` | `templates:read` | Paginated. Published versions only. |
| `GET` | `/templates/{template}` | `templates:read` | With the published version history. |
| `POST` | `/envelopes` | `envelopes:write` | From a template version **or** a document + field schema. |
| `GET` | `/envelopes/{envelope}` | `envelopes:read` | The polling endpoint. |
| `PATCH` | `/envelopes/{envelope}` | `envelopes:write` | Drafts only: prefills and contact corrections. |
| `POST` | `/envelopes/{envelope}/send` | `envelopes:write` | Issues the invitations. |
| `POST` | `/envelopes/{envelope}/cancel` | `envelopes:write` | Withdraws it, with a reason. |
| `GET` | `/envelopes/{envelope}/recipients` | `envelopes:read` | In signing order. |
| `GET` | `/envelopes/{envelope}/values` | `envelopes:read` | `?include=images` for signature bytes. |
| `GET` | `/envelopes/{envelope}/events` | `envelopes:read` | Paginated; the pull half of the webhook feed. |
| `GET` | `/envelopes/{envelope}/artifacts` | `envelopes:read` | 409 until the envelope completes. |
| `GET` | `/envelopes/{envelope}/artifacts/{artifact}/download` | `envelopes:read` | Streams the bytes. |
| `GET` | `/webhooks/endpoints` | `webhooks:manage` | Paginated. |
| `POST` | `/webhooks/endpoints` | `webhooks:manage` | Returns the secret **once**. |
| `PATCH` | `/webhooks/endpoints/{endpoint}` | `webhooks:manage` | Only the keys you send are applied. |
| `DELETE` | `/webhooks/endpoints/{endpoint}` | `webhooks:manage` | Retires it; the row is kept. |
| `POST` | `/webhooks/endpoints/{endpoint}/rotate-secret` | `webhooks:manage` | Returns the new secret **once**. |

### Creating an envelope

Exactly one source, never both:

```http
POST /api/v1/envelopes
Idempotency-Key: 3f7d1c2e-...

{
  "template_version_id": "01JC…",
  "title": "NDA — Acme Ltd",
  "expires_in_hours": 72,
  "recipients": [
    { "id": "buyer",  "name": "Dana Buyer", "email": "dana@buyer.example" },
    { "id": "seller", "name": "Sam Seller", "email": "sam@seller.example" }
  ],
  "values": { "agreement_effective_date": "2026-02-01" }
}
```

`recipients[].id` is the **schema** recipient id — the handle a field uses to find its owner —
not the recipient's own public id. Fields are never owned by signing order and never by email
address. The names and addresses you give here are written into the copied field schema before
anything is persisted, so the envelope, its recipients, and their identity snapshots agree from
the first row written. An id the schema does not declare is `422 unknown_recipient`, never
quietly ignored.

The alternative source is `document_id` plus the `field_schema` to place on it. Sending both,
or neither, is a `422`.

`expires_in_hours` is the one field where absent and null differ: omit it for the seven-day
default, or send `null` for an envelope that never expires.

An envelope **copies** its source. A template edited tomorrow cannot change an agreement that
is already out for signature.

Creation does not send. The envelope is a draft until you call `send`.

### Sending

`send` is the last cheap moment. After it, correcting material content means a new envelope
with renewed signatures, so the gate is strict — and it reports **every** problem at once under
`details.problems` rather than making you discover the next one after fixing the previous.

A requested `assurance_level` this deployment cannot actually meet is one of those problems. It
is never silently downgraded (`docs/HANDOFF.md` §9).

### Values

Every field of the schema, in schema order. A field nobody has completed is present with
`value: null`; omitting it would be indistinguishable from a field the schema does not have.

`signature` and `initials` are **described** rather than dumped:

```json
{
  "field_id": "buyer_signature",
  "type": "signature",
  "value": null,
  "signature": { "type": "image/png", "sha256": "…", "bytes": 68 }
}
```

That is enough to confirm a mark exists and to verify a copy you fetched deliberately.
`?include=images` adds `signature.data`, the captured value verbatim. The default withholds it
because a signature image returned unasked ends up in your request logs, your proxy caches, and
your error reports — and because an envelope carries one per signer.

The digest is over the **decoded** image bytes, so it is comparable with anything else that
hashed the same PNG.

`signing_date` has `source: "service"`: it is derived from the owning recipient's attestation
and is never submitted by anyone.

### Artifacts

Two refusals, and they mean different things:

- **`409 not_completed`** — a statement about the envelope. Nobody has finished signing, or
  finalization failed and is visibly failed rather than quietly complete.
- **`501 unsupported`** — a statement about this build. The envelope *has* completed, and
  finalization is not implemented yet, so the bytes this API promises do not exist anywhere.

Neither is ever a `200` with an empty list, which would tell you a completed agreement has no
PDF. The seam is `App\Domain\Integration\Native\ArtifactLocator`; the API ships with
`NoArtifactsYetLocator`, and binding a real one makes these routes work with no controller
change.

Bytes stream through the application. There is no presigned URL on this API, on any storage
driver ([docs/BLOB_STORAGE.md](../BLOB_STORAGE.md)). `X-Artifact-Sha256` carries the digest
recorded at finalization, so you can verify the transfer without a second request.

### Events

The same rows webhook deliveries are built from, oldest first, with a cursor you can persist.
If you cannot accept inbound HTTP, or your receiver was down for an hour, read them here in the
same order. `id` is the event id a receiver deduplicates on, and `payload` is the recorded body
verbatim — never rebuilt from current state, because a payload rebuilt on read would describe
the present rather than the event. The bodies are documented in
[docs/delivery/envelope-events.md](../delivery/envelope-events.md).

### Webhook endpoints

The secret is returned **once**, in the response to `POST` and to `rotate-secret`. Only
ciphertext is stored and there is no route that returns it again. If you sent an
`Idempotency-Key` with that call, replaying it within 24 hours returns the same body — which is
the point of the header, and why that recorded body is encrypted at rest like the secret itself.

`DELETE` **retires** an endpoint: it is disabled, receives nothing further, and comes back with
`enabled: false`. The row is kept because delivery history references it, and a delivery record
naming an endpoint that no longer exists is not a record of anything. A bare `204` would imply
an erasure that does not happen.

Rotation keeps the previous secret verifying for a grace window, during which both secrets sign
every attempt, so a receiver can be reconfigured without dropping an event. `grace_hours: 0`
cuts over immediately — the right value when you are rotating *because* a secret leaked. Send a
**fresh** `Idempotency-Key` for that call, or none: reusing the key from an earlier rotation with
a different `grace_hours` is now a `422 idempotency_key_reused` rather than a replay that looks
like a rotation and is not one.

---

## Honest language

Humans provide electronic signatures and assent; the service seals the finished PDF under its
own certificate. Nothing on this API is a per-signer certificate, and no assurance beyond the
PAdES baselines named in `assurance_level` is claimed. `completed` means a validated final PDF
is durably stored and retrievable — `finalizing` is not completion, and `finalization_failed`
stays visibly failed rather than becoming `completed`.

---

## The contract document

`resources/api/openapi-v1.json` is hand-written and committed, and `GET /api/v1/openapi.json`
serves those same bytes so a tool reading it over HTTP and a tool reading it from the
repository cannot see two different contracts.

It is JSON rather than YAML because there is no YAML parser in this application's runtime, and
adding a production dependency to translate a static file on the way out would be a poor trade
for nicer quoting. It is also the artifact: Redoc, Swagger UI, and client generators read it
straight from the repository without running the application.

Nothing generates it, and nothing has to.
`tests/Feature/Integration/Native/OpenApiContractTest.php` asserts both directions — every
registered `/api/v1` route and method appears in the document, and every operation the document
describes exists in the router — so a route added without a description, or a description left
behind by a deleted route, fails the suite.
