# Pinned Firma OpenAPI schema

The `firma-compat-v1` profile is written against one exact upstream document. This file
pins it. The document itself is **not** committed — see "Licence and redistribution".

## Pin

| Field | Value |
|---|---|
| Reference version | **v1.40.4** (`info.version` = `01.40.04`), re-pinned by issue #171 |
| Document title | `Firma Partner API` (`info.title`) |
| OpenAPI version | `3.0.3` |
| Exact URL | `https://docs.firma.dev/api-reference/v01.40.04/openapi-v01.40.04.json` |
| SHA-256 | `6a05e97d3def17ed25dd5fc707bcd7fc42eba556ba11929d917ad6dd2aef2228` |
| Size | 867310 bytes |
| Retrieved | **2026-11-08T02:02:29Z** |
| Upstream `Last-Modified` | `Fri, 23 Oct 2026 09:58:43 GMT` |
| Paths | 86 (72 at v1.35.0) |

The digest was confirmed stable across three separate downloads at retrieval time.

### Why the pin moved

The original pin was v1.35.0 (`https://docs.firma.dev/api-reference/v01.35.00/openapi-v01.35.00.json`,
SHA-256 `91c7128a35ae52ac3937715041e41c1bee119cfb69b3dcf6fdc8135a4e3c98b9`, 621773 bytes,
retrieved 2026-09-08T06:52:14Z), the version named in `docs/HANDOFF.md` §2. Two things made it
stale:

1. Upstream moved through v1.36.0 to v1.40.4, and a consumer written against the current
   reference reads that document, not the one this profile was written against.
2. **The v1.35.0 URL no longer serves the document** ("Asset not found"), so a fresh clone could
   no longer verify the pin at all — the acceptance criterion of issue #2.

A copy of v1.35.0 is still published in upstream's documentation repository, but it is **not**
byte-identical to what was pinned (624259 bytes, SHA-256
`0c7cb262fecaef8c32accfd78162f957588a63df8bd00d76512011e6a847d1be`): upstream revised the
document after the pin. It was used only to locate the deltas the capability matrix's
"Delta from v1.35.0" section accounts for; nothing is pinned to it.

### How it was located

`https://docs.firma.dev/llms.txt` carries an `## OpenAPI Specs` section listing one
immutable per-version document per published release; v1.40.4 is the newest entry at
retrieval time. The unversioned `https://docs.firma.dev/api-reference/openapi.json` is a
*floating* document that tracks whatever the docs site currently publishes (D15). Only the
versioned URL above is a valid pin.

## Re-fetching and verifying

```bash
scripts/fetch-firma-schema.sh              # downloads to a temp file and verifies the digest
scripts/fetch-firma-schema.sh out.json     # downloads to out.json and verifies the digest
```

The script exits `3` on a digest mismatch. A mismatch means upstream re-published this
version. Do not bump the digest on its own: diff the new document against
`docs/compatibility/firma-capability-matrix.md` and update both in the same commit.

## Licence and redistribution

**The schema is not redistributable on any published terms, so it is not committed here.**

- The document declares no licence: `info.license` is absent, and so is
  `info.termsOfService`. `info.contact` is `{"name": "API Support", "url": "https://firma.com/support"}`.
- `https://firma.dev/legal/terms-conditions` (last updated 2026-01-31, 1600 Holdings LLC)
  governs credit purchasing, suspension, refunds, termination, governing law, signer legal
  effect, and data processing. It grants no copyright licence in the documentation or the
  API description, and says nothing about copying or redistributing either.
- The site footer asserts `© 2026 1600 Holdings. All rights reserved.`
- `https://docs.firma.dev/robots.txt` carries `Content-Signal: ai-train=yes, search=yes,
  ai-input=yes`. That is a crawling/training signal, not a redistribution grant.

Absent an explicit grant we treat the document as all-rights-reserved and keep only the
digest plus the fetch script. This satisfies the issue-#2 acceptance criterion — a fresh
clone can re-fetch the schema and verify the pinned digest — without vendoring third-party
material into an MIT repository (`docs/adr/0001-independent-mit-application.md`,
`docs/HANDOFF.md` §3).

Facts *about* the API (route names, field names, enum values, status codes) are recorded in
`docs/compatibility/firma-capability-matrix.md`. Those are interface facts needed to build a
compatible server, not a copy of the document.

## Scope of what the schema actually covers

Two things the `firma-compat-v1` profile needs are **not** in the machine-readable document
and were taken from prose instead:

1. **Webhook delivery payloads.** The document has no OpenAPI `webhooks` object and no event
   payload schema. It only names the delivery headers (`X-Firma-Event`, `X-Firma-Signature`,
   `X-Firma-Signature-Old`, `X-Firma-Delivery`) inside `TestWebhookResponse.headers_sent`.
   Since v1.40.2 it does enumerate the 26 event **names** on `POST /webhooks` (issue #170). The event
   names, the payload envelope, and the signature construction come from
   `https://docs.firma.dev/guides/webhooks` (retrieved 2026-09-08, unversioned — the docs
   site serves no per-version copy of that guide; `/v1-35-0/guides/webhooks` is a 404).
2. **Signing URL construction.** `https://app.firma.dev/signing/{recipientId}` appears in
   consumer code (`docs/HANDOFF.md` §2, R1) and in `CreateAndSendResponse.first_signer.signing_link`
   as an opaque URI. The document does not specify the format.

Both are marked in the capability matrix as prose-sourced, not schema-sourced.

## Fixtures

No captured request/response fixtures exist yet. The `fixture` column in the capability
matrix is deliberately blank until synthetic fixtures land under this directory
(`tests/Fixtures/firma/`). Public fixtures use synthetic agreements and synthetic identities
only (`AGENTS.md`).
