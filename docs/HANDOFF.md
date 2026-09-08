# BWH eSign — implementation handoff

**Status:** Proposed implementation specification, not an implemented or certified product.
What the built pipeline actually claims, and what it refuses to claim, is stated in
[`docs/assurance.md`](assurance.md); the gate-by-gate proof status is in
[`docs/security/release-gates.md`](security/release-gates.md) and the adversarial review of the
merged code is [`docs/security/review-2026-09.md`](security/review-2026-09.md).
**Prepared:** 2026-09-08 UTC.
**Working repository name:** `bherila/e-sign`.
**Application license:** MIT for original application code; dependencies retain their own licenses.
**First consumer:** a private Laravel application (referred to below as "the consumer") that currently integrates with Firma.

## 1. Mission and release boundary

Build an independently deployable Laravel application for preparing, sending, electronically signing, sealing, and retaining PDF agreements. It must replace the Firma workflows actually used by the consumer while providing a documented native API and a versioned Firma-compatible HTTP/webhook facade. It must also be useful without the consumer or any BWH-hosted service.

The first production deployment runs the signing app on its own host, with administrator SSO at that tenant's existing identity-provider host and the consumer on a third host. Hostnames are deployment settings recorded outside this repository, not claims that services or DNS have been provisioned. Keep branding, origins, issuer, callback URLs, organization names, and storage/mail configuration configurable for other installations.

Deliver a narrow but production-capable NDA release, not a superficial DocuSign clone. The initial supported workflows must cover platform NDAs, sequential buyer/seller practice NDAs, order forms, and data-destruction certifications. Complete these workflows before optional bulk sending, billing, complex conditional forms, notarization, or qualified-signature integrations.

The baseline application must perform PDF preparation and cryptographic sealing in PHP. Do not quietly require a Node, Python, Java, Chromium, Redis, or externally hosted PDF service in the cPanel runtime. Build-time assets and isolated CI validation tools may use other languages. A later optional signing backend can use a separate process, but cannot be the only way the advertised cPanel edition completes documents.

## 2. Research baseline and repo-specific constraints

Re-read current heads before implementation. The consumer was inspected at a pinned commit on 2026-09-08 (recorded privately alongside its fixtures). The auth package READMEs were inspected on their default branches. The observations below are scoped to those reads, not a full repository audit.

| Source | Observed integration detail | Consequence |
|---|---|---|
| Consumer Firma client service | Raw API key in the Authorization header; template draft/create, field lookup/patch, send; atomic document create-and-send; status/users/fields/download/cancel calls. | Capture real request and response contracts before implementing the facade. |
| Same file | Signing URLs are repeatedly constructed using `https://app.firma.dev/signing/{recipientId}`. | Changing the API base URL alone will not migrate the consumer. |
| Same file | Template field IDs are hardcoded; prefills are patched by `variable_name`; recipient field ownership uses recipient ID, not signing order. | Import template aliases or explicitly migrate mappings; do not silently drop overrides. |
| Same file | Comments record a live-tested percentage coordinate convention, while the reviewed Firma atomic-endpoint examples contain values above 100. | Record this disagreement and resolve with sanitized known-good fixtures. Never guess units from numeric magnitude. |
| Same file | Platform-NDA send failure warns and returns a link; the data-destruction variant throws. | Make the migration fail closed on failed send; do not persist or redirect to a dead draft link. |
| Consumer Firma webhook controller | Expects nested request identity, Firma headers, recipient events, and raw-body verification. Its event-ID resolver uses `event_id` or a payload hash, whereas public Firma docs use `id`. | Normalize both documented/current IDs explicitly in the consumer; test retries and reordering. |
| Consumer executed-document retention service | Retains executed copies through its file-storage service across four artifact models; records SHA-256 and matched/mismatched/unverifiable vendor-hash status. | Preserve these copies, evidence states, and the retention-gap dashboard through migration. |
| Consumer mail configuration | Standard Laravel SMTP transport is configured; no custom Brevo transport was established by this inspection. | Brevo SMTP is the simple compatible path; do not invent an existing Brevo adapter. |
| `bherila/auth-laravel/README.md` | Current runtime floor is PHP 8.4+ / Laravel 13; OAuth authorization-code client support with PKCE exists. | Use the shared implementation, publish required migrations, and test the runtime floor. |
| `bherila/auth-manager/README.md` | Owns identity and coarse application access grants, not application feature roles. | Keep fine-grained eSign authorization local; any central role-management extension is new work. |

Sources: [R1]–[R6]. The public Firma reference inspected is version 1.35.0. Its complete OpenAPI JSON was not successfully retrieved during this research; obtaining, reviewing, and hashing the official schema is a discovery deliverable, not something already accomplished. No live Firma round trip or cryptographic interoperability test was performed for this handoff.

## 3. Naming and licensing

Use `bherila/e-sign` and the working display label “BWH eSign.” Neither is represented as trademark-cleared. “Docsign” is already used by an e-signing product, so it is a poor choice even apart from similarity to DocuSign. Conduct an appropriate trademark/name clearance before committing to public branding. [S1, S2]

Implement the application independently. Do not fork DocuSeal, Documenso, or OpenSign and replace their licenses with MIT. The repositories reviewed use AGPL-family licensing; they are deployment alternatives rather than MIT Laravel foundations. [S3–S5]

The first PDF-engine candidate is `tecnickcom/tc-lib-pdf`, a pure-PHP, LGPL-3.0 library whose current documentation describes PDF import and PAdES support. This is a feasibility candidate, not an independently verified endorsement. Original application code can be MIT while an independently used LGPL dependency remains under its own terms; distribution must satisfy the applicable combined-work, notices, source, and replaceability requirements. Verify all transitive packages and generated assets. [S6, S7]

Ship an SBOM, third-party notices, locked dependencies, and the source/build materials needed to reproduce distributed images. Any modifications to LGPL library code retain the applicable library license. Do not embed AGPL application code in the MIT project. Do not assume third-party SDKs, documentation, templates, logos, or fonts inherit the app's MIT license. Use synthetic agreements and synthetic identities in public fixtures.

## 4. Architecture

Implement a modular monolith with one set of domain services used by the administrative UI, guest signing UI, native API, and compatibility facade. Do not create a second signing state machine inside the Firma controllers.

Suggested boundaries:

- **Identity and authorization:** identity bindings, workspace memberships, local roles, service credentials, recipient access.
- **Preparation:** PDF preflight, normalized review revisions, page geometry, text/anchor location, visual and JSON field definitions, template versioning.
- **Signing:** signing sessions, consent, submitted values, immutable attestations, ordering and transition guards.
- **Evidence:** audit events, document hashes, rendering/sealing, artifact publication, evidence export, retention.
- **Delivery:** mail outbox, webhook outbox, retries, attempt logs, operational health.
- **Integration:** native API, Firma facade, compatibility capabilities, import and migration adapters.

Suggested interfaces include `PdfPreflight`, `PdfTextLocator`, `PdfAssembler`, `PdfSealer`, `ArtifactValidator`, and `TimestampAuthority`. Keep cryptographic implementation in established libraries. Do not build a bespoke CMS/ASN.1/PDF-signature writer.

Use Laravel 13, PHP 8.4 as the minimum tested runtime, and PHP 8.5 for the Docker target unless package/platform discovery requires adjustment. Use MySQL with transactional tables, database-backed queues/cache/locks, and Laravel Storage. Test the exact supported database releases; do not advertise MariaDB support merely because a cPanel host offers it. Choose a React/TypeScript frontend with Vite-built assets and a locally served PDF.js renderer. Node is a build dependency, not a production requirement. [R5, S13, S14, S19]

The eSign app gets its own database/schema credentials, encryption key, session cookie, storage credentials, and API/webhook secrets. Sharing the physical MySQL or Garage server is acceptable; directly querying the consumer's tables is not. One application image can be run in web, worker, and scheduler roles; the existing reverse proxy can serve it without another public service port.

## 5. Identity, administrator provisioning, and app roles

### Hosted identity-provider SSO mode

Use auth-laravel's existing OAuth client mechanics against the configured auth-manager issuer. Use authorization code with PKCE, state protection, exact callback allowlists, and the package's validated identity response. Do not assume a full OIDC contract, claim shape, or role-claim feature that the deployed packages do not actually expose.

Bind local identities to a stable issuer/provider plus subject tuple. Email is contact data, not an automatic account-linking key. Apply database uniqueness to identity bindings. Never make the first person who logs in an administrator, and never equate a provider administrator or application-access grant with eSign owner authority.

Provide an idempotent CLI bootstrap/provisioning workflow. A proposed new eSign command should require an explicitly selected issuer and subject for the first owner. Report absent identity/client configuration rather than silently provisioning by email. Supply a tested operator runbook for registering the OAuth client in auth-manager, granting application access, provisioning the local owner, and completing an actual browser login.

Application registration should include the app label, homepage, exact redirect URI, and an SSO deep link to the app's role-management page. For launch, local workspace roles should be `owner`, `admin`, `sender`, and `auditor`, with explicit permission policies. A directory grant admits someone to the app; local roles determine which workspaces and agreements they can use. API credentials are separate service principals and are workspace/scope bound.

A centrally managed role interface may be added later through a reviewed, versioned application-manifest and entitlement contract. That is a separate auth-manager change, not an assumed existing plugin API. Do not teach the identity provider NDA business rules merely to ship this app.

Re-check disablement and entitlement according to the deployed auth contract, with a documented maximum session/revocation window. Follow the provider's deletion/tombstone reconciliation contract. Removing login access must not cascade-delete executed instruments or rewrite historical signer evidence; apply a separately reviewed legal-retention/privacy policy.

### Passkeys and cookies

Keep passkey registration and verification at the identity provider. A parent-domain RP ID is valid for the relevant subdomain relationship, but is not required just to achieve redirect-based SSO. Validate exact allowed origins; do not add a wildcard origin rule or distribute credential tables among apps. Existing credentials bound to a different RP ID require a deliberate migration/re-enrollment strategy. [S15]

Keep session cookies host-only with distinct names, Secure/HttpOnly, and appropriate SameSite settings. Do not share Laravel sessions, APP_KEY, or parent-domain session cookies between the consumer, eSign, and auth-manager. Configure staging clients, issuer/origin allowlists, keys, and relying-party identities separately from production.

### Standalone mode and recipients

Offer a local-admin mode using auth-laravel for standalone installations, with explicit CLI bootstrap and production-safe authentication defaults. An OSS installation must not require access to any BWH-operated identity endpoint.

External signers are recipients, not application administrators. Support scoped guest signing sessions and optional mailbox OTP; do not require an admin account to sign an NDA. Use separate policies for public recipient access and authenticated sender administration.

## 6. Domain model and invariants

A useful initial schema includes workspaces, users/identity bindings, memberships, API credentials, documents, document revisions, templates/template versions, envelopes, recipients, field definitions, submitted values, signing sessions, recipient attestations, artifacts, audit events, outbox events, webhook endpoints/deliveries, and idempotency records. Native IDs and imported-provider aliases are separate fields.

Each envelope initially carries one PDF; the schema may support document associations without promising multi-document envelope support in the first API release. A template version snapshots the PDF revision, recipients/roles, field definitions, consent policy, and relevant rendering settings. Sending copies the selected version into the envelope. Later template changes never mutate existing requests.

Suggested native lifecycle:

`draft -> sent/in_progress -> finalizing -> completed`

Pre-completion terminal outcomes include cancelled, declined, and expired. Retryable finalization failures remain visibly pending/failed finalization, not completed. Model recipient progress independently from envelope completion. Keep signer acceptance time, countersigner time, PDF sealing time, and delivery time distinct.

Enforce these invariants in shared domain services:

1. A recipient can complete only their own assigned fields and only while eligible under the configured order.
2. A signature acceptance binds to a specific immutable review revision, material field values, consent version, and recipient session. Stale submissions fail and require re-review.
3. All required recipient fields, identities, consent, and ordering are validated on the server. Client-side “complete” flags have no authority.
4. After the first acceptance, substantive agreement content cannot change. Later signers may add only the explicitly anticipated signer-specific fields. A material correction requires a new version and renewed signatures.
5. A completed request has a durable, validated final PDF and a complete evidence reference. It is not merely a set of signature images or a status row.
6. Cancellation/expiry/signing/finalization races have one legal state-machine outcome under a transaction/compare-and-swap guard.
7. API retries and worker redelivery produce one logical acceptance/completion. External email/webhook transport remains at-least-once, not magically exactly-once.

For parallel signing, collect/freeze all material values before signers can assent. Do not allow two people to accept materially different contract text because another person edited a shared field concurrently. Sequential signing is the first the consumer acceptance case; parallel support needs its own tests.

## 7. PDF preparation, visual editor, and native JSON

Retain the original upload exactly as received, with its digest. Produce and store the review revision before showing it for assent. Any normalization happens before review, is disclosed to the sender, and is traceable to the original. The PDF that the browser displays must be the revision used as the base of final assembly, with only the authorized captured fields and completion material added.

The candidate importer reconstitutes pages and does not preserve existing source signatures. Initial policy must reject already-signed PDFs, encrypted PDFs, and unsupported interactive structures instead of silently invalidating signatures or losing visible contract content. Supporting them later requires a separate, tested preservation/preflight strategy. [S8]

Perform actual PDF parsing, not just a MIME/extension check. Enforce upload, page, object, nesting, decompression, decoded-image, and processing limits. Treat actions, JavaScript, XFA, embedded files, remote resources, and annotations explicitly. Either reliably normalize an allowed feature before review or reject it with an actionable message; do not claim a generic “sanitization” pass removes all hazards. No OCR or remote PDF service is a baseline requirement.

The editor needs zoom, page navigation, multiple page sizes, drag/resize, numeric placement, keyboard controls, recipient coloring, required/read-only controls, prefill mapping, validation, mobile signing, and a tabular alternative to dragging. Initial fields should cover signature, initials, text/name/company/title, agreement date, captured signing date, and checkbox. Additional controls must be declared capabilities rather than silently ignored.

Use one versioned schema shared by the editor and native API. This is an illustrative **native design**, not Firma's schema:

```json
{
  "schema_version": "1.0",
  "document_id": "doc_uploaded_nda",
  "coordinate_space": {
    "unit": "pt",
    "origin": "top-left",
    "page_box": "crop",
    "rotation": "displayed",
    "page_index_base": 1
  },
  "recipients": [
    {"id": "buyer", "name": "Example Buyer", "email": "buyer@example.test"},
    {"id": "counterparty", "name": "Example Counterparty", "email": "counterparty@example.test"}
  ],
  "signing_order": [["buyer"], ["counterparty"]],
  "fields": [
    {
      "id": "buyer_signature",
      "recipient_id": "buyer",
      "type": "signature",
      "page": 1,
      "rect": {"x": 60, "y": 650, "width": 170, "height": 36},
      "required": true
    },
    {
      "id": "counterparty_signature",
      "recipient_id": "counterparty",
      "type": "signature",
      "page": 1,
      "rect": {"x": 330, "y": 650, "width": 170, "height": 36},
      "required": true
    }
  ]
}
```

Define the displayed CropBox transform precisely, including nonzero box offsets, rotation, and PDF UserUnit. Convert to backend units explicitly. The Firma facade converts its verified coordinate convention to this model. Never infer point-versus-percent coordinates heuristically.

Reject duplicate/unknown IDs, fields bound to nonexistent recipients, out-of-page fields, non-finite coordinates, illegal dimensions, unresolved prefill variables, unsupported types, and partial schema imports. Round-trip JSON to editor to JSON without drift. Preserve stable field IDs and template aliases.

Anchor placement must have deterministic text extraction, occurrence selection, offsets/units, and a stored resolved rectangle before send. Missing/ambiguous required anchors are errors. An explicit compatibility option for an optional absent anchor must be narrow and observable. Prove positioned-text extraction on the PHP baseline during discovery; do not implement a regex over compressed PDF bytes or fuzzy/LLM placement. When migrating documents generated by the consumer, layout-provided explicit rectangles may be an alternative, but any compatibility difference must be documented and tested.

## 8. Signer security and evidence

Use high-entropy expiring invitation/session credentials, scoped to one recipient and envelope. Store bearer-token verifiers rather than plaintext reusable tokens where possible; design rotation/reissuance explicitly. A public recipient UUID alone must not authorize signing. A legacy `/signing/{recipientId}` resolver can require mailbox verification; native links can carry opaque credentials. Do not sacrifice authorization to preserve a URL construction shortcut.

GET requests must not apply signatures, consume one-shot assent, or advance recipients. Mail-security scanners and previews must be harmless. Establish a recipient session through an explicit flow, then require protected POST actions for consent and signing. Scrub tokens from logs; use no-referrer policy and no third-party analytics/CDNs on signing pages. Validate callback/return destinations rather than reflecting arbitrary URLs.

Support typed and drawn signatures with explicit adoption/intent and an accessible alternative. Render signature submissions through controlled assets: decode and re-encode permitted images with dimensions/size limits; do not accept arbitrary SVG/HTML or remote image URLs. Never record a successful signature merely because a canvas is nonempty.

Capture the document/revision digest, relevant values, actor role, recipient identity snapshot, claimed organizational capacity, verification method/result, displayed consent version, server acceptance timestamp, and appropriately minimized network/client evidence. Do not treat IP or user agent as conclusive identity proof. Distinguish a trusted application's identity assertion, email control, and stronger authentication in the evidence model.

Provide an export containing the original, reviewed revision, executed PDF, machine-readable evidence, human-readable completion report, certificate chain, validation report, and explicit digests. Label the completion report separately from an X.509 certificate.

Use a versioned canonical evidence encoding and append-only sequence with previous-event digests. Compute the final PDF's hash outside that PDF; avoid a self-referential “final hash inside itself.” A separately signed export manifest may bind the final PDF and evidence files. Define exactly which object each digest covers.

A hash chain in an administrator-writable database is not an independent witness. Add separately administered, off-host evidence/checkpoint backups and signer copies; document the residual threat from a compromised app, signing key, or host administrator. Do not claim absolute non-repudiation or tamper-proof storage.

## 9. Cryptographic assurance and compliance policy

Use precise product language: people provide electronic signatures/assent; the service then cryptographically seals the executed PDF under its own disclosed certificate. That organizational/service seal must not be presented as a separate personal certificate owned and exclusively controlled by each human signer.

Target the applicable PDF/CMS/X.509 rules and **ETSI EN 319 142-1 V1.2.1 (2024-01)** for an initial PAdES B-B artifact. Support B-T through a configured RFC 3161 timestamp authority; plan LT/LTA as separately validated extensions with ongoing preservation operations, not booleans that imply compliance. [S9, S10]

Specify the assurance policy before the envelope is sent. Use modern allowed key/digest choices enforced by the backend and deployment policy. Reject absent, unusable, expired, or mismatched configured signing material before inviting signers. Fail closed on invalid timestamp responses, unexpected trust chains, missing required assurance material, or a generated artifact that does not reach the required profile. A backend option named after a profile is not evidence that every output meets it.

Self-issued certificates can support cryptographic integrity without automatically gaining a relying party's trust. Independently trusted time/certificate identity requires corresponding trust arrangements. A self-hosted timestamp service is not an independent witness merely because it speaks RFC 3161. Signer acceptance timestamps and proof that a final seal existed by a TSA time are different claims. [S10, S11]

At release, independently validate synthetic artifacts with local tools such as pyHanko for signature integrity/trust checks and European Commission DSS for profile-level checks, plus an explicit normative requirement matrix. pyHanko's documentation warns that its normal validation is not a complete structural PAdES-profile conformance checker. Do not upload confidential agreements to public demo validators. Browser/manual Acrobat checks supplement, but do not replace, automated checks. [S11, S12, S20]

For the first US NDA deployment, have counsel approve the intended signature/consent flow, agreement language, signer authority, retention and access policy, and any applicable consumer disclosure/access-demonstration requirements. ESIGN/UETA support is an end-to-end workflow and recordkeeping objective, not a library certificate. Do not market blanket legal enforceability. [S16, S17]

Do not claim eIDAS advanced or qualified human signatures solely from email OTP plus an organization seal. Qualified-signature support requires its own qualified-certificate/device/provider and identity process. Keep it an explicit future integration boundary, and make the current assurance class visible. [S18]

Keep signing private keys separate from APP_KEY, database data, public storage, source, and container layers. Mount protected keys/secrets with least privilege; version key IDs and preserve public chains for old documents. Include expiry monitoring, rotation, compromise response, and tested recovery. In Docker, prefer exposing key material only to the signing-worker role. Document that a single-account shared host cannot provide the same key isolation from compromised application PHP.

## 10. Firma-compatible HTTP surface

Implement a native `/api/v1` and a compatibility facade under `/functions/v1/signing-request-api`. Both invoke identical authorization/domain services. Pin the official reference version, retrieved schema digest, adapter version, and captured fixture version in the repository.

First acceptance profile: **`firma-compat-v1`**, covering every call and payload field used by the verified the consumer workflows. This is not permission to label the entire Firma API supported. Maintain a route/field/status/event capability matrix with supported, unsupported, and intentionally different entries. Expand templates, workspaces, webhook administration, and other requested families methodically toward broader compatibility. Never advertise a successful no-op for an unsupported route or required option.

| Relative endpoint | Initial contract to test |
|---|---|
| `POST /signing-requests` | Template or PDF draft creation, recipient mapping, stable IDs, overrides/prefills. |
| `POST /signing-requests/create-and-send` | Validate-before-send behavior, document base64, fields/anchors, settings, first-signer result. |
| `GET /signing-requests/{id}` | Endpoint-specific documented status representation; the consumer polling expects boolean status fields. |
| `PATCH /signing-requests/{id}` | The consumer's singular `field` patch shape, values, read-only/editable rules. |
| `POST /signing-requests/{id}/send` | Guard required values, recipient eligibility, immutable template/document snapshot. |
| `POST /signing-requests/{id}/cancel` | Valid transitions, reason, idempotency, terminal-state behavior. |
| `GET /signing-requests/{id}/users` | `results`, signing order, recipient IDs and `finished_on`; do not rely on array order. |
| `GET /signing-requests/{id}/fields` | `results`, final values, variable/template IDs, correct signature-value encoding. |
| `GET /signing-requests/{id}/download` | JSON `download_url`, authorized short-lived access, preserved exact PDF bytes. |
| Template/workspace/webhook/audit routes | Implement the exact official routes required by chosen profile; do not guess their shapes. |

The authorization header must accept the consumer's raw API key, not require Bearer-only syntax. Native service credentials may also support Bearer syntax. Enforce scope/tenant isolation before looking up resources; identifiers and foreign keys must not bypass scope.

Preserve endpoint-specific response shapes rather than forcing a single convenient serializer on every route. In particular, a create response and a polling response need not use the same status type. Preserve nullable fields, time formats, pagination, error behavior, prefilled-editability semantics, and signer-order identity binding as confirmed by fixtures. Add native idempotency keys and a clearly documented compatibility extension where the upstream contract lacks them.

Treat vendor documentation and recorded behavior disagreements as explicit issues. The adapter must not reproduce an unsafe vendor behavior just to earn a vague “drop-in” claim. Resolve the chosen safe behavior in the compatibility matrix and change the consumer where required.

Full compatibility does not include impersonating the Firma hosted website, DNS, JavaScript SDK/editor, billing platform, or private implementation. The app's own signing/editor UI remains local and visibly branded as this product. [R1, R2, S21, S22]

## 11. Webhook and email reliability

Use a transactional outbox. A domain transition and its event are committed atomically in MySQL. Delivery jobs reference immutable event payloads. Store delivery attempts separately from logical events, with stable event identity, per-attempt identity, response status, retry time, and redacted error detail.

Firma compatibility signing uses:

```text
signed_payload = ASCII(timestamp) + "." + exact_raw_json_body
signature = hex(HMAC-SHA256(webhook_secret, signed_payload))
X-Firma-Signature: t=<timestamp>,v1=<signature>
```

Implement the documented event/delivery headers and rotation overlap. Use a fresh attempt timestamp/signature while retaining the logical event identity. Make retry/disable behavior configurable and identify any difference from the selected compatibility profile. Give admins safe replay, queue-age monitoring, and a visible disabled-endpoint state. [S22]

Cover the relevant created/sent/recipient-signed/completed/cancelled/declined/expired events using the exact official event names and payload schema. A recipient-signed event does not imply full execution. A completed event cannot be dispatched before the validated final PDF is retrievable. If completion/evidence are separate upstream events, preserve their documented distinction rather than manufacturing a nonexistent event.

Do not trust arbitrary webhook destinations. Require HTTPS outside explicit local development, validate resolved destinations, disable unsafe redirects, protect against DNS rebinding/SSRF, and block metadata/internal targets by default. The intended internal the consumer callback may be permitted by an explicit administrator-controlled host/IP policy, not a caller-controlled private-network bypass. Apply similar restrictions to timestamp/revocation retrieval and any allowed remote import functionality.

The consumer needs a durable inbox with signature/replay-window validation, normalized event identity, unique provider-scoped IDs, raw evidence retention, idempotent processing, and protection against out-of-order regression. A duplicate insert race must not turn an unprocessed event into a permanently acknowledged loss.

Use Laravel Mailables and queued delivery with Brevo SMTP as the initial production configuration. An optional Symfony Brevo API transport can be considered without placing Brevo SDK calls in domain services. Distinguish email-queued, provider-accepted, bounced, and signed states. Signing success does not depend on falsely declaring email delivered. Do not fall back to a log mailer and count that as production delivery. Build invitation, reminder, decline/cancel, completion, and administrative-failure templates; verify mail-domain setup and link behavior. [R4, S23]

## 12. Artifact publication, Garage, and retention

Use private Laravel Storage disks for every document class. Serve authorized expiring URLs through the app when a backend cannot safely generate its own temporary links. Stream downloads with fixed content types and safe filenames. Never expose signed artifacts through a public storage symlink. [S14]

Garage's documented S3 implementation lacks Object Lock and object versioning. Therefore do not promise WORM or bucket-enforced legal hold. Implement unique non-overwritten artifact keys, application-level deletion restrictions, legal-hold policy, separate credentials, backup and reconciliation. Use independently administered off-host copies for stronger deletion/tampering resilience; same-host duplication alone is not independence. [S24]

Publish artifacts with a staged process, not an imaginary cross-database/S3 transaction:

1. Under an envelope lock, capture the immutable finalization input and a generation identifier; move to finalizing.
2. Outside the lock, render/seal into private staging, validate, hash, and upload to a new final artifact key. Confirm readable bytes/digest using the storage adapter.
3. In a new database transaction, re-check the generation/revision/state; publish the artifact reference, completion evidence, and completion outbox event together.
4. On retry, reuse the selected completed artifact. Clean up only provably unreferenced staging objects under a conservative policy; never garbage-collect completed evidence by prefix age.

Object ETags are not the application's cryptographic document hash. Define SHA-256 fields explicitly. For imported Firma documents, retain the original vendor hash and its known/unknown algorithm status separately from the app's own digest. Preserve mismatches and missing evidence; do not relabel them verified during import.

Separate authentication-log cleanup, abandoned draft cleanup, and executed-evidence retention. Default to no automatic deletion of executed documents until an operator has configured a reviewed policy. Legal holds override deletion. Privacy/deletion workflows must be explicit and audited.

## 13. Deployment profiles and operations

### Docker (first production deployment)

Produce a reproducible application image and Compose examples. Reuse the host reverse proxy, MySQL, and Garage when configured, while preserving distinct service credentials and databases/buckets. Separate staging and production data, keys, callbacks, API credentials, and mail routing. Run as non-root, avoid Docker-socket mounts, restrict writable paths and network access, and keep keys out of image layers.

Run a managed queue worker in Docker. The same image can provide worker/scheduler roles; a single process should not mix web-request latency with sealing work. Include health/readiness for database, storage, queue lag, scheduler heartbeat, mail configuration, webhook backlog, signing-key usability, certificate expiration, and required TSA reachability. Never expose secrets or sensitive documents in health output.

### cPanel / shared hosting

Supply a release bundle with prebuilt frontend assets and a tested PHP+MySQL installation path. Confirm the actual PHP CLI executable and web runtime both satisfy the supported version/extensions. Use `public/` as document root and keep keys, `.env`, and private storage outside it. Support local disk storage and SMTP as well as configured S3-compatible storage.

Use cron-driven bounded queue work, with a database-backed lease/overlap guard and idempotent jobs. Do not require a persistent daemon or Redis. Document that queue delays depend on cron cadence and that worker max-time is not necessarily a hard per-job interrupt. Validate per-document processing and memory limits on the cPanel profile, including a host without pcntl/process-spawning capabilities when claiming that support. Large unsupported PDFs should fail preflight before invitations, not hang signing after assent. [S13]

Provide installation diagnostics and an end-to-end smoke test for both profiles. A deployment is not “working” merely because its home page loads. It must authenticate the provisioned admin, upload/prepare, invite, sign, seal, download/validate, deliver a verified webhook, and recover after worker interruption.

Back up database, artifact storage, encryption secrets, and signing material according to distinct handling policies. Test restore into an isolated environment, with outbound mail/webhooks suppressed, and verify restored documents against their recorded digests and signatures.

## 14. Required test and release gates

| Gate | Required proof |
|---|---|
| Crypto and trust | Positive artifacts validate with known configured trust; modified content, forged CMS, wrong keys, truncated PDFs, and unexpected revisions fail. An embedded certificate alone is not automatically trusted. |
| PAdES profile | Local DSS/profile checks and explicit requirement fixtures establish the claimed output level. A generic “signature valid” result alone is insufficient. |
| PDF fidelity | Synthetic and authorized sample PDFs cover rotation, CropBox offsets, mixed sizes, Unicode, forms/annotations, xref streams, scanned pages, and unsupported encrypted/already-signed inputs. Preview and final geometry agree. |
| JSON/editor | Round trip without coordinate drift; numeric/keyboard editing; recipient ownership; unsupported/missing/ambiguous values fail clearly. |
| State machine | Wrong-order, duplicate, stale, cancelled/expired, concurrent signer, and finalize/cancel races preserve invariants. Required fields and consent cannot be bypassed. |
| Artifact durability | Crash before upload, after upload, and around the final DB commit never emits an invalid completion or loses the selected final bytes. |
| Webhooks | Raw-body signatures, clock window, secret rotation, stable event IDs, retries, duplicate delivery, timeout, lost response, receiver failure, and reordering are tested. |
| Isolation | Cross-workspace access through IDs, aliases, JSON, downloads, queues, service keys, editor routes, and webhook replay is denied. |
| SSO | Real browser callback flow; PKCE/state failures; issuer/subject binding; bootstrap; disabled grants; local roles; no shared parent-domain sessions. |
| Guest signing | Mail-preview GETs harmless; token expiry/rotation; OTP rate limits; no unauthorized use of public recipient IDs; CSRF and return-URL protections. |
| Runtime portability | Same supported functional flow on PHP baseline with MySQL+cron and without a remote signing service; Docker worker variant also tested. |
| Independence | Block Firma/DocuSign hosts: new workflows still complete. Use local SMTP/storage configuration to prove Brevo/Garage are swappable. No CDN/analytics is required. |
| Migration | Existing Firma requests remain on Firma; imported executed PDFs are byte-preserved; new self-hosted requests reconcile and retain all four artifact types. |
| Recovery and maintenance | Restore verifies old artifacts; key rotation preserves historical verification; pending finalization and webhook failures are visible and replayable. |
| Accessibility and legal readiness | Keyboard/mobile signer flow, accessible signature alternative, approved consent/copy/retention process, and honest assurance documentation. |

## 15. Consumer migration plan

First capture a sanitized provider contract suite around the consumer's Firma client service and webhook controller. Add a provider-neutral interface or facade while leaving the existing Firma path working. Persist provider and remote-request identity per agreement, not just in one global environment variable. Historical IDs and template/field aliases remain distinguishable.

Replace hardcoded signing-host construction with a provider-aware URL resolver. Prefer an explicitly authorized URL/access-session returned by the provider; never let an arbitrary upstream redirect become an open redirect or credential leak. Handle the compatibility resolver/OTP flow deliberately where necessary.

Import the active legal template versions and their exact field mappings through an audited tool. Keep per-version template snapshots. Preserve every original executed Firma PDF byte-for-byte with its evidence and hash-verification state. Do not re-render, re-seal, or claim to re-create the original execution.

Run old pending requests to completion on Firma with their old webhook credentials. Use the self-hosted provider only for new requests behind an environment/workflow feature flag. Register and test the new callback independently. Scope webhook uniqueness by provider/issuer where needed.

The pilot must exercise platform NDA, practice NDA, order form, and data-destruction flows, including recipient-signature timing, countersigning, polling reconciliation, cancellation, download, local retention, and admin audit views. Generalize retention interfaces without weakening the existing matched/mismatched/unverifiable states. Local SHA-256 agreement is not proof of an imported document's original signer identity.

Keep rollback scoped to creation of future requests. Never route a self-hosted in-flight request to Firma or vice versa merely by toggling a global base URL. After migration, periodically reconcile completion/retention gaps using the provider on each record. Archive the remaining Firma evidence before retiring its credentials, subject to authorization and retention policy.

## 16. Recommended PR sequence and parallel agent boundaries

**PR 0 — contract and cryptographic feasibility.** Read current repos; obtain the official schema; build the compatibility matrix and sanitized fixtures; prove PHP PDF fidelity, anchor capability, valid baseline sealing, independent verification, and the license inventory. Record unresolved gaps. This is a release-path gate before major editor implementation, not a spike whose failures get hidden.

**PR 1 — app skeleton and identity.** MIT project, locked runtime, CI, workspace policies, local/SSO modes, migrations, explicit owner bootstrap, and a working admin login. No default administrator or production test bypass.

**PR 2 — preparation and editor.** Safe uploads, review revisions, template versions, native schema, PDF viewer, fields, deterministic transforms/anchors, JSON import/export, and accessible editing. No fake “signed” outputs.

**PR 3 — signing and evidence.** Guest flow, consent, ordering, immutable attestations, finalization state machine, private artifact publication, service seal, evidence bundle, and failure recovery. Finish offline baseline before optional higher profiles.

**PR 4 — compatibility and delivery.** Native/Firma adapters, endpoint-specific serializers, service credentials, transactional mail/webhook outboxes, rotation/retry/replay, and end-to-end contract tests against a synthetic consumer.

**PR 5 — deployment hardening.** Docker and cPanel release/install/smoke-test paths, key management, backups/restore, operational diagnostics, dependency notices, and documented assurance limits. Can partly run in parallel once PR 1 establishes contracts.

**Consumer PR A — provider boundary, no cutover.** Preserve existing Firma behavior except independently tested correctness/security fixes; remove hardcoded URL assumptions; add provider identity and fixtures; maintain existing executed-document retention.

**Consumer PR B — staged self-hosted integration.** Template import/mapping, callback inbox improvements, provider-specific reconciliation, all four workflow tests, staging pilot, then separately authorized production activation.

**Auth-manager work — minimal registration first.** Register/test the application in the intended deployment and document grants/role-management deep links. Any central role-manifest or provisioning API extension needs its own reviewed design and tests. Do not block the product on an unnecessary provider-wide roles refactor.

Each PR should report code/tests completed, precise capabilities, remaining gaps, operational steps, and which claims are verified versus proposed. Do not merge through a failed crypto, retention, isolation, or migration gate. The first production rollout also needs independent security review and approval of the legal workflow.

## 17. Source inventory

Repository sources (inspected through the GitHub connection; re-read before coding):

- **R1:** the consumer's Firma client service (private repository; pinned commit recorded with the fixtures).
- **R2:** Same repo/commit, its Firma webhook controller.
- **R3:** Same repo/commit, its executed-document retention service and the related retention migration.
- **R4:** Same repo/commit, its mail configuration.
- **R5:** `bherila/auth-laravel`, default-branch `README.md`, inspected 2026-09-08 UTC; follow its current OAuth client and installation documentation.
- **R6:** `bherila/auth-manager`, default-branch `README.md`, inspected 2026-09-08 UTC; also follow referenced deployment-profile and identity-deletion contracts before implementation.

Public primary references (reviewed 2026-09-08 UTC; URLs identify sources, not hardcoded app dependencies):

| Ref | Source |
|---|---|
| S1 | Existing product: `https://docsign.com/` |
| S2 | USPTO similarity-search guidance: `https://www.uspto.gov/trademarks/basics/why-search-similar-trademarks` |
| S3 | DocuSeal repository/license: `https://github.com/docusealco/docuseal` |
| S4 | Documenso repository/license: `https://github.com/documenso/documenso` |
| S5 | OpenSign repository/license: `https://github.com/OpenSignLabs/OpenSign` |
| S6 | tc-lib-pdf repository/license: `https://github.com/tecnickcom/tc-lib-pdf` |
| S7 | LGPL-3.0 text: `https://opensource.org/license/lgpl-3-0` |
| S8 | TCPDF import documentation: `https://tcpdf.org/docs/pdf-import/` |
| S9 | ETSI EN 319 142-1 V1.2.1: `https://www.etsi.org/deliver/etsi_en/319100_319199/31914201/01.02.01_60/en_31914201v010201p.pdf` |
| S10 | RFC 3161: `https://www.rfc-editor.org/info/rfc3161/` |
| S11 | Adobe signature validation: `https://helpx.adobe.com/acrobat/desktop/e-sign-documents/manage-digital-signatures/validate-digital-sign.html` |
| S12 | pyHanko validation capabilities/limitations: `https://docs.pyhanko.eu/en/latest/cli-guide/validation.html` |
| S13 | Laravel queues: `https://laravel.com/framework/docs/13.x/queues` |
| S14 | Laravel filesystem: `https://laravel.com/framework/docs/13.x/filesystem` |
| S15 | WebAuthn RP/origin rules: `https://www.w3.org/TR/webauthn-3/` |
| S16 | 15 USC 7001: `https://uscode.house.gov/view.xhtml?edition=prelim&num=0&req=granuleid%3AUSC-prelim-title15-section7001` |
| S17 | California UETA example, Civil Code 1633.7: `https://leginfo.legislature.ca.gov/faces/codes_displaySection.xhtml?lawCode=CIV&sectionNum=1633.7.` |
| S18 | Consolidated eIDAS regulation: `https://eur-lex.europa.eu/eli/reg/2014/910/2024-10-18/eng` |
| S19 | PDF.js: `https://mozilla.github.io/pdf.js/` |
| S20 | European Commission DSS documentation: `https://ec.europa.eu/digital-building-blocks/DSS/webapp-demo/doc/dss-documentation.html` |
| S21 | Firma v1.35.0 atomic endpoint: `https://docs.firma.dev/api-reference/v01.35.00/signing-requests/create-and-send-signing-request-atomic` |
| S22 | Firma webhook contract: `https://docs.firma.dev/guides/webhooks` |
| S23 | Symfony Mailer/Brevo: `https://symfony.com/doc/current/mailer.html` |
| S24 | Garage compatibility: `https://garagehq.deuxfleurs.fr/documentation/reference-manual/s3-compatibility/` |
| S25 | TCPDF signature documentation: `https://tcpdf.org/docs/digital-signatures/` |
| S26 | Firma documentation index: `https://docs.firma.dev/llms.txt` |

The specification's architecture, proposed command names, native JSON format, internal lifecycle, and PR breakdown are recommendations. They are not assertions that upstream projects already implement those contracts.
