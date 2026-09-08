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
| Adapter version | **1** — `app/Domain/Integration/Firma`, `app/Http/Controllers/Compat/Firma`, `routes/compat-firma.php` (issue #33) |
| Fixture version | **2026-09-08** — `tests/Fixtures/firma/firma-compat-v1/`, four workflows × four endpoints |

**Every "upstream shape" cell below is read off the pinned document unless it is tagged
`(prose)`.** Nothing here is inferred from a numeric magnitude or from consumer behaviour
(`AGENTS.md`, "Coordinates are never guessed").

## Status vocabulary

| Status | Meaning here |
|---|---|
| **supported** | Implemented, and a fixture or a test asserts the contract. The `Fixture / test` column names it. |
| **unsupported** | Deliberately out of profile, or declared upstream and not implemented in this build. Returns `501` with a body naming the option; never a no-op success. |
| **intentionally different** | Shipped behaviour differs from upstream, on purpose, with the reason in the notes. |
| **unknown** | Shape captured, decision not made. **No profile route has one.** The only remaining ones are upstream webhook events that no transition in this product produces. |

Every route row below is now `supported`, `intentionally different`, or `unsupported`, which
is the acceptance criterion of issue #33. Counts are in [Status summary](#status-summary).

## Status summary

Every row of every table below, counted. There are no `unknown` rows.

| Status | Rows |
|---|---|
| supported | 48 |
| intentionally different | 46 |
| unsupported | 14 |
| **unknown** | **0** |

"Intentionally different" outnumbering "supported" is the accurate reading of what a
compatibility facade over a different product looks like, not a sign of incompleteness. Most of
those rows are one of four recurring decisions:

1. **No pre-signed URLs.** Every `*_download_url`, `document_url` and `download_url` is an
   app-issued, signature-covered, short-lived capability instead, and the bytes stream through
   the application (`docs/BLOB_STORAGE.md` rule 1).
2. **No invented facts.** `credit_cost`, `credits_remaining`, `first_name`/`last_name`, the
   contact block, the corner positions, and eleven of the fourteen `settings` members are null
   or false with a documented reason, because a plausible value for something this product does
   not model is worse than an honest absence.
3. **Nothing silently dropped.** Where upstream ignores an unmatched override, an unknown
   field type, or an option it cannot honour, this facade refuses with a body naming it.
4. **State conflicts are `409`.** Upstream answers `400` for several of them; a `400` reads as
   "fix your body" for something no body can fix.

## Authentication and transport

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| `Authorization` header | `securitySchemes.ApiKeyAuth`: `apiKey` in header named `Authorization`. Description: "Use your API key directly without any prefix… Bearer prefix is optional but not required." | supported | `FirmaAuthTest::test_the_raw_api_key_authenticates`, `::test_the_bearer_syntax_authenticates` | The scheme-less form is the one the consumer sends and the one the facade is judged on (`docs/HANDOFF.md` §10). `Bearer` is accepted as well; there is no ambiguity, because a raw key always starts `esk_` and a bearer token never does. Scope and tenant are enforced before any resource lookup. |
| Scope admission | not upstream — upstream keys are workspace-wide | **intentionally different** | `FirmaAuthTest::test_a_credential_without_the_profile_scope_is_forbidden`, `::test_the_profile_scope_alone_does_not_grant_reads` | A credential needs `compat:firma-v1` to reach this surface *and* the route's own resource scope. The profile scope grants no resource authority: a key issued for `/api/v1` does not gain a second HTTP surface, and admission is not permission. |
| Rate-limit headers | `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` on 2xx; `Retry-After` on 429/503. Documented tiers: GET 200/min, write 100/min, webhooks 60/min, send 60/min, JWT 120/min. | unsupported | | Not emitted. Advertising a limit this deployment does not enforce would have a client back off for a reason that does not exist, and matching upstream's tier values is not a profile requirement. Rate limiting is a deployment concern (`docs/operations`), and when it lands these headers come with it. |
| Error envelope | `Error` = `{error (required, human-readable), message, details}` | supported | `FirmaAuthTest` (401/403/404/501), `FirmaLifecycleTest` (409/422), `FirmaCreateAndSendTest` (400) | `error` carries a short machine token and `message` the sentence, which is what every recorded upstream example does even though the schema calls `error` human-readable. `details` appears only when there is something structured to say. `create-and-send` keeps its own envelope; see D1. |
| Base URL / signing links | Consumer hardcodes `https://app.firma.dev/signing/{recipientId}`; upstream returns `CreateAndSendResponse.first_signer.signing_link` as an opaque `uri` | **intentionally different** | `FirmaCreateAndSendTest::test_the_document_is_ingested_and_the_request_is_sent` | We do not impersonate the Firma hosted site (`docs/HANDOFF.md` §10). `signing_link` is this application's **stable resolver**, `signing.legacy.show` — the same `/signing/{recipientId}` path shape, on this host — and deliberately **not** a freshly minted invitation. A recipient has at most one live invitation and issuing another revokes the previous one, so a credential minted for this response would either kill the link the invitation email is about to carry or be killed by it, depending on when the queue ran; either way the field would be a URL that goes nowhere. The resolver authorizes nothing on its own: mailbox verification is what turns it into a session, which `docs/HANDOFF.md` §8 requires. The consumer still has to read the field rather than build the URL, because the host differs. |
| Identifiers | Every `id` is `format: uuid` | **intentionally different** | `FirmaContractTest::test_the_polling_response_has_the_recorded_key_set` | A signing request's `id` is this application's public ULID. A parallel identifier space so that a compatibility surface could show UUIDs would mean two ids for one agreement, a mapping table to keep them in step, and a support conversation every time they disagreed. Treat the id as opaque — which the fixtures' own README shows is already necessary, since one id the consumer had stored turned out never to have been a provider id. |
| Idempotency keys | not upstream | **intentionally different** | | `Idempotency-Key` is honoured on `/api/v1` and **not** on this surface. Upstream has no such header, and adding one here would mean a consumer's retry behaved differently against the two surfaces for reasons its own contract does not describe. A repeated `cancel` is still a `409`, which is upstream's answer. `docs/HANDOFF.md` §10 asks for the extension as a documented one; it is documented on the native API. |

## Routes

### `POST /signing-requests`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Request body | `$ref` → `requestBodies/PatchSigningRequestBody` → `PatchSigningRequestBodySchema`, a `oneOf`: (a) requires `document` (base64 PDF/DOCX), (b) requires `template_id`. Shared: `name` (≤255), `description`, `expiration_hours` (min 1, default 168), `recipients[]`, `fields[]`, `anchor_tags[]` (max 100, document-based only), `reminders[]`, `settings`, `language`, `completion_title/message/redirect_url/redirect_delay` | supported | `FirmaLifecycleTest::test_a_draft_is_created_from_a_native_template_id`, `FirmaCreateAndSendTest` | `document` xor `template_id`, both accepted. DOCX is not: only PDF reaches the preflight parser. `language` and the four `completion_*` members are `501` — the signing pages are this application's own, so supplied copy would not be rendered. `reminders[]` and `anchor_tags[]` are `501`; see the rows below. The schema's name says "Patch" and it is the create body (D2); the name is not copied into our code. |
| `201` response | `SigningRequestCreateResponse` — required `id`, `name`, `status`; `status` enum is **`["draft"]` only**; flat `fields[]` (`SigningRequestCreateField`), `recipients[]`, `document_url`, `page_count`, `expiration_hours`, `template_id`, `settings`, `created_date`/`updated_date`/`sent_date`/`finished_date`/`cancelled_date`, `warnings[]` | supported | `FirmaLifecycleTest::test_a_draft_is_created_from_a_native_template_id` | Every member emitted. `status` is the string `"draft"`; the `_date` suffix is preserved against the detail response's `_on`. `template_id` echoes the **template version** id the envelope copied, because that is what it copied. `warnings` is empty: the only warnings upstream documents are email-format ones, and an address that does not validate is a `400` here. |
| Recipient mapping | `Recipient` requires `first_name`, `email`, `designation`. `designation` enum `Signer\|Approver\|CC`. `order` documented as required for all recipients (but absent from the schema `required` list — D3). Temporary IDs `temp_1`, `temp_2`… resolve to real UUIDs in the response. `name` is auto-constructed and overwrites any supplied value. | **intentionally different** | `FirmaCreateAndSendTest::test_a_recipient_without_an_order_is_refused`, `::test_a_non_signer_designation_is_not_implemented` | `order` is **required**: an implied signing sequence is not inferred from array position (D3, fail closed). `designation` other than `Signer` is `501` — an approver would have to be treated as a signer, binding them to an agreement they meant to review, or dropped. `name` is joined from the parts rather than overwritten, and never split back apart: this product stores one display name and does not guess which part of a person's name is which. Temporary ids resolve to recipient public ids in the response, as upstream does. |
| Template overrides / prefills | Template-based: match template fields by `template_field_id` (preferred) or `variable_name` (fallback); match template users by `template_user_id` or `order`. Only user info is overridable — `order` and `designation` always inherit from the template. Unmatched fields are silently ignored. | **intentionally different** | `FirmaLifecycleTest::test_a_prefill_that_matches_no_field_is_refused`, `::test_a_draft_is_created_from_an_imported_template_alias` | Both addressing modes work; `order` and the signing sequence still come from the template. Silent ignore is the vendor behaviour we must not reproduce (`docs/HANDOFF.md` §2, §10): an unmatched override is a `400` naming the field ids or variable names that do exist. A dropped prefill is a blank in an executed agreement nobody notices. A `variable_name` matching two fields is also an error — the recorded fixtures show `full_name` twice on one agreement — so the caller says which by `id`. |
| `template_id` namespace | `format: uuid`, upstream's own id | supported | `FirmaLifecycleTest::test_a_draft_is_created_from_an_imported_template_alias` | Accepts this application's `templates.public_id` **or** an imported alias from `template_aliases` — the provider id the consumer already has hardcoded. Native id first, because that namespace is ours. `docs/HANDOFF.md` §6 keeps the two in separate fields, so neither ever becomes the other. |
| `400` / `404` | `400` invalid input (must supply `document` xor `template_id`); `404` template not found or not in workspace | supported | `FirmaLifecycleTest::test_an_unknown_template_id_is_not_found`, `FirmaCreateAndSendTest::test_a_document_and_a_template_together_are_refused` | A template that resolves and has no published version is `422`, not `404`: the caller named something real that is not ready, and a `404` would send them looking for a typo. The `404` is produced after scope enforcement, so another tenant's id is indistinguishable from one that does not exist. |
| `settings.require_otp_verification` | `SigningRequestSettings.require_otp_verification`, boolean | supported | `FirmaCreateAndSendTest::test_require_otp_verification_is_honoured`, `::test_omitting_the_otp_setting_leaves_the_envelope_undecided` | Honoured, and written to `envelopes.require_otp`. **Null is not false**: saying nothing leaves the request inheriting its workspace and then the deployment default, and an explicit `false` overrules both — a distinction a caller migrating from a workspace that required a code needs to be able to make. The code is honestly labelled everywhere a human sees it as a second check on the same factor, continued access to the mailbox the link went to; never a second factor, and never an eIDAS assurance (`docs/HANDOFF.md` §9). |
| Other `settings` members | `SigningRequestSettings`, 14 members | **intentionally different** | `FirmaCreateAndSendTest::test_hand_drawn_only_is_not_implemented` | Four are `501` when asked for as `true` — `hand_drawn_only`, `allow_editing_before_sending`, `attach_pdf_on_finish`, and a non-empty `identity_editable_fields`. The rest are accepted and reported truthfully on read. `false` and `null` are never requests for anything and always pass, which is the difference between failing closed and being unusable. |
| Atomicity | not stated for this route | supported | `FirmaLifecycleTest::test_a_prefill_that_matches_no_field_is_refused` | Creation is one transaction. A draft whose prefills were rejected does not survive as a half-built request the client never learned about. |

### `POST /signing-requests/create-and-send`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Request body | Inline object, requires `name`. `document` (base64, ~4.5 MB inline cap) **xor** `template_id` **xor** `document_id` (from `POST /documents`, up to 50 MB). Plus `recipients[]`, `fields[]`, `anchor_tags[]`, `reminders[]`, `settings`, `expiration_hours` | supported | `FirmaCreateAndSendTest::test_the_document_is_ingested_and_the_request_is_sent` | `document` and `template_id` are supported; `document_id` is not, because `POST /documents` is out of profile — a caller cannot obtain one from this surface, so accepting the field would be accepting an id we never issued. The 4.5 MB inline cap is enforced on the decoded bytes, and the deployment's own upload limit applies on top. A base64 payload prefixed `data:application/pdf;base64,` is accepted, because it turns up in practice. |
| `201` response | `CreateAndSendResponse` — `status` enum is **`["sent"]` only**; `first_signer` = `{id, name, email, signing_link}`; `recipients[]` with real UUIDs; `fields[]` (`Field`); `credits_remaining` | **intentionally different** | `FirmaCreateAndSendTest::test_the_document_is_ingested_and_the_request_is_sent` | `credits_remaining` is `null`: there is no credit ledger, so any number would be invented, and omitting the key would break a consumer that reads it. `first_signer.signing_link` is ours and is the stable resolver rather than a minted invitation — see the signing-links row under Authentication for why. `recipients[]` carries `finished_on` as well, so a caller does not need `/users` immediately. |
| Document intake | not stated — upstream accepts the bytes | supported | `FirmaCreateAndSendTest::test_an_encrypted_document_is_refused_before_anything_is_created` | The bytes go through `DocumentIntake`, the same intake the upload UI uses: staged, hashed, really parsed by the preflight parser, written and read back, recorded with the report. An encrypted, already-signed, or structurally unsupported PDF is a `400` carrying the parser's findings, before any signing request exists. The original is retained byte-for-byte and never re-rendered (AGENTS.md). |
| Validate-before-send | `400` → `CreateAndSendValidationError` with `phase` enum `create_validation\|send_validation` and `validation_errors[]` = `{recipient_index (1-based), recipient_email, missing_fields[]}` | supported | `FirmaLifecycleTest::test_send_refuses_when_a_required_prefill_is_missing` | Reproduced verbatim, including `phase`, because it is how the consumer surfaces a missing prefill to a human. `validation_errors[]` is built from the same readiness projection `/users` reports, so a caller that polls after a refused send sees the same answer. |
| Atomicity | `500` description: "Any partially-created signing request is rolled back, so no draft remains." | supported | `FirmaCreateAndSendTest::test_hand_drawn_only_is_not_implemented`, `::test_a_coordinate_outside_the_percent_range_is_refused` | Matches our fail-closed rule and is stricter: an unsupported option is refused before the document is even ingested. The consumer's platform-NDA path currently warns and returns a link on send failure; the migration must fail closed instead (`docs/HANDOFF.md` §2). |
| `402` / `422` | `402` `InsufficientCreditsError`; `422` `UnprocessableEntityError` for suppressed (bounced/spam-marked) recipient addresses | unsupported | | `402` has no analogue: there are no credits to run out of. `422` for a suppressed address needs a suppression list, which does not exist yet; when it does, this row becomes supported without a shape change. Neither is fabricated in the meantime. |
| Field coordinates | `Field.position` = `{x, y, width, height}`, all required, `number`, `minimum: 0`, `maximum: 100`, described as "percentage (0-100)", with `x + width <= 100` and `y + height <= 100`. `page_number` 1-indexed and required. | **intentionally different** | `FirmaCreateAndSendTest::test_percent_coordinates_land_on_the_declared_native_rectangle`, `::test_a_coordinate_outside_the_percent_range_is_refused`, `::test_a_field_that_runs_off_the_page_is_refused` | Our native space is `pt`, top-left, CropBox, displayed rotation; the facade converts with `FacadeCoordinateTranslator` under the profile's **declared** convention `percent_of_page_top_left`. Percentages are of the *displayed* page, so a `/Rotate 90` Letter page treats 50% of `x` as 396 pt. Out-of-range is rejected, never reinterpreted as points, and the unit is never inferred from a value's magnitude (AGENTS.md). The upstream examples contradict the upstream schema; the schema wins (D4). |
| Field ownership | not clearly expressed — several members could name a recipient | supported | `FirmaCreateAndSendTest::test_a_field_that_names_no_recipient_is_refused` | A field names its owner by `recipient_id` (a temporary id), `recipient_email`, or `order`; the first that resolves wins. With more than one recipient, a field that names none is a `400` — ownership is never assigned by elimination or by signing position (`docs/HANDOFF.md` §2). With exactly one recipient there is nothing to choose between, so it is not a guess. |
| Anchors | `fields[].anchor` is not upstream's shape; upstream uses a separate `anchor_tags[]` collection with `offset_units: percent\|pixels` and its own type enum | **intentionally different** | `FirmaCreateAndSendTest::test_an_anchored_field_is_placed_from_the_document_text`, `::test_an_anchor_that_matches_nothing_is_refused` | `fields[].anchor = {text, occurrence?, origin?, offset_x?, offset_y?}` is a documented compatibility extension. The text is located by `PdfTextLocator` — a real content-stream parse — and resolved by `AnchorResolver`. Offsets are percentages of the page, like every other number in this profile: a second coordinate system with its own unit switch is how a signature ends up in the wrong place, which is why `anchor_tags[]` itself is `501`. `position.width` and `position.height` are still required, because an anchor says where a field goes and never how big it is. An anchor that matches nothing, or ambiguously, is a `400`. |
| `fields[].type` | Five disagreeing enums across the document (D10) | **intentionally different** | `FirmaCreateAndSendTest::test_a_declared_but_unimplemented_field_type_is_not_implemented` | One internal type set with explicit per-surface projections. Inbound spellings are normalised (`initials`→`initial`, `textarea`→`text_area`, as upstream documents). A type upstream declares and this build cannot place — `dropdown`, `file`, `image`, `number`, `radio`, `radio_buttons`, `stamp`, `url` — is `501` naming it, never coerced into a text box and never dropped: a field a signer was never asked to complete is worse than a rejected request (`docs/HANDOFF.md` §7). |

### `GET /signing-requests/{id}`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| `200` response | `SigningRequestDetail` — required `id`, `name`, `status`, `companies_workspaces_id` | supported | `request.json` × 4; `FirmaContractTest::test_the_polling_response_has_the_recorded_key_set` | The key set is asserted against all four recorded workflows, at every level of nesting, in both directions — a missing key and an invented one both fail. `companies_workspaces_id` is the workspace public id; there is no company above a workspace here. |
| `status` | **Object** of booleans: `sent`, `finished`, `cancelled`, `declined`, `expired`. "multiple can be true for terminal states" | supported | idem | Built by `SigningRequestPayload::status()` — the same projection every `signing_request.*` webhook carries, so a polling receiver and a subscribing one cannot be told two different stories. Overlapping flags are reproduced: a cancelled request that was sent reports both, which a single enum cast could not. |
| `timestamps` | Object: `created_on`, `sent_on`, `finished_on`, `cancelled_on`, `declined_on`, `last_changed_on`, `last_signing_action_on` (all `date-time`, most nullable) | supported | idem | `_on` here, `_date` on create; both preserved. `finished_on` is completion — a validated final PDF durably stored — not the last signature, which shows up in `last_signing_action_on`. Those two are further apart here than upstream, deliberately. |
| `expires_at` | `date-time`, nullable, computed from `sent_on + expiration_hours`; null before send | supported | idem | |
| `certificate` | Nullable object `{generated, generated_on, has_error}` | **intentionally different** | `FirmaDownloadTest::test_the_detail_download_urls_appear_only_when_finished` | Mapped onto the **completion report** artifact, which is a statement of what happened and not an X.509 credential belonging to any signer (AGENTS.md, "Honest language"). The member name is upstream's and is kept so a consumer can read it. `generated` is true once the report is published, which happens in the transaction that completes the request — so never before the evidence is retrievable. `has_error` is true only for a visibly failed finalization. |
| Download URLs | `final_document_download_url`, `document_only_download_url`, `certificate_only_download_url` — all nullable pre-signed URIs "expire after 1 hour"; each has a paired `*_download_error` enum `file_not_accessible\|null` | **intentionally different** | idem | We never pre-sign, on any driver (`docs/BLOB_STORAGE.md` rule 1). The facade returns app-issued, signature-covered, fifteen-minute URLs in the same fields, and the bytes stream through the application. All three are null until the agreement is executed, which is what the recorded fixtures show. `*_download_error` stays null: an artifact row exists only after its bytes were written and read back, so "recorded but inaccessible" is a fault to report loudly at download time rather than a field on a polling response. |
| `document_url` / `document_url_expires_at` | Pre-signed URI plus its expiry | **intentionally different** | idem | Same treatment: an app-issued fifteen-minute link to the reviewed revision — the bytes the parties are shown — rather than a storage pre-sign. Issued in every state including a draft, because the caller is the sender reading back their own upload. |
| Deprecated 0/1 integers | `use_signing_order`, `allow_download`, `allow_editing_before_sending`, `hand_drawn_only`, `attach_pdf_on_finish` — `integer` enum `[0, 1]`, all marked `deprecated` | supported | `FirmaContractTest::test_the_polling_response_has_the_recorded_key_set` | Emitted at the top level as integers **and** under `settings` as booleans, each with the right type in its own place (D5). The test asserts the types separately, because a client reading `response.use_signing_order` and one reading `response.settings.use_signing_order` are both in the wild. |
| `settings` | `SigningRequestSettings`, 14 members | **intentionally different** | idem, and `FirmaCreateAndSendTest::test_require_otp_verification_is_honoured` | Every member emitted so the key set matches. Three describe something this product models — `use_signing_order` (sequential vs parallel), `allow_download` (always true; every party can fetch the executed PDF through an authorized download), and `require_otp_verification`, which reports the **resolved** answer from `OtpRequirement`: this request's own setting, then its workspace's, then the deployment default. Resolved rather than echoed, so a caller that set nothing still sees what its guests will be asked for. The other eleven report `false` or `null`, each documented in `SigningRequestSettings` with which it is and why; inventing `true` for a setting nothing enforces would be a successful no-op one read at a time. |
| `credit_cost` | `integer`, "Credits consumed when sent" | **intentionally different** | idem | `null`. There is no credit system, and the two alternatives were both worse: omitting the key breaks a consumer that reads `response.credit_cost`, and a number invents a billing fact. Null says "no cost is recorded", which is true. (This row was `unsupported — omit and document` in Stage 0; emitting the key as null is the ruling, because the recorded fixtures carry the key and the consumer's polling code reads the whole object.) |

### `PATCH /signing-requests/{id}`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Request body | `oneOf` of exactly three mutually exclusive forms: (a) properties only, (b) `{recipient: Recipient}`, (c) `{field: {...}}` — **singular**. "Cannot update multiple entity types in one request." | supported | `FirmaLifecycleTest::test_a_field_is_patched_by_variable_name`, `::test_a_patch_cannot_update_two_entity_types` | The `field` and `recipient` forms are implemented; two entity types in one request is a `400`, as upstream's own description requires. |
| Properties form | `{name, description, expiration_hours, template_description}` | unsupported | `FirmaLifecycleTest::test_renaming_a_signing_request_is_not_implemented` | `501` naming the members sent, with `details.patchable`. A signing request's title, document and field schema are one immutable snapshot (`Envelope::SNAPSHOT_COLUMNS`), which is what lets an executed agreement be proved against what the parties were shown. Accepting a rename and doing nothing would be the no-op AGENTS.md forbids. |
| `field.position` | `{x, y, width, height}`, each a bare `number` with **no** min/max and described only as "X position on document" / "Field width" | unsupported | | Moving a field after the request is built is refused for the same reason: the copied field schema is immutable, and `schema_recipient_id` is the handle a field uses to find its owner. The percent range that `Field.position` states is absent here (D4); we would enforce it on both if this were supported. |
| Field prefill by `variable_name` | `field.variable_name` "Variable name for prefilled data mapping"; `final_value` "Final value of the field (for pre-filled read-only fields)"; `read_only` / `read_only_value` | supported | `FirmaLifecycleTest::test_a_field_is_patched_by_variable_name`, `::test_a_field_is_patched_by_id`, `::test_a_patch_for_an_unresolvable_variable_name_is_refused` | Both addressing modes work, because the consumer patches prefills by name while its template field ids are hardcoded (`docs/HANDOFF.md` §2). An unresolvable name is a `400`; an ambiguous one names the candidate ids. The value is read from `value`, then `final_value`, then `read_only_value`. The profile's `variable_name` is stored verbatim in the native schema's `label` and normalised into `alias`, because `alias` is an identifier and real consumer variable names contain spaces and slashes (`Company/Individual Name`); `/fields` echoes back the exact string. |
| Read-only / editable rules | `url` fields are "automatically read-only". `read_only=true` + `read_only_value` for static prefill. | **intentionally different** | `FirmaLifecycleTest::test_making_a_read_only_field_editable_is_not_implemented`, `::test_a_prefilled_editable_that_agrees_with_the_field_is_accepted` | `prefilled_editable` and `read_only` are **checked, not applied**: a value that agrees with the field is honoured as the no-op it is, and one that disagrees is `501`. Whether a field is editable belongs to the immutable schema. `url` fields do not exist here (D10), so their automatic read-only rule has nothing to apply to. |
| Lifecycle guard | "Cannot update after signing request has been sent, completed, or cancelled" → `400` | **intentionally different** | `FirmaLifecycleTest::test_a_sent_request_cannot_be_patched` | Same guard, enforced by the state machine rather than by the facade, and answered `409 invalid_state` rather than `400`: a state conflict is the one thing a client must not retry, and a `400` reads as "fix your body". |
| `200` response | `oneOf` of three shapes keyed to what was updated: properties → `{id, name, template_description, document_url, expiration_hours}`; recipient → full recipient object (schema body **empty**); field → full field object (schema body **empty**) | **intentionally different** | `FirmaLifecycleTest::test_a_field_is_patched_by_variable_name`, `::test_a_recipient_is_patched` | Two of the three variants are untyped upstream, so the document cannot pin them (D6). The facade returns the field exactly as `/fields` renders it, and the recipient exactly as `/users` does — the shapes a consumer of this profile already reads. |
| `warning` | "Response may include a `warning` field" | supported | idem | Singular here, `warnings` (plural array) on create; the difference is preserved rather than normalised (D7). Null unless there is something to say. |

### `POST /signing-requests/{id}/send`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Request body | none | supported | `FirmaLifecycleTest::test_send_emits_both_the_schema_and_the_example_key_sets` | |
| `200` response | Schema `SendSigningRequestResponse` = `{success, message, sentTo, sentAt}` — **camelCase, no required list**. The `example` in the same operation is `{message, signing_request_id, recipients_notified, sent_date, expires_at}` — **snake_case, disjoint keys** | **intentionally different** | idem | **Resolution of D8: the facade emits the union.** Every member of the schema and every member of the example, `message` shared. That is possible only because the two sets do not conflict — no key appears in both with a different meaning or type — so a client written against the schema and one written against the example both work. Picking one would have been wrong for half the clients with no basis for the choice; if upstream ever resolves its own contradiction, the surplus members are harmless rather than breaking. |
| `sentTo` / `recipients_notified` | not described beyond the member names | **intentionally different** | idem | The parties this send **released** an invitation to, read from recipient state, which the transition sets. Not from `invited_at`, which is stamped later when the mail is enqueued: a response cannot truthfully report work the queue has not done, and a caller asking who this call sent to means the release. For a sequential request that is the first stage and not everybody. |
| `400` | `Error`, examples: "Signing request has already been sent", "Signing request has expired" | **intentionally different** | `FirmaLifecycleTest::test_sending_twice_is_a_conflict` | `409 invalid_state` with `details.transition` and `details.from`, for the reason the PATCH lifecycle row gives. |
| Guard required values | Not expressed in the schema. `SigningRequestUser` carries `required_fields[]`, `missing_fields[]`, `required_read_only_fields[]`, `ready_to_send` | supported | `FirmaLifecycleTest::test_send_refuses_when_a_required_prefill_is_missing` | Send refuses when any party is not ready, and reports **every** reason at once — `422` with `details.problems` — rather than making a caller discover the next one after fixing the previous. `/users` reports the same readiness per recipient. |
| Snapshot immutability | Not expressed in the schema; implied by the PATCH lifecycle guard | supported | `FirmaLifecycleTest::test_a_sent_request_cannot_be_patched` | Enforced by the model regardless of upstream: `Envelope::SNAPSHOT_COLUMNS` refuses a dirty snapshot column, and an envelope copies its source at creation, so a template edited tomorrow cannot change an agreement already out for signature. |

### `POST /signing-requests/{id}/cancel`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Request body | Optional. `{reason (≤500), notify_signers (bool, default true)}` | **intentionally different** | `FirmaLifecycleTest::test_a_sent_request_is_cancelled_with_a_reason`, `::test_suppressing_the_cancellation_notice_is_not_implemented` | `reason` is supported and recorded on the envelope. `notify_signers: false` is `501`: which parties a withdrawal notice reaches is decided by `EnvelopeAudience` — everyone who was written to, nobody who was not — so suppressing it is not a caller's setting, and accepting `false` while mailing anyway would make the response untrue. `null` and `true` are the same instruction and both pass. |
| `200` response | `CancelSigningRequestResponse` — required `message`, `signing_request_id`, `cancelled_on`; optional `notify_signers`, `emails_sent` | supported | `FirmaLifecycleTest::test_a_sent_request_is_cancelled_with_a_reason` | All five emitted. `emails_sent` counts the parties already written to, from the same rule the mail scheduler applies, so the number in the response and the messages that go out cannot disagree — which means zero for a draft nobody was written to about. |
| Valid transitions | "Can only cancel requests that have been sent and are not already finished or cancelled" → `409` on violation | **intentionally different** | `FirmaLifecycleTest::test_cancelling_a_draft_succeeds` | **Cancelling a draft succeeds here.** Withdrawing a draft is a legal transition in this state machine, and refusing something this service can do in order to reproduce a limitation of somebody else's implementation would leave the caller holding a draft they asked us to withdraw. Nobody was written to, so `emails_sent` is 0. |
| Terminal-state behaviour | `409` when already cancelled/finished/not sent | supported | `FirmaLifecycleTest::test_cancelling_twice_is_a_conflict` | `409 invalid_state` for a request already cancelled, finished, declined, or expired. That one is a genuine conflict rather than a limitation. |
| Idempotency | Not expressed anywhere in the document | **intentionally different** | idem | Repeating a cancel yields `409`, as upstream does. The native idempotency key is a native extension and is not honoured on this surface; see the Idempotency row under Authentication. |

### `GET /signing-requests/{id}/users`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Envelope | `SigningRequestUserListResponse` = `{results: SigningRequestUser[]}`, `results` required. No pagination object. | supported | `users.json` × 4; `FirmaContractTest::test_the_users_response_has_the_recorded_key_set` | `results` and nothing else — no `{data, meta}` envelope from the native API. The array is in signing order, but a consumer must key on `id`: upstream specifies no order, so depending on ours would be depending on an accident. |
| Identity | `id` (uuid), `email`, `first_name`, `last_name`, `name` (combined), `designation` enum `Signer\|Approver\|CC`, `order` (integer, min 1) | **intentionally different** | idem | `first_name` and `last_name` are **null**: this product stores one display name, and splitting a person's name into parts is a guess about that person (`docs/HANDOFF.md` §2). The keys are present so a consumer reading them gets null rather than an undefined index, and `name` carries the whole thing. `designation` is the constant `Signer`. `id` is the recipient's public ULID, and it is the only handle a field or a signing session binds to — never the order, never the email (AGENTS.md). |
| Completion | `finished_on` (nullable `date-time`), `declined_on` (nullable), `decline_reason` (nullable) | supported | idem | From `SigningRequestPayload::recipient()`, the same builder the webhooks use. |
| Readiness | `required_fields[]` ("always includes `email` and `first_name`"), `missing_fields[]`, `required_read_only_fields[]` = `{variable_name, variable_defined_name, field_type, has_value}`, `ready_to_send` (bool) | **intentionally different** | `FirmaLifecycleTest::test_send_refuses_when_a_required_prefill_is_missing` | `required_fields` is `["email", "name"]` rather than `["email", "first_name"]`, because those are the two things this service actually refuses to send without and there is no `first_name` to require. `required_read_only_fields` lists the fields the **sender** owes this party with `has_value`, so a refused send needs no second call to diagnose. `ready_to_send` is the same gate the state machine applies. |
| Contact/profile | `phone_number`, `street_address`, `city`, `state_province`, `postal_code`, `country`, `title`, `company`, `custom_fields` — all nullable | **intentionally different** | `FirmaContractTest::test_the_users_response_has_the_recorded_key_set` | All null, `custom_fields` an empty object. This is a contact record the service does not keep: identity binds on the attestation, not on a stored address book. A field's `title` or `company` *value* is a field value and appears on `/fields`, where it belongs. |
| Required set | Document declares required: `id`, `first_name`, `email`, `designation`, `order` | **intentionally different** | idem | `first_name` is present and null; see Identity. Every other required member is populated. |

### `GET /signing-requests/{id}/fields`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| Envelope | `SigningRequestFieldListResponse` = `{results: SigningRequestField[]}` | supported | `fields.json` × 4; `FirmaContractTest::test_the_fields_response_has_the_recorded_key_set` | `results` and no pagination, as upstream. Fields are in field-schema order — the order they appear in the agreement — which is stable across reads. |
| Final values | `value` (nullable string, "Clean alias for `final_value`") and the deprecated `final_value` | supported | idem | Both emitted, identical, because both are read in the wild. Values come from `EnvelopeValueReader`, the same reader the native `/values` endpoint uses. `signing_date` is derived from the owning recipient's attestation instant and is never submitted by anyone. |
| Signature-value encoding | not specified by the document at all | **intentionally different** | `FirmaDownloadTest::test_signature_values_are_a_marker_until_images_are_asked_for`, `::test_an_unsigned_signature_field_has_a_null_value` | **Decided:** a signed signature or initials field reports the marker string `Recipient Signature` by default and the captured PNG data URL under `?include=images`; an unsigned one reports `null`. The recorded fixtures carry both forms, which is what makes the marker the right default rather than an invention. Opt-in, because an envelope carries one signature image per signer and a response that ships them unasked puts somebody's signature in the caller's request logs, proxy caches and error reports. |
| Position | `position` = `{x, y, width, height}`, "All values are percentages (0-100)" | supported | `FirmaContractTest::test_the_fields_response_has_the_recorded_key_set`, `FirmaCreateAndSendTest::test_percent_coordinates_land_on_the_declared_native_rectangle` | Percentages of the displayed page, converted from stored points by `FacadeCoordinateTranslator` under the profile's declared convention. The deprecated flat aliases `x_postion` **(sic)**, `y_position`, `width`, `heigh` **(sic)** are emitted with the same numbers, byte-identically to upstream, typos included (D9). The test asserts the two forms agree, and that neither typo appears on the create response — where upstream spells them correctly. |
| Corner positions | `tl_position`, `tr_position`, `bl_position`, `br_position` — bare nullable numbers, no units, no description beyond "Top-left corner position" | **intentionally different** | idem | **Null**, in every row of every workflow — which is also what the recorded fixtures show. There is no unit, no origin and no description to implement against, so there is no value that would be right; guessing one would misplace a corner by the width of a page. The keys are present so the key set matches. |
| Type | `type` enum (15 values incl. `radio_buttons`, `url`, `file`) plus deprecated `field_type` with the same enum | **intentionally different** | `FirmaContractTest::test_the_fields_response_has_the_recorded_key_set` | Both members emitted, identical. The facade emits only `signature`, `initial`, `text`, `date`, `checkbox` — the three the recorded responses actually carry plus the two more this product models. `name`, `company` and `title` are native types whose rendering is a line of text and are projected to `text`; the semantic handle survives in `variable_name`. Enum members of a document that is not vendored here are not invented (D10). |
| Recipient link | `recipient_id` plus deprecated `companies_workspaces_signing_requests_users_id`; also deprecated `companies_workspaces_signing_requests_id` | supported | idem | All three emitted. Ownership is by recipient id, never by signing order (`docs/HANDOFF.md` §2). |
| Variable identity | `variable_name`, `variable_defined_name` | **intentionally different** | `FirmaCreateAndSendTest::test_percent_coordinates_land_on_the_declared_native_rectangle` | `variable_name` is the caller's string verbatim — stored in the native schema's `label`, with a normalised copy in `alias`, because `alias` is an identifier and real consumer variable names contain spaces and slashes. `variable_defined_name` is null: the document describes no second name this product has. |
| Grouping / rules | `multi_group_id` (uuid), `format_rules`, `validation_rules`, `dropdown_options` (`array<string>` **or** `object`), `date_default`, `date_signing_default`, `page_number` | **intentionally different** | `FirmaContractTest::test_the_fields_response_has_the_recorded_key_set` | `page_number` and `date_signing_default` are real: the second is true for the one field type the service fills from a recipient's attestation instant. The rest describe features this build does not have and are emitted with the empty value of their own type — `dropdown_options` as `{}`, the others null — so the key set matches. Requesting any of them on input is refused rather than accepted and dropped. `deleted` is always `0`: nothing is ever soft-deleted from a copied field schema, which is immutable. |

### `GET /signing-requests/{id}/download`

| Surface | Upstream shape | Status | Fixture / test | Notes |
|---|---|---|---|---|
| `200` response | `SigningRequestDownloadResponse` — required `status`, `is_partial`, `download_url`, `expires_at`; optional `generated_at` | supported | `download.json` × 4; `FirmaContractTest::test_the_download_response_has_the_recorded_key_set` | All five emitted, key set asserted against all four workflows. |
| `status` | **String** enum `finished\|in_progress\|cancelled\|declined\|expired` | supported | idem | A *third* status representation, distinct from the create string enum and the detail boolean object. All three coexist by design. |
| `download_url` | Pre-signed URI that expires at `expires_at` | **intentionally different** | `FirmaDownloadTest::test_a_finished_request_serves_the_executed_pdf`, `::test_a_download_link_expires`, `::test_a_tampered_download_link_is_refused` | An app-issued URL whose signature covers the token and the expiry together, valid fifteen minutes rather than upstream's hour, naming one document of one signing request. Nothing is pre-signed on any driver and no disk name or object path appears in it (`docs/BLOB_STORAGE.md` rule 1); the bytes stream through the application. It is a capability, not an API key: it cannot list, patch, send, cancel, or reach a second agreement, and an edited one fails the signature. |
| Byte preservation | Not stated by the document | supported | `FirmaDownloadTest::test_a_finished_request_serves_the_executed_pdf`, `::test_a_sent_request_serves_the_reviewed_revision_as_partial` | Our own invariant is stronger, and asserted: what is served hashes to the digest recorded at publication for the executed PDF, and to the `document_revisions` digest for the reviewed revision. Neither is re-rendered to serve a download (AGENTS.md). |
| `is_partial` | Boolean; partial downloads gated on `allow_partial_download` in settings — a setting that **does not exist** in `SigningRequestSettings`. See D11. | supported | idem | **Decided: serve the partial snapshot and say so.** A finished agreement returns the sealed executed PDF with `is_partial: false`; anything else that has been sent returns the reviewed revision — the bytes every party was shown — with `is_partial: true`. That is what the recorded fixtures show upstream doing, and there is nothing to gate on, so the flag is simply reported truthfully rather than used to withhold a document the caller is entitled to. |
| `generated_at` | optional `date-time` | supported | idem | The artifact's publication instant when finished, and the reviewed revision's creation instant otherwise. |
| `409` | `{error: "no_document_available", message: "Signing request has not been sent yet"}` | supported | `FirmaDownloadTest::test_a_draft_has_no_document_available` | Verbatim token. A draft has been shown to nobody, so there is no document under signature to hand out — and answering with the upload would hand out bytes before anyone consented to see them. |
| `501` | not upstream | **intentionally different** | `FirmaDownloadTest::test_a_finished_request_with_no_artifact_is_not_implemented` | A finished request with no published artifact answers `501`, not `409`: telling a caller their finished agreement is unfinished would be false. Same distinction the native API draws, from the same locator. |
| `503` | `{error: "generation_timeout"}` or `{error: "stale_at_publication"}` with `Retry-After` seconds | unsupported | | Not emitted. There is no asynchronous generation behind this route to time out: a request is `finished` only once its artifact is durably stored and retrievable, so the state that would produce a `503` cannot exist. A finalization still running leaves the request unfinished, which `status` reports honestly. |

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
| `signing_request.viewed` | idem | unsupported | | Not published. A view is not assent and `GET` must stay harmless (`AGENTS.md`): a mail scanner opening a signing link would otherwise emit an event a receiver could read as engagement. The name is accepted by the outbox so a later, deliberately-recorded view event needs no vocabulary change. |
| `signing_request.updated` | idem | unsupported | | Not published. A prefill or a contact correction on a draft changes neither the agreement nor anyone's position in it, and no transition records one; publishing an event for it would tell a receiver something happened to an agreement nobody has seen yet. The polling endpoint's `timestamps.last_changed_on` reports the change. |
| `signing_request.deleted` | "deleted (before sending)" | unsupported | | Nothing is deleted. A draft is withdrawn (`signing_request.cancelled`), which keeps the record of what was prepared; `DELETE /signing-requests/{id}` is out of profile. An event named `deleted` for a row that still exists would be false. |
| `signing_request.completed` | "All recipients finished signing" | **intentionally different** | `DeliveryEnvelopeEventSinkTest::test_completion_is_recorded_once_the_artifact_reference_exists` | Upstream ties this to signing completion. We publish it only after the final PDF is generated, validated, durably stored and retrievable (`AGENTS.md`, `docs/HANDOFF.md` §11) — from `markCompleted()` and nowhere else. Later than upstream, deliberately. |
| `signing_request.certificate.generated` | "Signing certificate generated" | unsupported | | Not published, and deliberately not merged into `completed` either (`docs/HANDOFF.md` §11). The completion report is published in the same transaction that completes the request, so the two moments this event distinguishes are the same moment here; emitting it would be a second name for one fact. `SigningRequestDetail.certificate.generated` reports the state, and `GET /signing-requests/{id}` is where a poller reads it. |
| `signing_request.cancelled` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_a_cancellation_reaches_everybody_who_had_been_written_to` | Mail reaches recipients carrying an `invited_at`; a later signer who was never written to is not told an agreement they never saw was withdrawn. |
| `signing_request.expired` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_an_expiry_reaches_everybody_who_had_been_written_to`, `SigningScheduleCommandsTest` | Applied by `esign:signing:expire`; the state machine re-checks the deadline under a lock, so the command cannot expire anything early. |
| `signing_request.reminder.sent` | idem | unsupported | | `esign:signing:remind` sends the mail and records no event. The name is accepted by the outbox and nothing publishes it, which is the honest state: a receiver subscribing to it would wait forever rather than be told so. Reminder scheduling is also not configurable per request (`reminders[]` is `501`). |
| `signing_request.recipient.signed` | `data.recipients[]` | supported | `DeliveryEnvelopeEventSinkTest::test_an_acceptance_is_a_recipient_event_and_never_a_completion` | Does not imply full execution (`docs/HANDOFF.md` §11). `data.recipients` carries the one party who signed, with their `finished_on`; `status.finished` stays false. |
| `signing_request.recipient.declined` | idem | supported | `DeliveryEnvelopeEventSinkTest::test_a_decline_records_both_names_and_writes_only_to_the_sender` | There is **no** `signing_request.declined` event, even though `SigningRequestDetail.status.declined` and `timestamps.declined_on` exist. See D12. We emit this one and our own `esign.envelope.declined` beside it, and never invent the upstream name. |
| `signing_request.recipient.identity_changed` | idem | unsupported | | A recipient cannot rewrite their own identity here — who signed is recorded in their attestation — so there is no change to announce. `settings.identity_editable_fields` is `501` for the same reason, and `settings.notify_identity_change_email` reports `false`. A sender correcting a draft recipient's contact details is a different thing and is not this event. |
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

Recorded in Stage 0, **resolved here**. Each entry keeps the original description of the
disagreement — that is the fact about the upstream document and does not change — and gains a
"Resolved" paragraph stating what this facade does and why. Nothing below is left open.

**D1 — Error envelope is not uniform.** `Error` uses `{error, message, details}`;
`CreateAndSendValidationError` and `ResendConflictError` use `{error, code, …}` with `code`
required and no `message`. A single serializer cannot produce both. Both shapes must be kept
per route (`docs/HANDOFF.md` §10).

**Resolved:** both shapes are kept, per route. `FirmaException` records which envelope it owes and renders that one; `create-and-send` is the only route on the `code`/`phase`/`validation_errors` shape.

**D2 — `PatchSigningRequestBodySchema` is the create body.** It is referenced only from
`POST /signing-requests` (via `requestBodies/PatchSigningRequestBody`). The actual PATCH body
is an inline `oneOf` on the route. The name is upstream's; do not copy it into our code.

**Resolved:** the name is not copied. The create body is read by `StoreSigningRequestRequest` and the PATCH body by `PatchSigningRequestRequest`, each named for what it is.

**D3 — `Recipient.order` required or not.** The `Recipient` description says "ALL recipients
MUST have an explicit order value", but `order` is absent from the schema's `required` array
(only `first_name`, `email`, `designation` are listed). Fail closed: require it, and reject a
recipient without one with a clear message.

**Resolved:** required. A recipient without one is a `400` naming its 1-based index (`FirmaCreateAndSendTest::test_a_recipient_without_an_order_is_refused`).

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

**Resolved:** the schema wins and the examples are treated as upstream errors. The profile declares `percent_of_page_top_left`; out-of-range values are a `400` saying so. `FirmaCreateAndSendTest` places a field at `{x: 10, y: 20}` and asserts it lands on 61.2 pt / 158.4 pt of the fixture's own 612 × 792 page, and feeds the upstream example's own `{x: 100, y: 500}` in to assert it is refused.

**D5 — Settings are booleans in one place and 0/1 integers in another.**
`SigningRequestSettings` types `allow_download`, `attach_pdf_on_finish`,
`hand_drawn_only`, `use_signing_order`, `allow_editing_before_sending` as booleans;
`SigningRequestDetail` repeats all five at the top level as `integer` enum `[0, 1]`, marked
deprecated. Emit both, each with its own type.

**Resolved:** both emitted, each with its own type, and the types are asserted separately (`FirmaContractTest::test_the_polling_response_has_the_recorded_key_set`).

**D6 — Two of three PATCH response variants are untyped.** The `oneOf` entries for the
recipient and field cases are bare `{"type": "object"}` with only a description. The document
cannot pin those responses; only a fixture can.

**Resolved:** the facade returns the field exactly as `/fields` renders it and the recipient exactly as `/users` does, plus the singular `warning`. Those are the shapes a consumer of this profile already reads; inventing a third would be inventing a contract.

**D7 — `warnings` (array, on create) versus `warning` (singular, on PATCH).** Both are
described as email-format validation warnings. Preserve the difference.

**Resolved:** preserved. `warnings: []` on create, `warning: null` on PATCH.

**D8 — `POST /signing-requests/{id}/send`: schema and example are disjoint.** Schema
`SendSigningRequestResponse` = `{success, message, sentTo, sentAt}` (camelCase, nothing
required); the operation's own example = `{message, signing_request_id, recipients_notified,
sent_date, expires_at}` (snake_case). They share only `message`. This is the sharpest
disagreement in the document and blocks a tested contract for `/send` until a fixture settles
it. Whichever we ship must be stated explicitly, not chosen silently.

**Resolved: emit the union.** All four schema members and all five example members, `message` shared. The two sets share no key with a different meaning or type, so satisfying one does not break the other — which is the only reason this resolution is available. Asserted in both directions by `FirmaLifecycleTest::test_send_emits_both_the_schema_and_the_example_key_sets`.

**D9 — Upstream typos are part of the contract.** `SigningRequestField` exposes deprecated
`x_postion` (missing "i") and `heigh` (missing "t"), described as aliases of `position.x` and
`position.height`. `SigningRequestCreateField` — a different schema, on the create response —
spells the same concept `x_position` / `height` correctly. Reproduce each shape exactly where
it appears; do not normalise the typos away, and do not propagate them into native code.

**Resolved:** reproduced exactly where they appear and nowhere else. `/fields` emits `x_postion` and `heigh`; the create response emits `x_position` and `height`. Both are asserted, including the absence of the typos from the create response. Neither spelling reaches native code.

**D10 — Five different field-type enums.** `Field` (create/PUT body) has `image`, `initials`,
`textarea` but lacks `file`, `radio_buttons`, `url`. `SigningRequestField` (read) has `file`,
`radio_buttons`, `url` but lacks `image`, `initials`, `textarea`. The inline PATCH `field`
enum is the union of those two minus `image`. `SigningRequestCreateField` (create response)
uniquely includes `number`. `AnchorTag` uniquely includes `radio`. Type normalisation
(`initials`→`initial`, `textarea`→`text_area`) is documented only on `Field` and the PATCH
body. The profile needs one internal type set plus explicit per-surface projections, and an
unsupported type must error rather than round-trip.

**Resolved:** one internal type set (`FieldType`) with explicit per-surface projections in `FirmaFieldType`. Inbound spellings normalise; a declared-but-unimplemented type is `501` naming it. Outbound, only the five values the recorded responses and this product's own model justify are emitted — enum members of a document that is not vendored here are not invented.

**D11 — `allow_partial_download` is documented but does not exist.** The `/download` route
prose gates partial downloads on `allow_partial_download` "in settings";
`SigningRequestSettings` has no such property. The closest real property is
`allow_presigning_download`, which means something else ("allow signers to download the
original document before signing"). Partial-download support is therefore unspecified.

**Resolved: serve the partial snapshot and flag it.** There is no setting to gate on, so `is_partial` reports the truth about which of the two retained documents is being served rather than being used to withhold one. That is also what the recorded fixtures show upstream doing.

**D12 — No request-level `declined` event.** `SigningRequestDetail.status.declined` and
`timestamps.declined_on` exist, and `/download` accepts `declined` as a terminal status, but
the event list only has `signing_request.recipient.declined`. Do not manufacture a
`signing_request.declined` event to fill the gap (`docs/HANDOFF.md` §11).

**Resolved:** unchanged from Stage 0. We emit `signing_request.recipient.declined` and our own `esign.envelope.declined` beside it, and never manufacture the upstream name.

**D13 — Webhook event identity: `id` versus `event_id`.** The webhook guide's envelope and
its idempotency example both use `id`. The consumer's webhook controller resolves `event_id`
first and falls back to a payload hash (`docs/HANDOFF.md` §2, R2). The response body of
`POST /webhooks/{id}/test` uses a third name, `webhook_event_id`, for the event record. Our
facade emits `id`; the consumer must normalise both names explicitly, and the inbox must key
on a unique provider-scoped identity so a duplicate-insert race cannot turn an unprocessed
event into an acknowledged loss.

**Resolved:** unchanged from Stage 0. The facade emits `id`, plus the `X-Esign-Event-Id` header so a receiver can deduplicate from the headers alone.

**D14 — Webhook health fields promised in prose are missing from the schema.** The guide says
a webhook GET returns `last_failure_at` and `last_success_at`; the `Webhook` schema has
neither (only `consecutive_failures`, `auto_disabled_at`, `enabled`, timestamps).

**Resolved:** unchanged from Stage 0. `webhook_deliveries` records strictly more than the two timestamps the prose promises, and no HTTP `Webhook` resource is exposed.

**D15 — The unversioned spec URL is not the pinned one.**
`https://docs.firma.dev/api-reference/openapi.json` resolves but served a different, smaller
document (31 paths) than the pinned v01.35.00 file (72 paths) at retrieval time. Anyone
re-deriving this matrix must use the versioned URL in `tests/Fixtures/firma/SCHEMA.md`.

**Resolved:** unchanged. Anyone re-deriving this matrix uses the versioned URL in `tests/Fixtures/firma/SCHEMA.md`; `scripts/fetch-firma-schema.sh` verifies the digest.

## Out of profile

Present in the pinned document, deliberately **unsupported** in `firma-compat-v1`, and
required to return a clear error rather than a no-op success: `/company*`, `/workspaces*`,
`/templates*`, `/documents`, `/webhooks*` administration, `/generate-template-token`,
`/revoke-template-token`, `/jwt/*`, `/*/custom-fields*`, `/*/email-templates*`,
`/*/signer-terms*`, `/*/domains*`, `/*/logo`, `/signing-requests` (list), `PUT` and `DELETE`
on `/signing-requests/{id}`, `/signing-requests/{id}/reminders`,
`/signing-requests/{id}/audit`, `/signing-requests/{id}/resend`, and
`/signing-requests/{id}/signers/{signer_id}/{signature,initials,stamps,files}`.

Each answers `501` with a body naming the method and path, asserted by
`tests/Feature/Integration/Firma/FirmaAuthTest::test_a_route_outside_the_profile_is_not_implemented`
for both a `POST` to an out-of-profile signing-request sub-route and a `GET` to an
out-of-profile family. Not `404`: that would claim an endpoint the upstream contract really
declares does not exist, and would send an integrator hunting for a typo instead of reading
this document. Not a plausible `200` either — on `/signing-requests/{id}/resend` that would
report that a signer had been written to when nobody had.

Expanding into any of these families is a deliberate profile change: add the rows here, with
shapes read from the pinned document, before writing the adapter.

## Recorded fixtures

`tests/Fixtures/firma/firma-compat-v1/<workflow>/{request,users,fields,download}.json` hold
sanitized live responses (2026-09-08) for the four consumer workflows in four different states
(sent, finished, cancelled, finished single-recipient).
`tests/Feature/Compatibility/FirmaFixtureShapeTest.php` pins the shapes the consumer depends on
in the fixtures themselves.

`tests/Feature/Integration/Firma/FirmaContractTest.php` is the other half: for each workflow it
drives an envelope through the **real state machine** to the state that fixture captures, then
asserts the facade's four polling responses carry exactly the fixture's key set — every key, no
extras, at every level of nesting — and satisfy the same structural assertions
`FirmaFixtureShapeTest` makes. Rows for `GET /signing-requests/{id}`, `/users`, `/fields` and
`/download` are therefore fixture-backed in both directions.

`create`, `create-and-send`, `send`, `patch` and `cancel` have **no captured fixture**: the
recording pass was read-only, by design, so nothing in the consumer's staging workspace was
created or sent. Their shapes are derived from the consumer's client code and the pinned
reference, and are asserted against tests rather than recordings
(`FirmaLifecycleTest`, `FirmaCreateAndSendTest`, `FirmaAuthTest`, `FirmaDownloadTest`). Where
that leaves a genuine choice — `/send`'s two disjoint response shapes, most of all — the choice
is stated in the row and in the disagreement, never made silently.

