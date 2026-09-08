# Project brief

Written 2026-09-07, before any code. The detailed specification derived from this brief is
[HANDOFF.md](HANDOFF.md). Where the two disagree, HANDOFF.md wins; where either disagrees with
what a dependency actually does, the dependency wins and the docs get fixed.

## Recommendation

Build an original MIT-licensed Laravel application with a Firma compatibility layer, not a fork
of an existing signing product. Repository `bherila/e-sign`, working product name "BWH eSign".

The core design: **capture each person's informed assent, preserve the exact agreement they
reviewed, and cryptographically seal the executed PDF with independently verifiable evidence.**
The visual editor matters, but the document-integrity and signing pipeline is proved first.

## Why not deploy or fork an existing product

| Product | Relevant capabilities | Fit |
|---|---|---|
| DocuSeal | Visual PDF fields, multiple signers, SMTP, storage integrations, API/webhooks; Ruby on Rails. | Closest off-the-shelf deployment candidate. AGPLv3 with additional terms; roles, SSO, and some embedding are Pro features. |
| Documenso | Self-hosted; TypeScript, React Router/Hono, PostgreSQL. | Not a PHP/MySQL foundation; AGPL. |
| OpenSign | Self-hosted document signing. | AGPL, not MIT. |

For deploying an existing service, evaluate DocuSeal first. For publishing an MIT PHP
application with cPanel support, build independently and reuse libraries.

Naming: avoid "Docsign" (an existing product). "BWH eSign" is a working label pending clearance.

## What the current repositories change about the plan

- **The consumer uses four signing workflows**: platform NDAs, practice NDAs, order forms, and
  data-destruction certifications. Its retention service distinguishes matched, mismatched, and
  unverifiable vendor hashes; migration must not erase those states.
- **Signing URLs are hardcoded** to `https://app.firma.dev/signing/{recipientId}`. The client
  needs a provider-aware signing-URL/access-session resolver, not just a configurable base URL.
- **The response contract is specific**: boolean status fields when polling, `results` wrappers
  for users and fields, `finished_on` recipient timestamps, particular field-value encodings, a
  JSON `download_url` rather than a PDF body, and the raw API key in `Authorization` without a
  Bearer prefix.
- **The auth packages** provide OAuth authorization-code with PKCE on a PHP 8.4+/Laravel 13
  floor. Auth-manager owns identity and coarse application-access grants; applications own
  fine-grained permissions.
- **A correctness fix to carry into the migration**: platform-NDA creation warns on a failed
  `/send` and still returns a signing URL, whereas the data-destruction path throws. The
  replacement fails closed.

## Architecture

An independently deployed modular Laravel application, one signing engine shared by the UI,
native API, and Firma facade.

| Deployment address (configurable, illustrative) | Responsibility |
|---|---|
| identity host | Identity, credentials, passkeys, application-access grants |
| consumer app host | Business workflows and retained agreement copies |
| signing host | Document preparation, signing, evidence, API, delivery |

The signing app has its own database credentials, application key, session cookie, storage
credentials, and API/webhook secrets. It may share physical MySQL and object storage servers
without sharing tables or credentials. Preparation, signing, evidence, delivery, and
compatibility adapters are separate modules. There is no second signing state machine inside
the Firma-compatible controllers.

### Administrator SSO and provisioning

Use auth-laravel's existing client. Bind local identities to validated issuer + subject, not
email. Provide a tested, idempotent administrator-bootstrap command that requires an explicitly
selected identity. Never make the first successful login an administrator. Initial role model:
workspace-scoped owner, admin, sender, auditor. Auth-manager admits a person to the app; the
app decides what they can do. Central role management would be new auth-manager functionality.

### Passkeys

A parent-domain RP ID is valid for a subdomain deployment, but redirect-based SSO does not require
each app to verify passkeys. Keep registration and verification at auth-manager. Keep app
sessions host-only; do not share parent-domain cookies, `APP_KEY`, or passkey tables. Offer a
standalone local-admin mode for other installations. Recipients get a separate guest flow.

## PDF preparation and the visual/JSON interface

The signer must review the same agreement revision the service later uses to produce the
executed document. Workflow:

upload -> preflight -> immutable review revision -> fields/recipients -> validate -> send ->
collect assent -> finalize/seal -> publish evidence.

Retain the original upload exactly. Normalize only before review, as a traceable revision,
disclosed to the sender. Editor scope for release one: page navigation, zoom, drag/resize,
numeric placement, recipient assignment, required/read-only, prefill mapping, keyboard editing,
mobile signing; field types signature, initials, text/name/company/title, dates, checkbox. One
versioned field schema shared by editor and API, round-tripping without drift.

Coordinates are a real compatibility trap: the consumer documents a live-tested 0-100 percentage
convention, while Firma's atomic-endpoint examples include values above 100. Resolve with
fixtures, never by inferring units from magnitude. Define one internal coordinate space with
explicit CropBox, rotation, and unit transforms. Anchors resolve deterministically before send.

Do not silently invalidate existing PDF signatures: the candidate importer does not preserve
them and cannot read encrypted PDFs. Reject those inputs in release one.

## Cryptography: separate human assent, document integrity, and trust

A person typing or drawing a signature provides an electronic signature/assent. The server
sealing the PDF with an organizational certificate provides a cryptographic service seal. That
is not each human controlling a personal signing certificate.

| Capability | Release position |
|---|---|
| Human assent, attribution, consent, retained evidence | Required |
| Sealed PDF targeting PAdES B-B | Required baseline |
| PAdES B-T via configured RFC 3161 TSA | Supported production option |
| B-LT/B-LTA with validation material and preservation | Later, separately verified |
| eIDAS qualified human signatures | Separate integration, never implied |

`tecnickcom/tc-lib-pdf` (pure PHP, LGPL-3.0, PDF import and PAdES support per its docs) is the
first candidate to prototype, not a validated capability. Application code stays MIT; the
library keeps its license and distribution must satisfy LGPL conditions. Do not implement
CMS/ASN.1 or signature byte ranges from scratch.

Validate output, not configuration flags: generate representative artifacts and validate them
with pyHanko and European Commission DSS plus explicit requirements tests. A configured "B-LT"
option is not sufficient if validation material was unavailable; never silently downgrade while
reporting success.

Self-hosting removes the signing-SaaS dependency. It does not create trust: self-issued
certificates and application timestamps are not independently trusted. Keep TSA and certificate
providers configurable. Have counsel approve the US consent, authority, access/copy, and
retention flow. Do not advertise eIDAS advanced or qualified signatures from email verification
plus an organization seal.

Evidence export: executed PDF, reviewed revision, structured evidence, human-readable completion
report, explicit digests, certificate/validation information. Signer acceptance, countersigning,
sealing, and delivery times stay distinct. Signing keys are protected, versioned, and not
`APP_KEY`; in Docker, exposed only to the worker. A hash chain in an administrator-writable
database is not an independent witness; keep off-host checkpoints and signer copies.

## Firma compatibility as a tested contract

Native API at `/api/v1`; facade at `/functions/v1/signing-request-api`. Start with the named
acceptance profile `firma-compat-v1` covering every actual consumer interaction, then expand via
an explicit capability matrix. Unsupported functionality fails clearly. HTTP/webhook
compatibility does not imply compatibility with Firma's hosted editor, website, or JS SDK.
Discovery must obtain and hash the official OpenAPI schema; the v1.35.0 reference was inspected
but the complete JSON schema was not retrieved and no live round trip was run.

Webhooks: `signed_payload = timestamp + "." + raw_body`, `HMAC-SHA256(secret, signed_payload)`,
header `X-Firma-Signature: t=<timestamp>,v1=<hex>`. Logical events are stored separately from
delivery attempts. Transactional outbox in the signing app, durable inbox in the consumer. A
finalization failure never sends a false completion event. The consumer's receiver normalizes both
`event_id` and `id`.

## Storage, mail, hosting

Private Laravel Storage disks with app-authorized temporary download routes. Garage's S3
compatibility lacks object versioning and Object Lock, so do not describe it as WORM or legal
hold; use non-overwritten keys, application-level retention, explicit legal holds,
reconciliation, and separately administered backups. Publish artifacts in stages; MySQL and S3
do not share a transaction.

Mail via Laravel Mailables and queues; Brevo SMTP is the simplest first path and remains
swappable. Distinguish queued, accepted, bounced, and signed. A log mailer is not delivery.

| Docker | cPanel |
|---|---|
| One image, web/worker/scheduler roles | Prebuilt assets, PHP + MySQL |
| Managed queue worker | Bounded cron-driven queue work with overlap protection |
| Better signing-key isolation | Documented shared-account key limitation |
| Reuse existing proxy, MySQL, object store | Local disk or S3-compatible; SMTP |

Do not advertise cPanel support while depending on a remote Python/Node signing service.

## Consumer migration

Do not migrate by flipping one global provider URL. Persist provider identity per signing
request; existing Firma requests finish on Firma; new requests use the self-hosted service
behind a feature flag. Import active template versions and field mappings explicitly. Keep
every retained executed Firma document byte-for-byte unchanged. Pilot all four workflows in
staging including countersigning, cancellation, recipient timestamps, polling, download,
retention, and audit views. Rollback affects only creation of future requests.

## Build order

| Stage | Deliverable and gate |
|---|---|
| 0. Contract and crypto feasibility | Pin API/schema fixtures; resolve coordinate/anchor behavior; prove PHP PDF fidelity and independently validated signing; review licenses. |
| 1. Application and identity | Laravel skeleton, workspace policies, explicit owner provisioning, working SSO and standalone login, CI. |
| 2. Preparation and editor | Safe uploads, immutable review revisions, template versions, visual fields, native JSON import/export. |
| 3. Signing and evidence | Guest access, consent, ordering, immutable attestations, finalization, seal, evidence export, recovery. |
| 4. API and delivery | Native/Firma adapters, endpoint-specific serializers, service credentials, mail/webhook outboxes, replay and contract tests. |
| 5. Deployment hardening | Docker/cPanel installation tests, key management, monitoring, backups/restore, security and assurance documentation. |
| Consumer workstream | Provider boundary first; staged integration second; no production cutover until all four workflows pass. |
| Auth-manager workstream | App registration/grants first; optional central role management separately. |

Critical release tests: modified PDFs fail validation; stale or wrong-recipient submissions
fail; retries do not duplicate logical signatures; crashes never publish false completion;
cross-workspace access is denied; a new workflow completes with Firma/DocuSign hosts blocked.

**The first assignment is Stage 0, not the editor**: prove a representative NDA can be prepared,
reviewed, signed, sealed, independently validated, and retained on the PHP-only runtime.

## References

DocuSeal, Documenso, OpenSign repositories; docsign.com; W3C WebAuthn Level 3; Firma API
reference v1.35.0 and webhook guide; TCPDF import and digital-signature docs; tc-lib-pdf; ETSI
EN 319 142-1; pyHanko validation docs; Adobe signature validation; 15 U.S.C. 7001; eIDAS
Regulation (EU) 910/2014 consolidated; Laravel 13 filesystem and queue docs; Garage S3
compatibility; Symfony Mailer. Full URLs are in HANDOFF.md section 17.
