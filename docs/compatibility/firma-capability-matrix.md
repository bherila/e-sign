# Firma capability matrix — profile `firma-compat-v1`

Scope of this document: every route, field, status and event that the first consumer's
workflows touch (`docs/HANDOFF.md` §10 and §11). It is **not** a claim that the Firma API as
a whole is supported. Anything not listed here is out of profile and must fail closed rather
than return a successful no-op (`AGENTS.md`, "Fail closed").

## Provenance

| | |
|---|---|
| Upstream reference | Firma Partner API **v1.35.0** (`info.version` = `01.35.00`), OpenAPI 3.0.3 |
| Pinned document | `https://docs.firma.dev/api-reference/v01.35.00/openapi-v01.35.00.json`, SHA-256 `91c7128a35ae52ac3937715041e41c1bee119cfb69b3dcf6fdc8135a4e3c98b9`, retrieved 2026-09-08T06:52:14Z |
| Pin record | `tests/Fixtures/firma/SCHEMA.md` (also explains why the document is not vendored) |
| Prose sources | `https://docs.firma.dev/guides/webhooks` (unversioned, retrieved 2026-09-08) for the webhook envelope, event names and signature scheme — none of which are in the machine-readable document |
| Facade base path | `/functions/v1/signing-request-api` (upstream `servers[0]` is `https://api.firma.dev/functions/v1/signing-request-api`) |
| Adapter version | none — no facade code exists yet |
| Fixture version | none — no captured fixtures exist yet; the `Fixture` column is blank throughout by design |

**Every "upstream shape" cell below is read off the pinned document unless it is tagged
`(prose)`.** Nothing here is inferred from a numeric magnitude or from consumer behaviour
(`AGENTS.md`, "Coordinates are never guessed").

## Status vocabulary

| Status | Meaning here |
|---|---|
| **supported** | Implemented, and a fixture asserts the contract. Nothing qualifies yet. |
| **unsupported** | Deliberately out of profile. Must return a clear error, never a no-op success. |
| **intentionally different** | We already know the shipped behaviour will differ from upstream, and why. |
| **unknown** | Shape captured, decision not yet made or not yet implemented. The Stage 0 default. |

Stage 0 delivers the contract, not the adapter, so most rows are **unknown**. That is the
accurate reading, not a placeholder.

## Authentication and transport

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| `Authorization` header | `securitySchemes.ApiKeyAuth`: `apiKey` in header named `Authorization`. Description: "Use your API key directly without any prefix… Bearer prefix is optional but not required." | unknown | | Matches `docs/HANDOFF.md` §10: the consumer's raw key must work. Bearer must be accepted, not required. Scope/tenant isolation is enforced before resource lookup. |
| Rate-limit headers | `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` on 2xx; `Retry-After` on 429/503. Documented tiers: GET 200/min, write 100/min, webhooks 60/min, send 60/min, JWT 120/min. | unknown | | Emitting the headers is cheap; matching upstream's exact tier values is not a profile requirement. |
| Error envelope | `Error` = `{error (required, human-readable), message, details}` | unknown | | `docs/HANDOFF.md` §10 requires preserving error behaviour. Note that `CreateAndSendValidationError` and `ResendConflictError` use `code` instead of `message` — the envelope is not uniform (see Disagreements D1). |
| Base URL / signing links | Consumer hardcodes `https://app.firma.dev/signing/{recipientId}`; upstream returns `CreateAndSendResponse.first_signer.signing_link` as an opaque `uri` | **intentionally different** | | We do not impersonate the Firma hosted site (`docs/HANDOFF.md` §10). Our signing UI is locally branded and served from this application. The consumer must stop constructing the URL and start reading `signing_link`. |

## Routes

### `POST /signing-requests`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Request body | `$ref` → `requestBodies/PatchSigningRequestBody` → `PatchSigningRequestBodySchema`, a `oneOf`: (a) requires `document` (base64 PDF/DOCX), (b) requires `template_id`. Shared: `name` (≤255), `description`, `expiration_hours` (min 1, default 168), `recipients[]`, `fields[]`, `anchor_tags[]` (max 100, document-based only), `reminders[]`, `settings`, `language`, `completion_title/message/redirect_url/redirect_delay` | unknown | | The schema name says "Patch" but it is the **create** body; `PatchSigningRequestBodySchema` is referenced exactly once, from this route. See D2. |
| `201` response | `SigningRequestCreateResponse` — required `id`, `name`, `status`; `status` enum is **`["draft"]` only**; flat `fields[]` (`SigningRequestCreateField`), `recipients[]`, `document_url`, `page_count`, `expiration_hours`, `template_id`, `settings`, `created_date`/`updated_date`/`sent_date`/`finished_date`/`cancelled_date`, `warnings[]` | unknown | | Create returns a **string** status; polling returns a **status object**. Do not unify them (`docs/HANDOFF.md` §10). |
| Recipient mapping | `Recipient` requires `first_name`, `email`, `designation`. `designation` enum `Signer|Approver|CC`. `order` documented as required for all recipients (but absent from the schema `required` list — D3). Temporary IDs `temp_1`, `temp_2`… resolve to real UUIDs in the response. `name` is auto-constructed and overwrites any supplied value. | unknown | | CC recipients cannot hold fields; at least one `Signer` is required. |
| Template overrides / prefills | Template-based: match template fields by `template_field_id` (preferred) or `variable_name` (fallback); match template users by `template_user_id` or `order`. Only user info is overridable — `order` and `designation` always inherit from the template. Unmatched fields are silently ignored. | **intentionally different** | | Silent ignore is exactly the vendor behaviour we must not reproduce (`docs/HANDOFF.md` §2, §10: "do not silently drop overrides"). An unmatched override is an error in our facade. |
| `400` / `404` | `400` invalid input (must supply `document` xor `template_id`); `404` template not found or not in workspace | unknown | | `404` must be produced after scope enforcement, never by leaking cross-tenant existence. |
| Stable IDs | All `id` fields are `format: uuid` | unknown | | |

### `POST /signing-requests/create-and-send`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Request body | Inline object, requires `name`. `document` (base64, ~4.5 MB inline cap) **xor** `template_id` **xor** `document_id` (from `POST /documents`, up to 50 MB). Plus `recipients[]`, `fields[]`, `anchor_tags[]`, `reminders[]`, `settings`, `expiration_hours` | unknown | | |
| `201` response | `CreateAndSendResponse` — `status` enum is **`["sent"]` only**; `first_signer` = `{id, name, email, signing_link}`; `recipients[]` with real UUIDs; `fields[]` (`Field`); `credits_remaining` | unknown | | `credits_remaining` is a billing concept we do not have. Decide: omit, or expose a documented compatibility constant. Not decided. |
| Validate-before-send | `400` → `CreateAndSendValidationError` with `phase` enum `create_validation|send_validation` and `validation_errors[]` = `{recipient_index (1-based), recipient_email, missing_fields[]}` | unknown | | Two-phase validation is worth reproducing verbatim; it is how the consumer surfaces missing prefills. |
| Atomicity | `500` description: "Any partially-created signing request is rolled back, so no draft remains." | unknown | | Matches our fail-closed rule. Consumer's platform-NDA path currently warns and returns a link on send failure; migration must fail closed instead (`docs/HANDOFF.md` §2). |
| `402` / `422` | `402` `InsufficientCreditsError`; `422` `UnprocessableEntityError` for suppressed (bounced/spam-marked) recipient addresses | unknown | | `402` has no analogue here. `422` maps onto our own suppression list once one exists. |
| Field coordinates | `Field.position` = `{x, y, width, height}`, all required, `number`, `minimum: 0`, `maximum: 100`, described as "percentage (0-100)", with `x + width <= 100` and `y + height <= 100`. `page_number` 1-indexed and required. | **intentionally different** | | Our native space is `pt`, top-left, CropBox, displayed rotation. The facade converts; conversion is fixture-backed and never inferred (`AGENTS.md`). The upstream *examples* contradict the upstream *schema* — see D4. |

### `GET /signing-requests/{id}`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| `200` response | `SigningRequestDetail` — required `id`, `name`, `status`, `companies_workspaces_id` | unknown | | Endpoint-specific nested shape. Must not be collapsed into the create shape. |
| `status` | **Object** of booleans: `sent`, `finished`, `cancelled`, `declined`, `expired`. "multiple can be true for terminal states" | unknown | | The consumer polls boolean status fields (`docs/HANDOFF.md` §10). Overlapping true flags must be reproducible, so our state machine needs a documented flag-projection, not a single enum cast. |
| `timestamps` | Object: `created_on`, `sent_on`, `finished_on`, `cancelled_on`, `declined_on`, `last_changed_on`, `last_signing_action_on` (all `date-time`, most nullable) | unknown | | Note the `_on` suffix here versus `_date` in the create response. Both must be preserved. |
| `expires_at` | `date-time`, nullable, computed from `sent_on + expiration_hours`; null before send | unknown | | |
| `certificate` | Nullable object `{generated, generated_on, has_error}` | unknown | | Maps onto our seal/evidence artifact, not onto a per-signer certificate. Language rules in `AGENTS.md` apply to any user-facing text. |
| Download URLs | `final_document_download_url`, `document_only_download_url`, `certificate_only_download_url` — all nullable pre-signed URIs "expire after 1 hour"; each has a paired `*_download_error` enum `file_not_accessible|null` | **intentionally different** | | We never pre-sign; downloads stream through the app on a private disk (`docs/BLOB_STORAGE.md`, `docs/HANDOFF.md` §12). The facade returns an app-issued, authorized, short-lived URL in the same field. |
| Deprecated 0/1 integers | `use_signing_order`, `allow_download`, `allow_editing_before_sending`, `hand_drawn_only`, `attach_pdf_on_finish` — `integer` enum `[0, 1]`, all marked `deprecated` | unknown | | Same concepts appear as **booleans** in `SigningRequestSettings`. Emitting both, with the right type in each place, is required for consumer compatibility. See D5. |
| `credit_cost` | `integer`, "Credits consumed when sent" | unsupported | | No credit system. Must not be fabricated; omit and document. |

### `PATCH /signing-requests/{id}`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Request body | `oneOf` of exactly three mutually exclusive forms: (a) properties only, (b) `{recipient: Recipient}`, (c) `{field: {...}}` — **singular**. "Cannot update multiple entity types in one request." | unknown | | Matches the consumer's singular `field` patch shape (`docs/HANDOFF.md` §10). |
| `field.position` | `{x, y, width, height}`, each a bare `number` with **no** min/max and described only as "X position on document" / "Field width" | unknown | | The percent range that `Field.position` states is absent here. We enforce the documented 0–100 percent contract on both. See D4. |
| Field prefill by `variable_name` | `field.variable_name` "Variable name for prefilled data mapping"; `final_value` "Final value of the field (for pre-filled read-only fields)"; `read_only` / `read_only_value` | unknown | | The consumer patches prefills by `variable_name` while its template field IDs are hardcoded (`docs/HANDOFF.md` §2). Both addressing modes must work, and an unresolvable `variable_name` must error. |
| Read-only / editable rules | `url` fields are "automatically read-only". `read_only=true` + `read_only_value` for static prefill. | unknown | | `docs/HANDOFF.md` §10: prefilled-editability semantics must be preserved as confirmed by fixtures. |
| Lifecycle guard | "Cannot update after signing request has been sent, completed, or cancelled" → `400` | unknown | | Fail closed; our state machine already forbids this. |
| `200` response | `oneOf` of three shapes keyed to what was updated: properties → `{id, name, template_description, document_url, expiration_hours}`; recipient → full recipient object (schema body **empty**); field → full field object (schema body **empty**) | unknown | | Two of the three response variants are untyped in the document. Fixtures are the only way to pin them. See D6. |
| `warning` | "Response may include a `warning` field" | unknown | | Singular here; `warnings` (plural array) on create. See D7. |

### `POST /signing-requests/{id}/send`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Request body | none | unknown | | |
| `200` response | Schema `SendSigningRequestResponse` = `{success, message, sentTo, sentAt}` — **camelCase, no required list**. The `example` in the same operation is `{message, signing_request_id, recipients_notified, sent_date, expires_at}` — **snake_case, disjoint keys** | **intentionally different** | | The document contradicts itself; we cannot satisfy both. See D8. Resolution deferred to a captured fixture; until then the facade must pick one and say which. |
| `400` | `Error`, examples: "Signing request has already been sent", "Signing request has expired" | unknown | | |
| Guard required values | Not expressed in the schema. `SigningRequestUser` carries `required_fields[]`, `missing_fields[]`, `required_read_only_fields[]`, `ready_to_send` | unknown | | Send must refuse when any recipient is not `ready_to_send`. |
| Snapshot immutability | Not expressed in the schema; implied by the PATCH lifecycle guard | unknown | | `docs/HANDOFF.md` §10 requires an immutable template/document snapshot at send. We enforce it regardless of upstream. |

### `POST /signing-requests/{id}/cancel`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Request body | Optional. `{reason (≤500), notify_signers (bool, default true)}` | unknown | | |
| `200` response | `CancelSigningRequestResponse` — required `message`, `signing_request_id`, `cancelled_on`; optional `notify_signers`, `emails_sent` | unknown | | |
| Valid transitions | "Can only cancel requests that have been sent and are not already finished or cancelled" → `409` on violation | unknown | | Note: cancelling a **draft** is a `409`, not a success. |
| Terminal-state behaviour | `409` when already cancelled/finished/not sent | unknown | | |
| Idempotency | Not expressed anywhere in the document | **intentionally different** | | Repeating a cancel yields `409` upstream. We add a native idempotency key as a documented compatibility extension (`docs/HANDOFF.md` §10) while keeping the `409` for the bare compatibility call. |

### `GET /signing-requests/{id}/users`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Envelope | `SigningRequestUserListResponse` = `{results: SigningRequestUser[]}`, `results` required. No pagination object. | unknown | | Array order is not specified. The consumer must key on `id`, never on position (`docs/HANDOFF.md` §10). |
| Identity | `id` (uuid), `email`, `first_name`, `last_name`, `name` (combined), `designation` enum `Signer|Approver|CC`, `order` (integer, min 1) | unknown | | Signer-order identity binding is by recipient ID, not by order and not by email (`AGENTS.md`). |
| Completion | `finished_on` (nullable `date-time`), `declined_on` (nullable), `decline_reason` (nullable) | unknown | | |
| Readiness | `required_fields[]` ("always includes `email` and `first_name`"), `missing_fields[]`, `required_read_only_fields[]` = `{variable_name, variable_defined_name, field_type, has_value}`, `ready_to_send` (bool) | unknown | | Drives the send guard above. |
| Contact/profile | `phone_number`, `street_address`, `city`, `state_province`, `postal_code`, `country`, `title`, `company`, `custom_fields` — all nullable | unknown | | Synthetic values only in fixtures. |
| Required set | Document declares required: `id`, `first_name`, `email`, `designation`, `order` | unknown | | `name` is *not* required despite being auto-constructed. |

### `GET /signing-requests/{id}/fields`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| Envelope | `SigningRequestFieldListResponse` = `{results: SigningRequestField[]}` | unknown | | |
| Final values | `value` (nullable string, "Clean alias for `final_value`") and the deprecated `final_value` | unknown | | Both must be emitted. Signature-value encoding is not specified by the document at all and needs a fixture. |
| Position | `position` = `{x, y, width, height}`, "All values are percentages (0-100)" | unknown | | Deprecated flat aliases `x_postion` **(sic, typo in upstream)**, `y_position`, `width`, `heigh` **(sic)** must be emitted byte-identically to upstream, typos included. See D9. |
| Corner positions | `tl_position`, `tr_position`, `bl_position`, `br_position` — bare nullable numbers, no units, no description beyond "Top-left corner position" | unknown | | Unspecified units. Do not guess. Needs a fixture or an explicit unsupported ruling. |
| Type | `type` enum (15 values incl. `radio_buttons`, `url`, `file`) plus deprecated `field_type` with the same enum | unknown | | Five different field-type enums exist across the document. See D10. |
| Recipient link | `recipient_id` plus deprecated `companies_workspaces_signing_requests_users_id`; also deprecated `companies_workspaces_signing_requests_id` | unknown | | Field ownership is by recipient ID, not signing order (`docs/HANDOFF.md` §2). |
| Variable identity | `variable_name`, `variable_defined_name` | unknown | | The consumer's template field IDs are hardcoded; migration must map or explicitly fail (`docs/HANDOFF.md` §2). |
| Grouping / rules | `multi_group_id` (uuid), `format_rules`, `validation_rules`, `dropdown_options` (`array<string>` **or** `object`), `date_default`, `date_signing_default`, `page_number` | unknown | | `dropdown_options` being either an array or an object is a genuine polymorphism we have to accept on input. |

### `GET /signing-requests/{id}/download`

| Surface | Upstream shape | Status | Fixture | Notes |
|---|---|---|---|---|
| `200` response | `SigningRequestDownloadResponse` — required `status`, `is_partial`, `download_url`, `expires_at`; optional `generated_at` | unknown | | JSON `download_url`, as `docs/HANDOFF.md` §10 expects. |
| `status` | **String** enum `finished|in_progress|cancelled|declined|expired` | unknown | | A *third* status representation, distinct from the create string enum and the GET boolean object. All three coexist by design. |
| `download_url` | Pre-signed URI that expires at `expires_at` | **intentionally different** | | App-streamed authorized URL instead of a storage pre-sign (`docs/BLOB_STORAGE.md`). Field name and expiry semantics are preserved. |
| Byte preservation | Not stated by the document | unknown | | Our own invariant is stronger: originals and imported executed PDFs are retained byte-for-byte and never re-rendered (`AGENTS.md`). |
| `is_partial` | Boolean; partial downloads gated on `allow_partial_download` in settings — a setting that **does not exist** in `SigningRequestSettings`. See D11. | unknown | | Open decision: serve a partial snapshot, or fail closed until execution completes. Not decided in Stage 0. |
| `409` | `{error: "no_document_available", message: "Signing request has not been sent yet"}` | unknown | | |
| `503` | `{error: "generation_timeout"}` or `{error: "stale_at_publication"}` with `Retry-After` seconds | unknown | | Good precedent for our own rule that completion is only published once the final PDF is retrievable. |

## Webhook events

The pinned OpenAPI document contains **no** `webhooks` object, no event payload schema, and
types `Webhook.events` as an unconstrained `array<string>` with no enum. Everything in this
section is `(prose)` from `https://docs.firma.dev/guides/webhooks`.

| Event | Upstream shape (prose) | Status | Fixture | Notes |
|---|---|---|---|---|
| `signing_request.created` | Envelope below, `data.signing_request` | unknown | | |
| `signing_request.sent` | idem | unknown | | |
| `signing_request.viewed` | idem | unknown | | A view is not assent. GET must stay harmless (`AGENTS.md`). |
| `signing_request.updated` | idem | unknown | | |
| `signing_request.deleted` | "deleted (before sending)" | unknown | | |
| `signing_request.completed` | "All recipients finished signing" | **intentionally different** | | Upstream ties this to signing completion. We publish it only after the final PDF is generated, validated, durably stored and retrievable (`AGENTS.md`, `docs/HANDOFF.md` §11). Later than upstream, deliberately. |
| `signing_request.certificate.generated` | "Signing certificate generated" | unknown | | Distinct from `completed`. The documented distinction must be preserved, not merged (`docs/HANDOFF.md` §11). |
| `signing_request.cancelled` | idem | unknown | | |
| `signing_request.expired` | idem | unknown | | |
| `signing_request.reminder.sent` | idem | unknown | | |
| `signing_request.recipient.signed` | `data.recipients[]` | unknown | | Does not imply full execution (`docs/HANDOFF.md` §11). |
| `signing_request.recipient.declined` | idem | unknown | | There is **no** `signing_request.declined` event, even though `SigningRequestDetail.status.declined` and `timestamps.declined_on` exist. See D12. |
| `signing_request.recipient.identity_changed` | idem | unknown | | Pairs with `settings.identity_editable_fields` and `settings.notify_identity_change_email`. |
| `template.updated`, `template.used` | Template events | unsupported | | Out of `firma-compat-v1`. Templates are a later family (`docs/HANDOFF.md` §10). |
| `workspace.created`, `workspace.updated` | Workspace events | unsupported | | Out of profile. |
| `domain.verified`, `domain.verification.failed` | Domain events | unsupported | | Out of profile; we do not manage sender domains through this API. |

### Envelope and delivery

| Surface | Upstream shape (prose) | Status | Fixture | Notes |
|---|---|---|---|---|
| Payload envelope | `{id, type, created_at, company_id, workspace_id, data:{signing_request, recipients[], workspace}}` | unknown | | Event identity is `id`. The consumer's resolver reads `event_id` or falls back to a payload hash. See D13. |
| `X-Firma-Signature` | `t=<unix seconds>,v1=<hex HMAC-SHA256>`; signed payload is `{timestamp}.{raw_json_body}`; hex digest; verify against the **raw** body | unknown | | Identical to `docs/HANDOFF.md` §11. Byte-exact raw body, constant-time compare. |
| `X-Firma-Signature-Old` | Second signature under the previous secret, sent for a 7-day rotation grace period | unknown | | Rotation overlap is required; a fresh attempt gets a fresh `t` and signature while the logical event `id` is retained. |
| `X-Firma-Event` | Event type string | unknown | | |
| `X-Firma-Delivery` | Unique delivery-attempt ID | unknown | | Per-attempt identity, stored separately from logical event identity. |
| Replay window | Docs show a 5-minute tolerance and call it "optional" | **intentionally different** | | Not optional for us. The inbox enforces the window (`docs/HANDOFF.md` §11). |
| Retry schedule | Immediate, +5 min, +1 h; up to 3 attempts; 5 s endpoint timeout; any 2xx is success | **intentionally different** | | Ours is configurable, and the difference from the profile is recorded here rather than hidden. |
| Auto-disable | After 50 consecutive failures | unknown | | Admins get a visible disabled-endpoint state and safe replay. |
| Health fields | `Webhook`: `consecutive_failures`, `auto_disabled_at`, `enabled`. The prose additionally promises `last_failure_at` and `last_success_at`, which are **not** in the `Webhook` schema. See D14. | unknown | | |
| Master switch | Delivery needs both the per-webhook `enabled` and a company/workspace master switch; `POST /webhooks/{id}/test` bypasses the master switch, so a passing test does not prove live delivery | **intentionally different** | | A silent global off-switch that suppresses events without logging a failure is an unsafe vendor behaviour we will not reproduce (`docs/HANDOFF.md` §10). |
| Destination policy | Upstream requires HTTPS and a ≤5 s response | **intentionally different** | | We additionally validate resolved destinations, block metadata/internal targets, disable unsafe redirects, and guard against DNS rebinding; the internal consumer callback is permitted only by explicit administrator policy (`docs/HANDOFF.md` §11). |

## Disagreements

Recorded rather than resolved. Each needs a captured synthetic fixture or an explicit ruling
before the corresponding row can move off **unknown**.

**D1 — Error envelope is not uniform.** `Error` uses `{error, message, details}`;
`CreateAndSendValidationError` and `ResendConflictError` use `{error, code, …}` with `code`
required and no `message`. A single serializer cannot produce both. Both shapes must be kept
per route (`docs/HANDOFF.md` §10).

**D2 — `PatchSigningRequestBodySchema` is the create body.** It is referenced only from
`POST /signing-requests` (via `requestBodies/PatchSigningRequestBody`). The actual PATCH body
is an inline `oneOf` on the route. The name is upstream's; do not copy it into our code.

**D3 — `Recipient.order` required or not.** The `Recipient` description says "ALL recipients
MUST have an explicit order value", but `order` is absent from the schema's `required` array
(only `first_name`, `email`, `designation` are listed). Fail closed: require it, and reject a
recipient without one with a clear message.

**D4 — Coordinate units: schema says percent, examples say points.** `Field.position`
constrains `x`/`y`/`width`/`height` to `0..100` "as percentage" with `x + width <= 100`. The
`create-and-send` request **example inside the same document** uses `{"x": 100, "y": 500,
"width": 200, "height": 50}` and `{"x": 100, "y": 400, "width": 150, "height": 30}` — values
that violate the schema it is an example of, and that read as PDF points. The consumer's own
comments record a live-tested *percentage* convention (`docs/HANDOFF.md` §2). Meanwhile
`AnchorTag.offset_units` explicitly offers `percent|pixels`, where "pixels" means "PDF points
(72 DPI)" — so upstream does have a points concept, just not on `Field.position`.
Resolution: the schema wins, the examples are treated as upstream errors, the facade accepts
percent only, and out-of-range values are rejected rather than reinterpreted. Never infer the
unit from a number's magnitude (`AGENTS.md`).

**D5 — Settings are booleans in one place and 0/1 integers in another.**
`SigningRequestSettings` types `allow_download`, `attach_pdf_on_finish`,
`hand_drawn_only`, `use_signing_order`, `allow_editing_before_sending` as booleans;
`SigningRequestDetail` repeats all five at the top level as `integer` enum `[0, 1]`, marked
deprecated. Emit both, each with its own type.

**D6 — Two of three PATCH response variants are untyped.** The `oneOf` entries for the
recipient and field cases are bare `{"type": "object"}` with only a description. The document
cannot pin those responses; only a fixture can.

**D7 — `warnings` (array, on create) versus `warning` (singular, on PATCH).** Both are
described as email-format validation warnings. Preserve the difference.

**D8 — `POST /signing-requests/{id}/send`: schema and example are disjoint.** Schema
`SendSigningRequestResponse` = `{success, message, sentTo, sentAt}` (camelCase, nothing
required); the operation's own example = `{message, signing_request_id, recipients_notified,
sent_date, expires_at}` (snake_case). They share only `message`. This is the sharpest
disagreement in the document and blocks a tested contract for `/send` until a fixture settles
it. Whichever we ship must be stated explicitly, not chosen silently.

**D9 — Upstream typos are part of the contract.** `SigningRequestField` exposes deprecated
`x_postion` (missing "i") and `heigh` (missing "t"), described as aliases of `position.x` and
`position.height`. `SigningRequestCreateField` — a different schema, on the create response —
spells the same concept `x_position` / `height` correctly. Reproduce each shape exactly where
it appears; do not normalise the typos away, and do not propagate them into native code.

**D10 — Five different field-type enums.** `Field` (create/PUT body) has `image`, `initials`,
`textarea` but lacks `file`, `radio_buttons`, `url`. `SigningRequestField` (read) has `file`,
`radio_buttons`, `url` but lacks `image`, `initials`, `textarea`. The inline PATCH `field`
enum is the union of those two minus `image`. `SigningRequestCreateField` (create response)
uniquely includes `number`. `AnchorTag` uniquely includes `radio`. Type normalisation
(`initials`→`initial`, `textarea`→`text_area`) is documented only on `Field` and the PATCH
body. The profile needs one internal type set plus explicit per-surface projections, and an
unsupported type must error rather than round-trip.

**D11 — `allow_partial_download` is documented but does not exist.** The `/download` route
prose gates partial downloads on `allow_partial_download` "in settings";
`SigningRequestSettings` has no such property. The closest real property is
`allow_presigning_download`, which means something else ("allow signers to download the
original document before signing"). Partial-download support is therefore unspecified.

**D12 — No request-level `declined` event.** `SigningRequestDetail.status.declined` and
`timestamps.declined_on` exist, and `/download` accepts `declined` as a terminal status, but
the event list only has `signing_request.recipient.declined`. Do not manufacture a
`signing_request.declined` event to fill the gap (`docs/HANDOFF.md` §11).

**D13 — Webhook event identity: `id` versus `event_id`.** The webhook guide's envelope and
its idempotency example both use `id`. The consumer's webhook controller resolves `event_id`
first and falls back to a payload hash (`docs/HANDOFF.md` §2, R2). The response body of
`POST /webhooks/{id}/test` uses a third name, `webhook_event_id`, for the event record. Our
facade emits `id`; the consumer must normalise both names explicitly, and the inbox must key
on a unique provider-scoped identity so a duplicate-insert race cannot turn an unprocessed
event into an acknowledged loss.

**D14 — Webhook health fields promised in prose are missing from the schema.** The guide says
a webhook GET returns `last_failure_at` and `last_success_at`; the `Webhook` schema has
neither (only `consecutive_failures`, `auto_disabled_at`, `enabled`, timestamps).

**D15 — The unversioned spec URL is not the pinned one.**
`https://docs.firma.dev/api-reference/openapi.json` resolves but served a different, smaller
document (31 paths) than the pinned v01.35.00 file (72 paths) at retrieval time. Anyone
re-deriving this matrix must use the versioned URL in `tests/Fixtures/firma/SCHEMA.md`.

## Out of profile

Present in the pinned document, deliberately **unsupported** in `firma-compat-v1`, and
required to return a clear error rather than a no-op success: `/company*`, `/workspaces*`,
`/templates*`, `/documents`, `/webhooks*` administration, `/generate-template-token`,
`/revoke-template-token`, `/jwt/*`, `/*/custom-fields*`, `/*/email-templates*`,
`/*/signer-terms*`, `/*/domains*`, `/*/logo`, `/signing-requests` (list), `PUT` and `DELETE`
on `/signing-requests/{id}`, `/signing-requests/{id}/reminders`,
`/signing-requests/{id}/audit`, `/signing-requests/{id}/resend`, and
`/signing-requests/{id}/signers/{signer_id}/{signature,initials,stamps,files}`.

Expanding into any of these families is a deliberate profile change: add the rows here, with
shapes read from the pinned document, before writing the adapter.
