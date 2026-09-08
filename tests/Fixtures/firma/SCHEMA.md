# Pinned Firma OpenAPI schema

The `firma-compat-v1` profile is written against one exact upstream document. This file
pins it. The document itself is **not** committed — see "Licence and redistribution".

## Pin

| Field | Value |
|---|---|
| Reference version | **v1.35.0** (`info.version` = `01.35.00`), the version named in `docs/HANDOFF.md` §2 and §17 |
| Document title | `Firma Partner API` (`info.title`) |
| OpenAPI version | `3.0.3` |
| Exact URL | `https://docs.firma.dev/api-reference/v01.35.00/openapi-v01.35.00.json` |
| SHA-256 | `91c7128a35ae52ac3937715041e41c1bee119cfb69b3dcf6fdc8135a4e3c98b9` |
| Size | 621773 bytes |
| Retrieved | **2026-09-08T06:52:14Z** |
| Upstream `Last-Modified` | `Tue, 25 Aug 2026 08:28:33 GMT` |
| Declared servers | `https://api.firma.dev/functions/v1/signing-request-api` (current), `https://api.firma.dev/api/v1` (planned) |

The digest was confirmed stable across three separate downloads at retrieval time.

### How it was located

`https://docs.firma.dev/llms.txt` carries an `## OpenAPI Specs` section listing one
immutable per-version document per published release; v1.35.0 is the newest entry. The
unversioned `https://docs.firma.dev/api-reference/openapi.json` also resolves (HTTP 200)
but is a *floating* document that tracks whatever the docs site currently publishes — at
retrieval time it was a different, smaller file (227508 bytes,
`8eaa4b7f8d6d52f06c548dc69e2382bba89f3dd83b34daa767b9b556b8e0ecf8`, 31 paths versus 72).
Only the versioned URL above is a valid pin. `openapi.yaml`, `mint.json`, `docs.json` and
`/api-reference/v01.35.00/openapi.json` all return 404.

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
   `X-Firma-Signature-Old`, `X-Firma-Delivery`) inside `TestWebhookResponse.headers_sent`,
   and types `Webhook.events` as an unconstrained `array<string>` with no enum. The event
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
