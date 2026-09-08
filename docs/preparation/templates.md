# Templates and template versions

A template is the thing a sender picks. A template *version* is the thing they send, and it
is frozen the moment it is published, so a later edit to the template cannot reach an
envelope that is already out for signature.

Implements issue #21. Read with [documents.md](documents.md) for where the PDF revision comes
from, [field-schema.md](field-schema.md) for the field vocabulary, [editor.md](editor.md) for the
visual editor that reads and writes a draft version's field set, and sections 6 and 7 of
[docs/HANDOFF.md](../HANDOFF.md).

| | |
|---|---|
| Domain | `app/Domain/Preparation/Templates` (`TemplateService`, `Models\{Template,TemplateVersion,TemplateAlias}`, `RenderSettings`) |
| HTTP | `app/Http/Controllers/Templates`, `app/Http/Requests/Templates`, `routes/templates.php` |
| Tables | `templates`, `template_versions`, `template_aliases` |
| Tests | `tests/Feature/Preparation/TemplateLifecycleTest.php`, `tests/Feature/Preparation/TemplateHttpTest.php` |

## The one invariant

> A template version snapshots the PDF revision, recipients/roles, field definitions, consent
> policy, and relevant rendering settings. Sending copies the selected version into the
> envelope. Later template changes never mutate existing requests. — HANDOFF §6

Everything below is a consequence of that sentence. The template row carries a name, a
description, and a pointer to the current version — nothing a signer ever reads. All of the
content lives on the version, which is why "rename the template" and "change what it says"
are structurally different operations rather than two calls that happen to be spelled
differently.

## Lifecycle

```
create template
      │
      ▼
POST versions ──▶ draft (editable in place)
                     │
                     ▼
      POST versions/{n}/publish ──▶ published (frozen, becomes current_version_id)
                                       │
                     an edit ──────────┘──▶ POST versions again ──▶ draft n+1
```

Four rules, each enforced in `TemplateService` and again at the model or in the schema:

1. **A version snapshots a review revision, not a document.** It references
   `document_revisions.id` for a `review` revision of a `ready` document. Binding to the
   document would let a later upload change what the template means; architecture invariant 2
   binds an acceptance to a specific immutable revision.
2. **Publishing resolves the version's anchors, and then the version is never edited.**
   `published_at` is the lock. `TemplateVersion`
   refuses updates and deletes after it is set, with
   `PublishedVersionIsImmutableException`; the service refuses first so the caller gets a
   message rather than a half-open transaction. The HTTP answer is **409**, with the
   instruction to draft the next version.
3. **An edit after publishing is version n+1.** There is no route, and no service method,
   that mutates a published version. `POST .../versions` is both "first version" and "edit
   after publish"; the number comes from a counter read inside the transaction, and the unique
   key on `(template_id, version)` turns a lost race into a database error rather than two
   versions sharing a number.
4. **A template with only drafts is not sendable.** `current_version_id` is set by publishing
   and only by publishing, so a draft cannot be picked for an envelope. Drafts are also
   refused by `TemplateVersion::snapshotForEnvelope()` — a draft can still change, and an
   envelope that copied one would be exactly the request that a template edit *did* reach.

Retiring (`retired_at`) withdraws a template from the picker. It deletes nothing and freezes
nothing already sent: envelopes hold their own snapshot either way. What it stops is drafting
a version, publishing one, and picking the template for something new. It is reversible, and
both directions are audited (`preparation.template_retired` / `..._restored`).

## What a version stores

| Column | Contents |
|---|---|
| `version` | per-template counter from 1, unique with `template_id` |
| `document_revision_id` | the `review` revision this version snapshots. `RESTRICT` |
| `field_schema` | the canonical field schema document (`FieldSchemaDocument::canonicalJson()`, decoded) |
| `field_schema_sha256` | digest of those canonical bytes |
| `recipients` | the roles/placeholders from the same schema, with each one's signing stage |
| `consent_policy_version` | the consent text version a signer will be shown |
| `render_settings` | the declared rendering settings below |
| `published_at` | null while a draft. Setting it is the last permitted write, and the same statement stores the anchor-resolved field set |

`TemplateVersion::snapshotForEnvelope()` returns all of it in one array, and the Signing
module is expected to call that instead of reading columns: what a version *means* is then
defined in one place, and a new snapshot property is added there rather than in every
consumer. It re-verifies the schema digest before handing anything over, and refuses a draft.

### Recipients are denormalised, never independent

`recipients` is written from the field schema's own `recipients`, with `stage` derived from
its `signing_order`, and never from a separate input. The column and the schema therefore
cannot disagree. It exists so Signing can answer "who does this template expect, and in what
order" without importing the whole field set.

### Reading `field_schema` back

**Never hash the raw column.** A MySQL `JSON` column does not preserve object key order, so
the bytes that come back out are not necessarily the bytes that went in, and the stored
digest would appear to be wrong on one engine and right on another. Everything goes through
`TemplateVersion::canonicalFieldSchemaJson()`, which re-imports the decoded value through
`FieldSchemaDocument` and re-emits it canonically. Re-import is order-insensitive, so the
bytes — and the digest — are identical on SQLite, MySQL 8, and MariaDB.

The alternative, a `text` column holding the bytes verbatim, was rejected because it gives up
every query and index the JSON type offers in exchange for a property the canonical form
already guarantees.

### Anchors are resolved when the version is published

A field can carry an [anchor](anchors.md) instead of a position: *find this text, then put the
box next to it*. Publishing is where that request becomes a rectangle.

It is the right moment for two reasons. A version snapshots one immutable review revision, so
publishing is the first point at which the field set and the exact bytes it will be placed on are
both fixed — and it is the last point at which a template is cheap to fix. An anchor whose text is
not in that document, or is in it twice, is therefore reported while the sender is still
authoring, as an ordinary 422 with a JSON Pointer to the offending field.

Resolution runs before the publish transaction opens, so the private-disk read and the
content-stream parse never happen while the version row is locked; the outcome carries the digest
of the field set it ran against, and a locked row that disagrees is resolved again under the lock.

The resolved rectangle is written into the field's `rect` and a receipt into `anchor.resolved`
(which page, which occurrence, where the matched text sat, and the digest of the bytes it was
measured in), and `field_schema_sha256` moves with them. So a published version is already placed:
every envelope drawn from it starts with the geometry settled and re-resolves nothing.

Publishing is not the *authoritative* resolution — an envelope can be built straight from a
document with no template involved, so `send()` is. And an absent *optional* anchor is reported
here without being acted on (`preparation.template_version_anchors_resolved`, with
`omissions_applied: false`): which fields an envelope leaves out is a fact about that envelope,
recorded on it.

### Validation

The field set is validated by `FieldSchemaValidator` before it is stored, with page sizes
taken from the *document's own preflight report*. So a rectangle past the edge of a page, or a
field on a page the PDF does not have, is caught when the version is drafted rather than at
send time.

The prefill-variable check is deliberately **not** run here: the variable set belongs to the
sending context, which a template does not have. An omitted check is never reported as a
passed one ([field-schema.md](field-schema.md), "Checks that need context"), so the send-time
gate still runs it.

A rejection is a 422 carrying **every** problem at once, in two shapes:

```json
{
  "message": "The field schema document was rejected. Nothing was saved.",
  "errors": { "field_schema": ["/fields/0/recipient_id [unknown_recipient] …"] },
  "field_schema_errors": [
    { "path": "/fields/0/recipient_id", "code": "unknown_recipient", "message": "…" },
    { "path": "/fields/1/rect/width", "code": "dimension_not_positive", "message": "…" }
  ]
}
```

`errors.field_schema` is the shape every Laravel client already reads. `field_schema_errors`
is the structured list with RFC 6901 JSON Pointers that the visual editor needs to annotate
every offending field in one pass. Neither shape carries the other's information, and the
codes are API surface: renaming one is a breaking change.

The field set's own `document_id` is preserved verbatim and is **not** required to equal the
document it is stored against, because schemas arrive from imports that use the provider's
identifier. What binds a version to bytes is `document_revision_id`, never that value.

### Render settings

A declared capability list, not a free-form bag: an unrecognised key is a 422, because a
silently ignored render setting is a sender who believes they turned something on. Same rule
as unrecognised field types.

| Setting | Values | Default |
|---|---|---|
| `date_format` | `iso`, `us`, `eu`, `long` | `iso` |
| `timezone` | any IANA identifier | `UTC` |
| `include_certificate_page` | boolean | `true` |
| `signature_appearance` | `drawn`, `typed`, `either` | `either` |

They are frozen into the version rather than read from live configuration at send time: a
deployment that switched its date format would otherwise make two envelopes from the same
version render different dates, and the evidence would not say which one a signer saw.

Adding a setting means a case in `RenderSettings`, a rule in
`TemplateVersionContentRequest`, and a row in this table.

### Consent policy version

A string, snapshotted per version, defaulting to
`config('esign.templates.default_consent_policy_version')`
(`ESIGN_CONSENT_POLICY_VERSION`). Deployment configuration rather than a table: the consent
policy is drafted and approved outside this application and its history outlives anything this
schema owns. Changing the setting affects versions published after the change and nothing
else — which is the point of the snapshot.

## Aliases: imported-provider ids stay separate from native ids

HANDOFF §6 requires native ids and imported-provider aliases to be separate fields, and §2
says why: the consumer hardcodes provider template ids and patches prefills by name, so a
migration that reassigned those ids would drop overrides or send the wrong agreement.

A `template_aliases` row maps somebody else's identifier onto one of our templates. It never
becomes the template's own id, and `templates.public_id` is never derived from one.

| `source` | Meaning |
|---|---|
| `imported-provider` | a template id from the provider being migrated away from |
| `manual` | a stable handle an operator chose, so integration code can address a template by a name it controls |

**Uniqueness is per workspace, not global.** Two tenants that both migrated from the same
provider will legitimately present the same provider template id, and a global constraint
would make the second tenant's import fail. That is why `workspace_id` is carried on the alias
row: a unique index cannot reach through `template_id`, and a check-then-insert in application
code is a race. Resolution (`TemplateService::resolveAlias()`) is scoped the same way, so an
alias another tenant registered does not resolve at all rather than resolving and then being
forbidden.

Aliases are added and removed, never edited — `created_at` and no `updated_at` — so a rename
cannot quietly repoint a provider id at a different agreement.

## HTTP surface

All under `/workspaces/{workspace}`, `web` + `auth`, declared in `routes/templates.php`.

| Route | Permission |
|---|---|
| `GET /templates` | `view` |
| `POST /templates` | `createTemplates` |
| `GET /templates/{template}` | `view` |
| `PATCH /templates/{template}` (name, description, retired) | `createTemplates` |
| `POST /templates/{template}/versions` | `createTemplates` |
| `GET /templates/{template}/versions/{version}` | `view` |
| `PATCH /templates/{template}/versions/{version}` (drafts only) | `createTemplates` |
| `POST /templates/{template}/versions/{version}/publish` | `createTemplates` |
| `GET /templates/{template}/versions/{version}/schema.json` | `view` |
| `POST /templates/{template}/aliases` | `createTemplates` |
| `DELETE /templates/{template}/aliases` | `createTemplates` |

`{version}` takes either the per-template number a human quotes (`/versions/2`) or the
version's public ULID. Both resolve inside the already-resolved template, so neither can reach
another tenant's version, and integration code that recorded "v2" does not have to look a ULID
up first. Anything that is neither shape is a 404 at the router.

The alias travels in the **body** on both `POST` and `DELETE`. A provider template id is
somebody else's string and may need percent-encoding; an alias that round-trips through a URL
segment is one encoding bug away from unmapping the wrong template.

`schema.json` returns the canonical bytes themselves, with the recorded digest in
`X-Field-Schema-Sha256`, so a client can diff two versions or verify the digest without
re-canonicalising anything. It is a separate route from the version payload because this one
has to be *the bytes*, and a representation is allowed to grow a field.

### Status codes

| Situation | Status |
|---|---|
| invalid field schema | 422, with `field_schema_errors` |
| an anchor that cannot be resolved, on publish | 422, in the same `field_schema_errors` shape |
| document not `ready`, or from another workspace by model | 422 |
| alias already mapped in this workspace | 422 |
| alias not on this template | 404 |
| editing a published version | 409 `version_published` |
| publishing twice | 409 `version_already_published` |
| drafting or publishing on a retired template | 409 `template_retired` |

409 rather than 422 for the last three is the honest distinction: nothing about the request is
wrong, and re-sending it once the conflict is resolved — draft the next version, restore the
template — is exactly the right thing to do.

## Authorization

- Writes take `createTemplates`: sender and above. An **auditor can read** every template,
  every version, and every schema export, and can change none of it — which is most of what
  auditing a template means.
- Reads take `view`, the floor for every workspace role.
- Route parameters resolve through `Workspace::whereMemberOf()`, so a workspace the caller is
  not a member of is a **404**, not a 403: an outsider must not be able to distinguish a
  workspace they cannot see from one that does not exist. A template is looked up inside the
  resolved workspace, a version inside the resolved template, and the `document_id` sent to
  the version endpoint inside the resolved workspace, so a foreign identifier is a 404 at
  every level.
- 403 is reserved for the case it describes: a member of this workspace whose role does not
  carry the permission.

## Foreign keys and deletion

`templates.workspace_id`, `template_versions.template_id`,
`template_versions.document_revision_id`, and both columns on `template_aliases` are all
`RESTRICT`, per the no-cascade rule the memberships migration sets out: an executed agreement
outlives the membership that created it, the template it came from, and the workspace that
held it. Templates are soft-deleted, so anything sweeping storage or evidence must read the
table through the query builder rather than Eloquent, which would hide trashed rows.

`templates.current_version_id` deliberately has **no** foreign key. `templates` and
`template_versions` reference each other, and a circular constraint cannot be added to an
existing table on SQLite (there is no `ALTER TABLE ADD CONSTRAINT`), so declaring it would
mean the column behaves differently in development, in the CI MySQL/MariaDB jobs, and in
production. It is written only by `TemplateService::publish()`, inside the transaction that
stamps `published_at` on the version it points at, and `Template::currentVersion()` resolves
it against the template's own versions so a stale id yields null rather than somebody else's
version.

## Audit

Every mutation writes one `esign_audit_events` row inside the same transaction as the change,
so the trail cannot disagree with the data:

`preparation.template_created`, `preparation.template_updated`,
`preparation.template_retired`, `preparation.template_restored`,
`preparation.template_version_drafted`, `preparation.template_version_updated`,
`preparation.template_version_published`, `preparation.template_version_anchors_resolved`,
`preparation.template_alias_added`, `preparation.template_alias_removed`.

`preparation.template_version_anchors_resolved` is written only when a publish actually placed
something: it lists the fields whose rectangle it wrote and any whose optional anchor was absent,
with `omissions_applied: false` because publishing reports an absence rather than acting on it
([anchors.md](anchors.md)).

A no-op — a PATCH that changes nothing, retiring a template that is already retired — writes
neither a row nor an event.
