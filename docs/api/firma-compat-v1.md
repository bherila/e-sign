# The Firma-compatible facade (`/functions/v1/signing-request-api`)

A second HTTP surface that speaks somebody else's contract, so that a client already written
against the Firma Partner API can point at this application and keep working. It and the
native API ([`native-v1.md`](native-v1.md)) call the same domain services; there is one
signing state machine and neither surface holds signing rules
([`docs/ARCHITECTURE.md`](../ARCHITECTURE.md)).

Implements issue #33. Acceptance profile **`firma-compat-v1`**.

| | |
|---|---|
| Base path | `/functions/v1/signing-request-api` — upstream's `servers[0]`, verbatim |
| Profile contract | [`docs/compatibility/firma-capability-matrix.md`](../compatibility/firma-capability-matrix.md) — every route, field, status and event, with a final status and a test reference |
| Upstream reference | Firma Partner API v1.35.0, pinned in [`tests/Fixtures/firma/SCHEMA.md`](../../tests/Fixtures/firma/SCHEMA.md) |
| Recorded fixtures | `tests/Fixtures/firma/firma-compat-v1/` — four consumer workflows × four endpoints |
| Routes | `routes/compat-firma.php`, mounted by `App\Providers\IntegrationServiceProvider` |
| HTTP | `app/Http/Controllers/Compat/Firma`, `app/Http/Requests/Compat/Firma`, `app/Http/Resources/Compat/Firma`, `app/Http/Middleware/Compat` |
| Services | `app/Domain/Integration/Firma` |
| Tests | `tests/Feature/Integration/Firma` |

---

## Read this first: what is and is not impersonated

This facade reproduces **an HTTP API**. It does not reproduce a company.

**Not impersonated, and never will be:**

- **The hosted website and its DNS.** Nothing here answers as `app.firma.dev` or
  `api.firma.dev`. You point your client's base URL at this deployment.
- **The signing UI.** Signers land on this application's own pages, served locally and
  visibly branded as this product. A client that constructs
  `https://app.firma.dev/signing/{recipientId}` itself is building a link to somebody else's
  website; read `first_signer.signing_link` from the response instead. The *path shape* is
  the same — `/signing/{recipientId}` on this host — so the change is the base URL, but it is
  the one change a migrating consumer *must* make.
- **The JavaScript SDK and the hosted field editor.** Placing fields on a rendered PDF is
  this application's own editor. There is no drop-in replacement for a third-party SDK
  bundle, and a compatibility shim that loaded one would be loading their code.
- **Billing.** There is no credit ledger. `credit_cost` and `credits_remaining` are `null`
  rather than invented numbers, and the `402 InsufficientCreditsError` path does not exist.
- **Private implementation.** Ids, internal enum members we have not observed, undocumented
  members, and anything the pinned document does not describe.

**Reproduced:** the nine routes below, their request bodies, their response key sets
(asserted against recorded responses), their status representations, their error envelopes,
the raw-key `Authorization` syntax, and the webhook envelope and signature scheme
([`docs/delivery/webhooks.md`](../delivery/webhooks.md)).

**Out of profile:** everything else the upstream document declares — templates, workspaces,
documents, webhook administration, JWT and template tokens, custom fields, email templates,
signer terms, domains, logos, the signing-request *list*, `PUT`/`DELETE` on a signing
request, reminders, audit, resend, and the per-signer signature/initials/stamp/file routes.
Those answer **`501`** naming the path, never `404` and never a plausible empty success. The
full list is in the matrix's "Out of profile" section.

---

## Authentication

Your API key in `Authorization`, **with or without a scheme**:

```http
Authorization: esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
Authorization: Bearer esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s
```

The scheme-less form is the point. `docs/HANDOFF.md` §10 makes accepting it a requirement,
because the client this profile exists for sends it that way; rejecting it would mean either
patching every consumer or shipping a facade that is not compatible. There is no ambiguity:
a key always starts `esk_` and a bearer token never does.

Credentials are issued, rotated and revoked from the console
([`docs/operations/service-credentials.md`](../operations/service-credentials.md)). There is
no self-service route and no way to read a secret back.

### Scopes

Two gates, and a caller passes both.

| Scope | What it grants |
|---|---|
| `compat:firma-v1` | **Admission to this surface.** Required on every route here. Grants no resource authority of its own. |
| `envelopes:read` | `GET /signing-requests/{id}`, `/users`, `/fields`, `/download` |
| `envelopes:write` | `POST /signing-requests`, `/create-and-send`, `PATCH`, `/send`, `/cancel` |
| `templates:read` | additionally required by `/create-and-send` |

**Nothing is implied.** A credential issued for `/api/v1` does not gain a second HTTP surface
by accident, and a credential admitted here still needs `envelopes:read` to read an
agreement. Missing `compat:firma-v1` is `403` with `details.required_scope`, as is a missing
resource scope.

### Tenancy

**There is no workspace in any path and none accepted in any body.** The credential belongs
to exactly one workspace, and that is the tenant for every lookup — constrained *before* the
`{id}` from the URL is used. The visible consequence: another tenant's id answers **`404`**,
exactly like an id that does not exist anywhere. A `403` would confirm the id exists, and one
forgotten comparison is a cross-tenant read (`docs/HANDOFF.md` §10).

### No idempotency keys here

`Idempotency-Key` is honoured on `/api/v1` and deliberately **not** on this surface. Upstream
has no such header, and adding one would mean your retry behaved differently against the two
surfaces for reasons your own contract does not describe. A repeated `cancel` is still `409`.

---

## Identifiers

A signing request's `id` is this application's **public ULID**, not a UUID:

```
01JC3QK8ZP4W7RG5N2YB6TQXVD
```

That is an intentional difference, recorded in the matrix. Minting a parallel identifier
space so a compatibility surface could show UUIDs would mean two ids for one agreement, a
mapping table to keep them in step, and a support conversation every time they disagreed.
**Treat the id as opaque** — which is already necessary: the recorded fixtures include an id
the consumer had stored with an `sr_` prefix that turned out never to have been a provider id
at all, and every endpoint answers `404` for it.

`template_id` on create accepts **either** namespace:

1. this application's `templates.public_id`, or
2. an **imported alias** — the provider template id you already have hardcoded, recorded in
   `template_aliases`.

The native id is tried first. `docs/HANDOFF.md` §6 keeps the two in separate fields, which is
what lets both work without either ever becoming the other. Add an alias with
`POST /templates/{template}/aliases` on the workspace UI.

---

## Errors

The upstream envelope, on every route:

```json
{
  "error": "invalid_state",
  "message": "Cannot send an envelope in state \"sent\".",
  "details": { "transition": "send", "from": "sent" }
}
```

`error` is a short machine token — switch on it — and `message` is the sentence. `details`
appears only when there is something structured to say. This is **not** the native API's
`{"error": {"code", …}}`; neither surface inherits the other's shape
(`docs/HANDOFF.md` §10).

| `error` | Status | Means |
|---|---|---|
| `invalid_request` | 400 | The body, a coordinate, a type, or an override could not be used |
| `unauthorized` | 401 | No key, or one that is unknown, revoked, or expired |
| `forbidden` | 403 | Valid key, missing scope (`details.required_scope`), or an expired/edited download link |
| `not_found` | 404 | No such signing request **in your workspace** — identical to one that does not exist |
| `invalid_state` | 409 | The move is illegal from this state (`details.transition`, `details.from`) |
| `no_document_available` | 409 | Asked for a document before the request was sent |
| `unprocessable_entity` | 422 | Not sendable, or a template with no published version (`details.problems`) |
| `validation_failed` | 422 | `create-and-send` two-phase validation; see below |
| `unsupported` | 501 | Declared upstream, not implemented here (`details.unsupported_option`) |
| `internal_error` | 500 | Something unanticipated; the detail is in the server's log, not your body |

`create-and-send` has its **own** envelope, because upstream's document does not use one
(matrix disagreement D1):

```json
{
  "error": "validation_failed",
  "code": "validation_failed",
  "phase": "send_validation",
  "validation_errors": [
    { "recipient_index": 1, "recipient_email": "dana@buyer.example", "missing_fields": ["agreement_date"] }
  ]
}
```

`phase` is `create_validation` (nothing was persisted) or `send_validation` (the request was
built and the send gate refused it).

**Nothing leaks.** No body carries a stack trace, a file path, SQL, or the class name of an
unexpected failure.

### `501` always names the option

`docs/HANDOFF.md` §10: never advertise a successful no-op for an unsupported route or
required option. So every `501` says which instruction was refused:

```json
{
  "error": "unsupported",
  "message": "Signature capture in this build accepts a typed or a drawn signature; there is no drawn-only mode. …",
  "details": { "unsupported_option": "settings.hand_drawn_only", "profile": "firma-compat-v1" }
}
```

Refused options, and why each one:

| Option | Why |
|---|---|
| `settings.hand_drawn_only: true` | The template's `signature_appearance` records a *preference* and **nothing in the signing flow enforces it** — a typed signature is accepted whatever it says. Recording the option would promise an assurance nothing keeps. |
| `settings.allow_editing_before_sending: true` | A request's document and field schema are one immutable snapshot; a correction is a new request with renewed signatures. |
| `settings.attach_pdf_on_finish: true` | The completion email carries an authorized link, not the bytes. |
| `settings.identity_editable_fields` | Who signed is recorded in their attestation; an editable identity field would let that record change after the fact. |
| `notify_signers: false` on cancel | Which parties a withdrawal notice reaches is decided by the service — everyone written to, nobody who was not. Accepting `false` and mailing anyway would make the response untrue. |
| `reminders[]` | Reminders are sent on the service's own schedule. |
| `anchor_tags[]` | Anchors are supplied per field; see [Anchors](#anchors). |
| `language`, `completion_title`, `completion_message`, `completion_redirect_url`, `completion_redirect_delay` | The signing and completion pages are this application's own; supplied copy would not be rendered, and there is no redirect to a third-party URL. |
| `recipients[].designation` other than `Signer` | An approver would have to be treated as a signer — binding them to an agreement they meant to review — or dropped. |
| `fields[].type` in `dropdown`, `file`, `image`, `number`, `radio`, `radio_buttons`, `stamp`, `url` | Declared upstream, not placeable here. Never coerced into a text box: a field a signer was never asked to complete is worse than a rejected request. |
| PATCH properties (`name`, `description`, `expiration_hours`) | The title is part of the immutable snapshot. |
| PATCH `field.prefilled_editable` / `field.read_only` that **changes** the field | Editability belongs to the copied schema. A value that agrees with the field is honoured as the no-op it is. |
| `document_id` on create | `POST /documents` is out of profile, so you cannot obtain one here; accepting the field would accept an id we never issued. |

Sending `hand_drawn_only: false`, or `null`, is not a request for anything and passes.

---

## Coordinates

**The profile's `position` is a percentage of the page. That is a declaration, not a
detection.**

```json
{ "page_number": 1, "position": { "x": 10, "y": 20, "width": 30, "height": 5 } }
```

On a 612 × 792 pt page that is 61.2 pt from the left and 158.4 pt from the top, with the
top-left corner as the origin and `y` growing downwards
([`docs/preparation/coordinate-space.md`](../preparation/coordinate-space.md)).

Four consequences worth knowing:

1. **Percentages are of the *displayed* page.** A `/Rotate 90` Letter page is 792 pt wide as
   displayed, so 50% of `x` is 396 pt and not 306 pt.
2. **Out of range is refused, not reinterpreted.** `{"x": 100, "y": 500}` — which is the
   upstream document's own example, and violates the schema it illustrates — is a `400`
   saying so. The unit is never inferred from a number's magnitude: `{"x": 60}` is 60% here
   and 60 pt under a different profile, and the two are indistinguishable without the
   declaration (`AGENTS.md`, matrix disagreement D4).
3. **`x + width <= 100` and `y + height <= 100`**, as upstream's schema requires. A field
   that runs off the page is a `400`.
4. **`/fields` converts back**, so a `GET` returns the percentages you sent.

### Anchors

`fields[].anchor` places a field relative to text in the document:

```json
{
  "type": "signature",
  "page_number": 1,
  "anchor": { "text": "Signature:", "occurrence": "sole", "origin": "top_left", "offset_y": 1.77 },
  "position": { "x": 0, "y": 0, "width": 27.8, "height": 4.5 }
}
```

This is a **documented compatibility extension**, not upstream's shape. Upstream places
anchors through a separate `anchor_tags[]` collection with its own type enum and its own
`offset_units: percent|pixels`; that collection is `501`, because reproducing a second
coordinate system with a second unit switch is how a signature ends up in the wrong place.
Here there is one unit — the profile's own, percentages of the page.

- `text` is located by a real PDF content-stream parse, not a regex over the file.
- `occurrence` is `"sole"` (the text appears exactly once) or a 1-based number. `"all"` is
  refused: one field cannot be in two places.
- `origin` is `top_left` (default), `top_right`, `bottom_left`, or `bottom_right`.
- `offset_x` / `offset_y` are percentages of the page, added to the chosen corner.
- `position.width` and `position.height` are **still required**. An anchor says where a field
  goes and never how big it is.
- An anchor that matches nothing, or matches ambiguously, is a `400`. A field placed at a
  fallback position is a field nobody agreed to sign there.

---

## Endpoints

| Method | Path | Scope | Notes |
|---|---|---|---|
| `POST` | `/signing-requests` | `envelopes:write` | A draft, from a `template_id` or an inline `document` |
| `POST` | `/signing-requests/create-and-send` | `envelopes:write` + `templates:read` | Create and release in one call |
| `GET` | `/signing-requests/{id}` | `envelopes:read` | The polling endpoint |
| `PATCH` | `/signing-requests/{id}` | `envelopes:write` | One `field` or one `recipient`, drafts only |
| `POST` | `/signing-requests/{id}/send` | `envelopes:write` | Issues the invitations |
| `POST` | `/signing-requests/{id}/cancel` | `envelopes:write` | Withdraws it, with a reason |
| `GET` | `/signing-requests/{id}/users` | `envelopes:read` | `{results: […]}` |
| `GET` | `/signing-requests/{id}/fields` | `envelopes:read` | `{results: […]}`, `?include=images` |
| `GET` | `/signing-requests/{id}/download` | `envelopes:read` | JSON with a short-lived `download_url` |
| `GET` | `/downloads/{token}` | — | The bytes. Signed URL, no API key |

### Three status representations, and why none is collapsed

This is the single most surprising thing about the profile, and it is faithful:

| Route | `status` |
|---|---|
| `POST /signing-requests` | the **string** `"draft"` |
| `POST /signing-requests/create-and-send` | the **string** `"sent"` |
| `GET /signing-requests/{id}` | an **object of booleans**: `{sent, finished, cancelled, declined, expired}` |
| `GET /signing-requests/{id}/download` | a **string enum**: `finished\|in_progress\|cancelled\|declined\|expired` |

All four coexist upstream by design, and `docs/HANDOFF.md` §10 requires preserving them
rather than forcing one convenient serializer on every route. On the polling endpoint
**several flags can be true at once**: a cancelled request that had been sent reports both,
which a single enum cast could not.

Two timestamp spellings coexist for the same reason: `_date` on the create response
(`created_date`, `sent_date`, …) and `_on` on the polling response (`created_on`, `sent_on`,
…).

### Creating a draft

```http
POST /functions/v1/signing-request-api/signing-requests
Authorization: esk_…

{
  "template_id": "01JC3QK8ZP4W7RG5N2YB6TQXVD",
  "name": "NDA — Acme Ltd",
  "expiration_hours": 72,
  "recipients": [
    { "template_user_id": "buyer",  "first_name": "Dana", "last_name": "Buyer",  "email": "dana@buyer.example",  "designation": "Signer", "order": 1 },
    { "template_user_id": "seller", "first_name": "Sam",  "last_name": "Seller", "email": "sam@seller.example",   "designation": "Signer", "order": 2 }
  ],
  "fields": [
    { "variable_name": "agreement_date", "read_only_value": "2026-02-01" }
  ]
}
```

`order` is **required** on every recipient. Upstream's prose says so and its schema does not
(matrix D3); the facade fails closed, because an implied order is an implied signing
sequence. A template's own order and designations are never taken from the request — only the
contact details are overridable.

`recipients[].template_user_id` is this application's schema recipient id; `order` also
resolves, when it names exactly one party of the template's signing sequence.

**`fields[]` means two different things**, decided by the source:

- **From a template** it is a set of **prefills**. Each entry names a field by
  `variable_name`, `id` or `template_field_id`, and supplies `read_only_value`, `final_value`
  or `value`.
- **From a `document`** it **places** fields, and may prefill them in the same entry.

An override that matches no field is a **`400`**, not silently ignored. Upstream ignores one;
a dropped prefill is a blank in an executed agreement nobody notices until a counterparty
asks about it. A `variable_name` matching two fields is also an error — the recorded fixtures
show `full_name` twice on one agreement — so address it by `id`.

A draft **copies** its source. A template edited tomorrow cannot change an agreement already
out for signature. Creation does not send.

### Creating and sending in one call

```http
POST /functions/v1/signing-request-api/signing-requests/create-and-send

{
  "name": "Mutual NDA",
  "document": "JVBERi0xLjcK…",
  "recipients": [ { "first_name": "Dana", "email": "dana@buyer.example", "order": 1 } ],
  "fields": [
    { "type": "signature", "page_number": 1, "recipient_email": "dana@buyer.example",
      "position": { "x": 10, "y": 80, "width": 25, "height": 4 } }
  ]
}
```

`document` is base64, up to ~4.5 MB decoded (a `data:application/pdf;base64,` prefix is
accepted). The bytes go through the **same document intake the upload UI uses**: staged,
hashed, really parsed by the preflight parser, written to storage and read back, and recorded
with their report. An encrypted, already-signed, or structurally unsupported PDF is a `400`
carrying the parser's findings, **before any signing request exists** — so this call cannot
leave a half-built draft behind. The original is retained byte-for-byte and never
re-rendered.

A field names its owner by `recipient_id` (a temporary id), `recipient_email`, or `order`.
With more than one recipient, a field that names none is a `400`: ownership is never assigned
by elimination or by signing position. With exactly one recipient there is nothing to choose
between.

The response carries `first_signer.signing_link` — **read this instead of building a URL**.

That link is this application's stable `/signing/{recipientId}` resolver, and deliberately
**not** a freshly minted invitation. A recipient has at most one live invitation and issuing
another revokes the previous one, which is what makes "resend the link" mean something; a
credential minted for this response would therefore either kill the link the invitation email
is about to carry or be killed by it, depending on when the queue ran. The resolver authorizes
nothing on its own: it reaches a form, and stating the address the invitation went to plus
reading a code delivered there is what turns it into a session. `docs/HANDOFF.md` §8 refuses
"possession of the recipient id is the authorization" outright and names exactly this resolver
as the way to keep the URL shape without it.

### `settings.require_otp_verification`

Honoured. It is written to the request itself, and **null is not false**: saying nothing leaves
the request inheriting its workspace's setting and then the deployment default, while an
explicit `false` overrules both. `GET /signing-requests/{id}` reports the *resolved* value, so
a caller that set nothing still sees what its guests will be asked for.

The code is a second check on the **same** factor — continued access to the mailbox the link
went to — not a second factor, and not an eIDAS advanced or qualified signature.

### The polling response

`GET /signing-requests/{id}` is the endpoint a consumer loops on. Its full key set is
asserted against all four recorded workflows. The parts worth calling out:

- **`status`** — the boolean object above, built by the same projection every
  `signing_request.*` webhook carries, so a polling receiver and a subscribing one cannot be
  told two different stories.
- **`timestamps.finished_on`** is *completion* — a validated final PDF durably stored and
  retrievable — not the last signature. Those two are further apart here than upstream;
  `timestamps.last_signing_action_on` is where the last signature shows up.
- **`certificate`** maps onto the **completion report**: a statement of what happened, not an
  X.509 certificate and not a credential belonging to any signer. `generated` becomes true in
  the transaction that completes the request, so never before the evidence is retrievable.
- **`settings`** carries all fourteen upstream members. Three are real
  (`use_signing_order`, `allow_download`, and `require_otp_verification`, which reports the
  resolved requirement); the other eleven are `false` or `null` with a documented reason
  each, because a plausible value for something this product does not model is worse than an
  honest absence. The same five settings also appear at the top level as deprecated `0`/`1`
  integers — both forms, each with the right type in its own place (matrix D5).
- **`credit_cost`** is `null`. There is no credit system; omitting the key would break a
  consumer that reads it, and a number would invent a billing fact.
- **The three `*_download_url` members** are null until the agreement is executed, then
  app-issued links (below). `*_download_error` stays null.

### `/users`

`{results: […]}`, no pagination — upstream has none here, and the native API's `{data, meta}`
envelope is not used on this surface. **Key on `id`, never on array position**: upstream
specifies no order for this array.

- `first_name` and `last_name` are **null**. This product stores one display name, and
  splitting a person's name into parts is a guess about that person; `name` carries the whole
  thing. The keys are present so reading them gives null rather than an undefined index.
- `designation` is always `Signer`.
- The contact block (`phone_number`, `street_address`, …, `custom_fields`) is null. This is a
  contact record the service does not keep — identity binds on the attestation, not on a
  stored address book. A field's `title` or `company` *value* is a field value and appears on
  `/fields`.
- `required_fields` is `["email", "name"]`, not `["email", "first_name"]`: those are the two
  things this service actually refuses to send without.
- `required_read_only_fields[]` lists the fields **the sender** owes this party, with
  `has_value`. After a refused `/send`, this is where you see which prefill is missing without
  a second call. `ready_to_send` is the same gate the state machine applies.

### `/fields`

`{results: […]}`, in field-schema order — the order the fields appear in the agreement.

**Every row carries the rectangle twice, in upstream's two spellings**, with identical
numbers:

```json
{
  "x_postion": 14.27, "y_position": 15.43, "width": 15, "heigh": 1.63,
  "position": { "x": 14.27, "y": 15.43, "width": 15, "height": 1.63 }
}
```

`x_postion` (missing an `i`) and `heigh` (missing a `t`) are **upstream's typos and part of
the contract** (matrix D9). They are reproduced exactly here and go no further: the *create*
response spells the same concepts `x_position` and `height`, because upstream's create-response
schema does, and neither spelling reaches native code.

Also both spellings of the value (`value` and the deprecated `final_value`, identical) and
both of the type (`type` and `field_type`).

**Signature value encoding** — the document specifies none, so this is a decision:

| Request | A signed signature field's `value` |
|---|---|
| default | the marker string `"Recipient Signature"` |
| `?include=images` | the captured PNG **data URL** |
| never signed | `null` |

The recorded fixtures carry both forms, which is what makes the marker the right default
rather than an invention. Images are opt-in because an envelope carries one signature per
signer, and a response that ships them unasked puts somebody's signature in your request
logs, proxy caches and error reports.

`variable_name` is the string you sent, verbatim — spaces and slashes included
(`Company/Individual Name`). Internally a normalised copy is stored as the schema alias,
because the native schema's alias is an identifier; both spellings resolve on `PATCH`.

`tl_position`, `tr_position`, `bl_position` and `br_position` are **null**, in every row.
Upstream types them as bare numbers with no unit, no origin and no description, so there is
no value that would be right; guessing one would misplace a corner by the width of a page.
The recorded fixtures show null too.

`dropdown_options` (`{}`), `format_rules`, `validation_rules`, `multi_group_id`,
`date_default`, `calculated_font_size`, `required_conditions`, `visibility_conditions` and
`background_color` describe features this build does not have, and carry the empty value of
their own type so the key set matches. `deleted` is always `0`: nothing is soft-deleted from
a copied field schema, which is immutable.

### `PATCH`

The consumer's **singular** `field` payload:

```http
PATCH /functions/v1/signing-request-api/signing-requests/{id}

{ "field": { "variable_name": "agreement_date", "value": "2026-03-15" } }
```

Address the field by `variable_name`, `id` or `template_field_id`. The value is read from
`value`, then `final_value`, then `read_only_value`.

A `recipient` form corrects a party's contact details:

```json
{ "recipient": { "id": "01JC…", "first_name": "Corrected", "last_name": "Buyer", "email": "corrected@buyer.example" } }
```

The identity snapshot is corrected with them, rather than left describing the address the
invitation is no longer going to. A recipient patch must name the party by `id`: matching on
an email address would bind the correction to an address that may be the thing being
corrected.

- **One entity type per request.** Both `field` and `recipient` is a `400`, as upstream's own
  description requires.
- **The properties form is `501`.** See the refused-options table.
- **Drafts only.** After send, an invitation has been addressed and a correction is a
  different request, not an edit — `409 invalid_state`.
- The response is the field exactly as `/fields` renders it, or the recipient exactly as
  `/users` does, plus a **singular** `warning`. Upstream leaves both variants untyped
  (matrix D6); these are the shapes you already read. `warnings` (plural) belongs to create.

### `/send`

The response emits **both** disjoint shapes the upstream document gives for this route
(matrix D8 — the sharpest contradiction in the pinned document):

```json
{
  "success": true,
  "message": "Signing request sent.",
  "sentTo": ["dana@buyer.example"],
  "sentAt": "2026-02-01T09:15:00+00:00",

  "signing_request_id": "01JC…",
  "recipients_notified": 1,
  "sent_date": "2026-02-01T09:15:00+00:00",
  "expires_at": "2026-02-04T09:15:00+00:00"
}
```

The declared schema is `{success, message, sentTo, sentAt}` (camelCase, nothing required);
the operation's own example is `{message, signing_request_id, recipients_notified, sent_date,
expires_at}` (snake_case). They share only `message`. There is no captured fixture to decide
between them, and the two sets share no key with a different meaning or type — so the facade
satisfies both rather than being wrong for half of its clients.

`sentTo` and `recipients_notified` are the parties **this send released** an invitation to,
which for a sequential request is the first stage and not everybody.

Send is the last cheap moment: after it, correcting material content means a new request with
renewed signatures. So the gate is strict, and it reports **every** problem at once under
`details.problems` rather than making you discover the next one after fixing the previous.

### `/cancel`

```json
{ "reason": "Counterparty withdrew." }
```

Returns `{message, signing_request_id, cancelled_on, notify_signers, emails_sent}`.
`emails_sent` counts the parties already written to — the same audience the notice reaches —
so it is zero for a draft nobody was written to about.

**Cancelling a draft succeeds here, where upstream answers `409`.** Withdrawing a draft is a
legal transition in this state machine, and refusing something this service can do in order
to reproduce a limitation of somebody else's implementation would leave you holding a draft
you asked us to withdraw. A request already cancelled, finished, declined or expired is still
`409` — that one is a genuine conflict.

---

## Downloads

`GET /signing-requests/{id}/download` returns JSON, not bytes:

```json
{
  "status": "finished",
  "is_partial": false,
  "download_url": "https://esign.example/functions/v1/signing-request-api/downloads/djE…?expires=…&signature=…",
  "generated_at": "2026-02-04T11:02:31+00:00",
  "expires_at": "2026-02-04T11:17:31+00:00"
}
```

### Which document, and what `is_partial` means

| Request state | Document served | `is_partial` |
|---|---|---|
| finished | the **sealed executed PDF** — every value drawn on it, sealed under the service certificate | `false` |
| sent / in progress / cancelled / declined / expired | the **reviewed revision** — the bytes every party was shown, with nobody's signature on them | `true` |
| draft | none — `409 no_document_available` | |

That is what the recorded fixtures show upstream doing: an unfinished or cancelled request
answers HTTP 200 with a document-only URL rather than erroring. Upstream gates partial
downloads on an `allow_partial_download` setting that does not exist in its own schema
(matrix D11); there is nothing to gate on, so `is_partial` simply reports which of the two
documents you got. **Do not file a partial download as an executed agreement.**

A draft is refused because nothing has been shown to anybody: answering with the upload would
hand out bytes before anyone consented to see them.

A *finished* request whose artifact was never published answers `501`, not `409` — telling
you your finished agreement is unfinished would be false.

### What `download_url` is

An **app-issued capability**, not a pre-signed storage URL. Nothing in this application
pre-signs, on any driver (`docs/BLOB_STORAGE.md` rule 1). Concretely:

- It points at this application, and the bytes stream through it. No disk name and no object
  path appear in the link, so revoking a storage credential does not leave live links behind.
- **Fifteen minutes**, not upstream's hour, and the expiry is *inside the signature* — it
  cannot be extended by editing the query string.
- It names **one document of one signing request** and nothing else: no workspace, no
  credential, no scope. Editing the token changes the signature and the request is refused.
- It is not an API key. It cannot list, patch, send, cancel, or reach a second agreement.

Follow it promptly, or re-fetch `/download` for a fresh one. An expired or edited link answers
`403 forbidden`.

`GET /signing-requests/{id}` carries the same kind of link in `document_url` and, once the
agreement is executed, in `final_document_download_url`, `document_only_download_url` and
`certificate_only_download_url`. The last of those serves the **completion report**, which is
a report and not a signer's certificate — the member name is upstream's and is kept so you
can read it.

**A webhook body never contains one of these.** A body is encoded once and replayed for up to
~40 h, so a fifteen-minute link in it is dead before most receivers read it and a longer one
is a credential sitting in a log. `data.signing_request.download` reports only *that* an
artifact exists; the bytes come from this route, authorized per request
([`docs/delivery/webhooks.md`](../delivery/webhooks.md)).

---

## Webhooks

The event names, the envelope, and the `X-Firma-Signature` scheme are reproduced and
documented separately in [`docs/delivery/webhooks.md`](../delivery/webhooks.md). Two things
matter to a consumer of this profile:

- `data.signing_request` is built by the **same builder** as `GET /signing-requests/{id}`, so
  a polling receiver and a subscribing receiver see the same facts described the same way.
- `signing_request.completed` fires **later than upstream's**: only after the final PDF is
  generated, validated, durably stored and retrievable. `signing_request.recipient.signed`
  does not imply full execution, and `status.finished` stays false until it is true.

Which events are published, and which upstream names are deliberately never emitted, is in
the matrix's "Webhook events" section.

---

## Migrating a consumer

Six changes, in the order they bite:

1. **Point the base URL at this deployment.** The path after it is unchanged.
2. **Read `first_signer.signing_link`** instead of constructing
   `https://app.firma.dev/signing/{recipientId}`. This is the one change that is not
   optional.
3. **Treat `id` as opaque.** It is a ULID here.
4. **Map your template ids.** Either switch to this application's template public ids, or
   record your existing provider ids as aliases and keep sending them.
5. **Handle `501`.** Anything your client sends that this build cannot honour now fails loudly
   instead of being ignored — which is the point, and is usually a bug it was hiding. Read
   `details.unsupported_option`.
6. **Stop treating an unmatched prefill as harmless.** It is a `400` here.

Then run your own integration suite against a staging workspace. If something in the matrix
says `intentionally different` and your client cannot live with it, that is an issue worth
opening: the resolution may be wrong, but it will not be undocumented.
