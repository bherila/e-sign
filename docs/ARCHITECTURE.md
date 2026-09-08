# Architecture

A modular monolith. One set of domain services serves the administrative UI, the guest signing
UI, the native API under `/api/v1`, and the Firma-compatible facade under
`/functions/v1/signing-request-api`. There is exactly one signing state machine; compatibility
controllers translate shapes, they never hold state or rules.

## Module boundaries (`app/Domain/`)

| Module | Owns |
|---|---|
| `Identity` | identity bindings (issuer + subject, never email), workspace memberships, local roles (`owner`, `admin`, `sender`, `auditor`), service credentials, recipient access sessions |
| `Preparation` | PDF preflight, immutable review revisions, page geometry and coordinate transforms, text/anchor location, field definitions (versioned native schema), templates and template versions |
| `Signing` | envelopes, recipients, signing sessions, consent, submitted values, immutable attestations, ordering and transition guards |
| `Evidence` | audit events, digests, rendering and sealing, staged artifact publication, evidence export, retention and legal hold |
| `Delivery` | mail outbox, webhook outbox, retries, attempt logs, operational health |
| `Integration` | native API, Firma facade and capability matrix, import and migration adapters |

`Identity` also holds the append-only `esign_audit_events` store, because provisioning is
the first thing that happens on a new instance and it happens before there is anything to
sign. `Evidence` still owns the envelope and artifact audit trail and writes into the same
table. Authentication events stay in auth-laravel's `auth_audit_log`.

Suggested ports (interfaces) with swappable implementations: `PdfPreflight`, `PdfTextLocator`,
`PdfAssembler`, `PdfSealer`, `ArtifactValidator`, `TimestampAuthority`. Cryptography stays in
established libraries. No bespoke CMS/ASN.1 or PDF signature byte-range code.

## Lifecycle

```
draft -> sent/in_progress -> finalizing -> completed
                 \-> cancelled | declined | expired
finalizing failures stay visibly pending/failed; they never become completed
```

Recipient progress is modelled independently of envelope completion. Signer acceptance time,
countersigner time, sealing time, and delivery time are distinct facts.

## Invariants enforced in domain services

1. A recipient completes only their own fields, only while eligible under the configured order.
2. An acceptance binds to a specific review revision, material values, consent version, and
   recipient session. Stale submissions fail and require re-review.
3. Required fields, identities, consent, and ordering are validated server-side. Client flags
   carry no authority.
4. After the first acceptance, substantive content is frozen; only anticipated signer-specific
   fields may be added. Material corrections mean a new version and renewed signatures.
5. A completed envelope has a durable, validated final PDF and a complete evidence reference.
6. Races (cancel/expire/sign/finalize) resolve to one legal outcome under a transaction or
   compare-and-swap guard.
7. Retries and redelivery yield one logical acceptance or completion; external transports stay
   at-least-once.

## Artifact publication

Staged, never a pretend cross-store transaction:

1. Under an envelope lock, capture the immutable finalization input and a generation id; move to
   `finalizing`.
2. Outside the lock, render, seal, validate, hash, and upload to a new final key. Read the bytes
   back and confirm the digest through the storage adapter.
3. In a new transaction, re-check generation, revision, and state; publish the artifact reference,
   completion evidence, and the completion outbox event together.
4. On retry, reuse the selected completed artifact. Never garbage-collect evidence by prefix age.

## HTTP surfaces call the same services

Both surfaces are adapters over `app/Domain/Integration/Native/*`, and neither is allowed to
be the only caller of anything:

| | |
|---|---|
| Native API | `/api/v1` — `routes/api.php`, `app/Http/Controllers/Api/V1`, [docs/api/native-v1.md](api/native-v1.md) |
| Firma facade | `/functions/v1/signing-request-api`, profile `firma-compat-v1` |
| Shared services | `EnvelopeService`, `TemplateCatalog`, `EnvelopeValueReader`, `EnvelopeEventFeed`, `WebhookEndpointService`, `IdempotencyStore`, `ArtifactLocator` |

**The facade calls those same services.** It translates shapes — status vocabularies,
coordinate conventions, field names, error bodies — and it must not reach past them into the
state machine, the envelope factory, or the models, and must not grow a second way to create,
send, or cancel an envelope. Section 10 of `docs/HANDOFF.md` requires the facade to preserve
somebody else's response shapes, which is a *presentation* obligation; the moment it becomes a
behaviour obligation there are two state machines, and the second one is the one nobody tests.

Concretely: `EnvelopeService::create()` is the only path from a request to an envelope, and it
is the only place that turns a template version or a document plus a field schema into an
`EnvelopeSourceSnapshot`. Recipient contacts are written into the copied schema there, before
anything is persisted. If the facade needs a behaviour the native API does not have, the
behaviour is added to the service and both surfaces get it.

Two things are deliberately *not* shared, because they are per-surface contracts rather than
behaviour:

- **Error bodies.** `App\Http\Middleware\Api\ApiErrorBoundary` applies the native
  `{error: {code, message, details}}` envelope to `/api/v1` only. The facade owes different
  bodies on its own routes. The mapping from a domain exception to a meaning is shared
  (`ApiErrorMap`); only the rendering differs.
- **Pagination and identifiers.** The native API pages with an opaque keyset cursor and
  reports public ULIDs. The facade reproduces whatever the profile documents.

`ArtifactLocator` is the seam between this and finalization: the API is written against it and
ships with `NoArtifactsYetLocator`, so artifact routes fail closed until a real locator is
bound.

## Deployment shape

The same application runs in three roles: web, queue worker, scheduler. In Docker, signing key
material is mounted only into the worker role. On cPanel, cron runs bounded queue work under a
database lease; that profile cannot isolate keys from application PHP and the documentation says
so.

Decisions that change this document get an ADR in `docs/adr/`.
