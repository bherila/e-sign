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
| **supported** | Implemented, and a fixture or a test asserts the contract. Only outbound webhook delivery qualifies so far; its rows cite tests rather than captured fixtures, because the signature scheme and envelope are prose with no machine-readable document to capture. |
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

The outbox accepts exactly the names below plus our own, which are prefixed `esign.`;
anything else is refused rather than recorded (`App\Domain\Delivery\Webhooks\WebhookEventName`,
`tests/Unit/Delivery/WebhookEventNameTest.php`).

The envelope state machine now records them. `App\Domain\Delivery\Events\DeliveryEnvelopeEventSink`
publishes each transition inside the transaction that made it, with the payload
`App\Domain\Delivery\Events\SigningRequestPayload` builds — the same builder the facade uses
for `GET /signing-requests/{id}`, so a polling receiver and a subscribing receiver cannot be
told two different stories. The mapping from transition to event to message is
`docs/delivery/envelope-events.md`. Rows for events no transition produces stay **unknown**.

| Event | Upstream shape (prose) | Status | Fixture | Notes |
|---|---|---|---|---|
| `signing_request.created` | Envelope below, `data.signing_request` | supported | `DeliveryEnvelopeEventSinkTest::test_creating_an_envelope_records_the_created_event_and_tells_nobody` | Recorded when the envelope is built from its snapshot. No mail: a draft has been shown to nobody. |
| `signing_request.sent` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_sending_records_one_event_and_invites_only_the_released_stage` | Carries every recipient. The invitation mail goes only to the stage `send()` released. |
| `signing_request.viewed` | idem | unknown | | A view is not assent. GET must stay harmless (`AGENTS.md`). |
| `signing_request.updated` | idem | unknown | | |
| `signing_request.deleted` | "deleted (before sending)" | unknown | | |
| `signing_request.completed` | "All recipients finished signing" | **intentionally different** | `DeliveryEnvelopeEventSinkTest::test_completion_is_recorded_once_the_artifact_reference_exists` | Upstream ties this to signing completion. We publish it only after the final PDF is generated, validated, durably stored and retrievable (`AGENTS.md`, `docs/HANDOFF.md` §11) — from `markCompleted()` and nowhere else. Later than upstream, deliberately. |
| `signing_request.certificate.generated` | "Signing certificate generated" | unknown | | Distinct from `completed`. The documented distinction must be preserved, not merged (`docs/HANDOFF.md` §11). |
| `signing_request.cancelled` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_a_cancellation_reaches_everybody_who_had_been_written_to` | Mail reaches recipients carrying an `invited_at`; a later signer who was never written to is not told an agreement they never saw was withdrawn. |
| `signing_request.expired` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_an_expiry_reaches_everybody_who_had_been_written_to`, `SigningScheduleCommandsTest` | Applied by `esign:signing:expire`; the state machine re-checks the deadline under a lock, so the command cannot expire anything early. |
| `signing_request.reminder.sent` | idem | unknown | | `esign:signing:remind` sends the mail but records no event yet. The name is accepted by the outbox; nothing publishes it. |
| `signing_request.recipient.signed` | `data.recipients[]` | supported | `DeliveryEnvelopeEventSinkTest::test_an_acceptance_is_a_recipient_event_and_never_a_completion` | Does not imply full execution (`docs/HANDOFF.md` §11). `data.recipients` carries the one party who signed, with their `finished_on`; `status.finished` stays false. |
| `signing_request.recipient.declined` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_a_decline_records_both_names_and_writes_only_to_the_sender` | There is **no** `signing_request.declined` event, even though `SigningRequestDetail.status.declined` and `timestamps.declined_on` exist. See D12. We emit this one and our own `esign.envelope.declined` beside it, and never invent the upstream name. |
| `signing_request.recipient.identity_changed` | idem | unknown | | Pairs with `settings.identity_editable_fields` and `settings.notify_identity_change_email`. |
| `template.updated`, `template.used` | Template events | unsupported | | Out of `firma-compat-v1`. Templates are a later family (`docs/HANDOFF.md` §10). |
| `workspace.created`, `workspace.updated` | Workspace events | unsupported | | Out of profile. |
| `domain.verified`, `domain.verification.failed` | Domain events | unsupported | | Out of profile; we do not manage sender domains through this API. |

### Envelope and delivery

Implemented in `app/Domain/Delivery/Webhooks` (issue #34). Contract and runbook:
`docs/delivery/webhooks.md`.

| Surface | Upstream shape (prose) | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Payload envelope | `{id, type, created_at, company_id, workspace_id, data:{signing_request, recipients[], workspace}}` | **intentionally different** | `tests/Feature/Delivery/Webhooks/OutboxWriterTest.php` | `id`, `type`, `created_at`, `workspace_id` and `data` are emitted verbatim. `company_id` is omitted: this product has no company above the workspace, and a null or invented value would be worse than its absence. Event identity is `id`; see D13. `created_at` is when the transition occurred, not when the attempt was made. |
| `data.signing_request.status` | **Object** of booleans: `sent`, `finished`, `cancelled`, `declined`, `expired`; several true at once for terminal states | supported | `tests/Unit/Delivery/Events/SigningRequestPayloadTest.php` | Key set asserted against the captured `request.json` fixtures. Overlapping flags are reproduced: a cancelled envelope that was sent reports both, which a single enum cast could not. |
| `data.signing_request.timestamps` | Object with the `_on` suffix: `created_on`, `sent_on`, `finished_on`, `cancelled_on`, `declined_on`, `last_changed_on`, `last_signing_action_on` | supported | idem | Key set asserted against the fixtures. `finished_on` is completion, not the last signature — those are further apart here than upstream, and `last_signing_action_on` is where the signature shows up. |
| `data.recipients[]` | `SigningRequestUser`: `id`, `name`, `email`, `designation`, `order`, `finished_on`, `declined_on`, `decline_reason` | **intentionally different** | idem | Key set asserted against `users.json`. `first_name`/`last_name` are omitted: one display name is stored and splitting it would guess at a person's name (`docs/HANDOFF.md` §2). `designation` is the constant `Signer`; there is no approver or CC concept to enforce. |
| `data.signing_request.download` | not upstream — our own hint | **intentionally different** | `SigningRequestPayloadTest::test_a_published_artifact_is_reported_without_a_url` | Null until a validated artifact is published, then `{available, is_partial, generated_on}` and **never a URL**. A body is encoded once and replayed for ~40 h, so a link in it is either dead on arrival or a long-lived credential in a receiver's log. Bytes come from the download route, authorized per request. |
| `X-Firma-Signature` | `t=<unix seconds>,v1=<hex HMAC-SHA256>`; signed payload is `{timestamp}.{raw_json_body}`; hex digest; verify against the **raw** body | supported | `tests/Unit/Delivery/WebhookSignerTest.php`, `tests/Feature/Delivery/Webhooks/DeliverWebhookTest.php` | Byte-exact raw body: the envelope is encoded once when the event is recorded and every attempt sends those bytes unchanged. Tests verify with an independent `hash_hmac` computation, not by calling the signer twice. |
| Rotation overlap | `X-Firma-Signature-Old`: a second signature under the previous secret, for a 7-day grace period | **intentionally different** | `DeliverWebhookTest::test_during_a_rotation_both_secrets_sign_the_same_attempt` | We emit **both** forms: `X-Firma-Signature` carries one `v1=` per live secret, current first (the Stripe convention), and `X-Firma-Signature-Old` is emitted alongside during the overlap. A receiver that loops over `v1=` entries and a receiver that reads only the documented old header both verify. Grace window is `ESIGN_WEBHOOK_ROTATION_GRACE_HOURS`, default 168 h. |
| `X-Firma-Event` | Event type string | supported | `DeliverWebhookTest::test_the_identity_headers_name_the_event_the_attempt_and_the_type` | |
| `X-Firma-Delivery` | Unique delivery-attempt ID | supported | idem | A ULID, one per attempt row, distinct from the logical event id on every retry and replay. |
| `X-Esign-Event-Id` | **not upstream** — our documented extension | **intentionally different** | idem | The logical event id, stable across every retry and replay, in a header as well as in the envelope's `id`. Upstream offers no header carrying it, so a receiver must parse the body before it can deduplicate; this lets it decide from the headers. |
| `X-Esign-Attempt` | **not upstream** — our documented extension | **intentionally different** | idem | The attempt number, 1-based. Lets a receiver log "this is the fourth try" without joining on our side's state. |
| Replay window | Docs show a 5-minute tolerance and call it "optional" | **intentionally different** | `DeliverWebhookTest::test_the_signature_timestamp_is_fresh_at_the_moment_of_the_attempt` | Not optional for us. Each attempt is signed with a fresh `t`, so a retry two days later still lands inside a receiver's window, and the inbox enforces the window (`docs/HANDOFF.md` §11). |
| Retry schedule | Immediate, +5 min, +1 h; up to 3 attempts; 5 s endpoint timeout; any 2xx is success | **intentionally different** | `tests/Unit/Delivery/RetryScheduleTest.php`, `DeliverWebhookTest` | Default 1 m, 5 m, 30 m, 2 h, 12 h, 24 h with ±10 % jitter — seven attempts over ~40 h — and every value is configurable. Any 2xx is success and the 5 s timeout is kept. 5xx, connection failures, timeouts, 408 and 429 retry; every other 4xx stops immediately, because repeating a request the receiver called malformed cannot help. |
| Auto-disable | After 50 consecutive failures | **intentionally different** | `DeliverWebhookTest::test_enough_undeliverable_events_disable_the_endpoint_with_a_visible_reason` | Default 10 consecutive undeliverable events, configurable. The disabled state carries a reason an operator reads in `esign:webhook:endpoint:list`, is audited, and is cleared — along with the failure count — by `esign:webhook:endpoint:enable`. |
| Health fields | `Webhook`: `consecutive_failures`, `auto_disabled_at`, `enabled`. The prose additionally promises `last_failure_at` and `last_success_at`, which are **not** in the `Webhook` schema. See D14. | **intentionally different** | `tests/Feature/Delivery/Webhooks/WebhookConsoleTest.php` | We keep `consecutive_failures` and a disabled state with a reason. Per-attempt history — timestamp, status, redacted response excerpt, redacted error, next attempt — lives in `webhook_deliveries`, which is strictly more than the two timestamps the prose promises. No HTTP `Webhook` resource is exposed yet; administration is artisan-only. |
| Master switch | Delivery needs both the per-webhook `enabled` and a company/workspace master switch; `POST /webhooks/{id}/test` bypasses the master switch, so a passing test does not prove live delivery | **intentionally different** | | A silent global off-switch that suppresses events without logging a failure is an unsafe vendor behaviour we will not reproduce (`docs/HANDOFF.md` §10). There is one switch, per endpoint, and it is visible. |
| Destination policy | Upstream requires HTTPS and a ≤5 s response | **intentionally different** | `tests/Unit/Delivery/DestinationPolicyTest.php`, `tests/Unit/Delivery/WebhookTransportOptionsTest.php` | We additionally validate every resolved address, block metadata/internal targets, refuse credentials in the URL, never follow a redirect, and pin the connection to the addresses we checked (DNS rebinding). The policy runs on every attempt, not once at creation. The internal consumer callback is permitted only by an administrator allowlist in deployment configuration, never by a caller-controlled flag (`docs/HANDOFF.md` §11). |
| Duplicate suppression | Receivers are told to deduplicate on the event id | supported | `DeliverWebhookTest::test_a_replay_is_a_new_attempt_of_the_same_event` | Deduplication is the receiver's job; our side of the bargain is that the event id never changes across retries or replays, and neither do the body bytes. |
| Delivery ordering | Not stated by the guide | **intentionally different** | `OutboxWriterTest::test_two_events_keep_the_order_in_which_they_occurred` | We make no ordering promise — a retry can reorder anything — and instead guarantee `created_at` reflects when the transition occurred, so a receiver can order and reject regressions itself. |

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

## Recorded fixtures

`tests/Fixtures/firma/firma-compat-v1/<workflow>/{request,users,fields,download}.json` hold
sanitized live responses (2026-09-08) for the four consumer workflows in four different states
(sent, finished, cancelled, finished single-recipient). `tests/Feature/Compatibility/FirmaFixtureShapeTest.php`
pins the shapes the consumer depends on. Rows for `GET /signing-requests/{id}`, `/users`,
`/fields`, and `/download` are therefore fixture-backed; create/send/patch/cancel remain derived
from the consumer's client code and the reference only.

