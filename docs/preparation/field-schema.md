# Native field schema

One versioned schema describes where every field sits on a prepared document, who fills it in,
and in what order. The visual editor and the native API speak it directly; the Firma-compatible
facade converts its own convention into it. It is the only field vocabulary in the product.

| | |
|---|---|
| Contract | [`resources/schema/field-schema-1.1.json`](../../resources/schema/field-schema-1.1.json) — current (JSON Schema draft 2020-12). [`field-schema-1.0.json`](../../resources/schema/field-schema-1.0.json) is retained, unchanged, and still read; validate a 1.0 document against that one |
| Server | `app/Domain/Preparation/Schema` (`FieldSchemaDocument`, `FieldSchemaValidator`) |
| Editor | [`resources/js/schema/fieldSchema.ts`](../../resources/js/schema/fieldSchema.ts) (`parseFieldSchema`, `serializeFieldSchema`); the editor itself is [editor.md](editor.md) |
| Fixture | [`tests/Fixtures/schema/nda-two-signers.json`](../../tests/Fixtures/schema/nda-two-signers.json), synthetic, shared by both suites |
| Specification | `docs/HANDOFF.md` section 7 |

## Example

```json
{
  "schema_version": "1.0",
  "document_id": "doc_synthetic_nda",
  "coordinate_space": {
    "unit": "pt",
    "origin": "top-left",
    "page_box": "crop",
    "rotation": "displayed",
    "page_index_base": 1
  },
  "recipients": [
    {"id": "buyer", "name": "Example Buyer", "email": "buyer@example.test", "role": "Buyer"}
  ],
  "signing_order": [["buyer"]],
  "fields": [
    {
      "id": "buyer_signature",
      "recipient_id": "buyer",
      "type": "signature",
      "page": 2,
      "rect": {"x": 60, "y": 650, "width": 170, "height": 36},
      "required": true,
      "read_only": false,
      "label": "Buyer signature",
      "alias": "buyer_signature_block"
    }
  ]
}
```

## Sections

**`schema_version`** — `"1.1"`, the version this build writes, or `"1.0"`, which is still read and
still published. A document keeps the version it arrived with, and each version has its own
contract file: validate against the one the document declares, not against the newest. See
[versioning](#versioning) below.

**`document_id`** — the prepared document this field set belongs to. Preserved verbatim.

**`coordinate_space`** — the declared convention, every value fixed:

| Property | Value | Meaning |
|---|---|---|
| `unit` | `pt` | PostScript points, 1/72 inch |
| `origin` | `top-left` | y grows downwards from the top-left of the displayed page box |
| `page_box` | `crop` | coordinates are relative to the CropBox clipped to the MediaBox |
| `rotation` | `displayed` | coordinates are in the post-`/Rotate` orientation the viewer shows |
| `page_index_base` | `1` | `page` numbers start at 1 |

Every document declares it explicitly, and a document that declares anything else is **rejected,
never reinterpreted**. Coordinates are never guessed: nothing in this module infers points versus
percent from a number's magnitude.

The space itself is defined in [coordinate-space.md](coordinate-space.md) and owned by
`app/Domain/Preparation/Geometry`, which also holds every transform into PDF user space (CropBox
offsets, `/Rotate`, `/UserUnit`). `Geometry\CoordinateSpace` is the single source of the five
values above; the schema stores plain numbers and hands them over, and converts nothing itself.

**`recipients`** — `id`, `name`, `email`, and an optional `role` display label. The `role` is
cosmetic ("Buyer", "Witness"): it is not an application role and grants nothing. Application
roles live in the Identity module, and identity binds on issuer plus subject, never on the email
address recorded here.

**`signing_order`** — sequential stages of parallel recipients. `[["a"], ["b"]]` is a then b;
`[["a", "b"]]` is both at once. Every declared recipient appears exactly once.

**`fields`** — the field definitions:

| Property | Required | Notes |
|---|---|---|
| `id` | yes | stable, unique within the document, never regenerated on import |
| `recipient_id` | yes | must resolve to a declared recipient |
| `type` | yes | one of the [field types](#field-types) |
| `page` | yes | 1-based |
| `rect` | yes | `x`, `y`, `width`, `height` in the declared space, top-left anchored. With a `replace` anchor, `x` and `y` are a placeholder resolution overwrites; the size is always the field's own |
| `required` | no, default `true` | an unstated requirement fails closed |
| `read_only` | no, default `false` | |
| `label` | no | shown in the editor and to the signer |
| `alias` | no | stable template alias, unique within the document |
| `prefill` | no | `{"variable": "recipient.name"}` |
| `anchor` | no | see [anchor placement](#anchor-placement) |

An empty `fields` array is a valid draft. Requiring at least one field is a send-time gate, not a
schema rule.

`id` and `alias` are the two handles that outlive a document. Field ids survive import and export
byte-for-byte, and templates address fields by `alias`, so re-preparing a document keeps the
mapping. Neither is ever rewritten by the importer.

### Anchor placement

`anchor` is a placement *request*: find this text, then place the field's rectangle relative to
it, or check the rectangle that is already there. Resolution is deterministic positioned-text
extraction (`app/Domain/Preparation/Text`) that writes a receipt into `anchor.resolved` — when a
template version is published, and again when an envelope is sent, and never after that. Whether
it also writes the resolved rectangle into `rect` is exactly what `placement` decides: `replace`
does, and `cross_check` does **not** — there the declared rectangle is authoritative and a
disagreement larger than the tolerance is a refusal, never a silent move. A missing or ambiguous
required anchor is an error, never a guess, and matching is an exact case-sensitive match on
decoded text runs — never a regular expression over PDF bytes.

This section is the **shape**: what a document may say and what the published contract makes of
it. Resolution itself — when it runs, which failure fires when, what happens to a field whose
optional anchor is genuinely absent — arrives with the resolver and is documented there.

```json
{
  "text": "Counterparty signature:",
  "occurrence": "sole",
  "placement": "replace",
  "origin": "bottom_left",
  "offset": {"dx": 0, "dy": 12.5},
  "required": true
}
```

| Property | Required | Notes |
|---|---|---|
| `text` | yes | exact string to locate |
| `occurrence` | yes | `"sole"`, or a 1-based index in document order |
| `placement` | no, default `replace` | `"replace"` (the anchor decides x and y) or `"cross_check"` (the rect decides, and the anchor must agree) |
| `origin` | no, default `top_left` | corner of the matched text the offset is measured from; one of `top_left`, `top_right`, `bottom_left`, `bottom_right` |
| `offset` | no | `dx`, `dy` in the declared unit, `dy` downwards, either may be negative |
| `required` | no, default `true` | false says the text may legitimately be absent; only accepted on a field that is itself optional and whose placement is `replace` |
| `tolerance` | no | how far, in points, a `cross_check` anchor may resolve from the declared rect; at most 14400 (see [why every number is bounded](#why-every-number-in-this-schema-is-bounded)); meaningless with `replace` |
| `resolved` | written by the service | the receipt: the digest of the bytes the text was found in, the page, the occurrence taken, and both rectangles. `anchor_rect` is a `measured_rect` — an observation of where the text was, which may overhang the page — while `rect` is a placement and, in `replace` mode, must be the field's own |

`occurrence` is **required and has no default**: the resolver refuses a "first match wins"
fallback, because silently taking the first match moves a signature box the moment the contract
text changes. Its third mode, `all`, places one box per match, which a single field with a single
id cannot represent, so it is not a document value: a document that wants several boxes says so
with several fields.

`placement` is defaulted rather than required, and the two are treated differently on purpose:
`replace` is not a guess between two readings but the only behaviour an anchor has ever had here,
so defaulting it is what an already-stored document *said*. That is what keeps an anchor written
before `placement` existed byte-identical — and with it the field-schema digest every attestation
is bound to.

`placement`, `required`, `tolerance` and `resolved` are additive optional members and therefore
arrive in **schema 1.1**. `resources/schema/field-schema-1.0.json` is unchanged and still
published; a 1.0 document keeps its version and its bytes, and this build reads both. Using one of
those members in a document that declares 1.0 is `unknown_property` — exactly what a consumer
holding the 1.0 contract would say — so the version string is enforced rather than merely
written.

`occurrence` and `origin` are the serialised form of `Text\AnchorOccurrence` and
`Text\AnchorOrigin`, so the document cannot express a placement the resolver does not implement,
and there is one definition of what each mode means.

**1.1 also states the relationships between these properties, and 1.0 did not.** Four rules tie
one property to another, and each is now an `if`/`then` in the contract file rather than only a
rule in the importers:

| Rule | Where |
|---|---|
| `anchor.required: false` requires the field's own `required` to be `false` | `$defs/field` |
| `anchor.tolerance` requires `placement: "cross_check"` | `$defs/anchor` |
| `anchor.required: false` requires `placement: "replace"` | `$defs/anchor` |
| a `cross_check` `resolved` receipt requires `anchor.tolerance` | `$defs/anchor` |

A relationship between two properties is the easiest kind of rule for a contract file and an
importer to disagree about, because the file can only say it with a conditional and it is tempting
not to write one. The cost of that disagreement falls entirely on an integration: it validates
against the published file, is told its document conforms, and then gets a 422 from the service.
`fieldSchemaContract.test.ts` runs `ajv` and the TypeScript importer over the same document for
each rule and asserts both refuse it; `FieldSchemaContractTest` pins the conditionals' shape so
they cannot be dropped from the file. 1.0 stays exactly as published — these are not backported,
because the file is frozen and its consumers are entitled to the bytes they have.

## Field types

`signature`, `initials`, `text`, `name`, `company`, `title`, `agreement_date`, `signing_date`,
`checkbox`.

**Additional field types are declared capabilities, never silently ignored.** A type outside this
list is rejected with `unsupported_field_type` and the document does not import. Dropping an
unrecognised field would produce a document that looks complete and asks nobody to sign it, which
is the failure mode this rule exists to prevent. Adding a type means: extending the enum in the
JSON Schema, the PHP `FieldType`, and the TypeScript `FIELD_TYPES`; bumping the minor version;
and advertising it in the capability matrix. The same rule applies to any new control the editor
grows.

## Canonical form

Import canonicalises, and export is deterministic, so the same document always produces the same
bytes on both the server and the client:

1. Properties in the order above, at every level. Fixed, not alphabetical, so the emitted document
   reads like the schema file and the specification example.
2. `required` and `read_only` always stated. Inside `anchor` the opposite rule applies: `placement`
   and `required` are written only when they differ from their defaults, which is what keeps an
   anchor written before those properties existed byte-identical. (`anchor.occurrence` is required
   by the schema itself, so it is always present.)
3. Coordinates rounded once to **three decimals**, half away from zero (0.001 pt is roughly a
   third of a micron; no drag can express less). Integral values are written `60`, never `60.0`.
4. No insignificant whitespace; slashes and non-ASCII characters unescaped.

`FieldSchemaDocument::canonicalJson()` and `serializeFieldSchema()` produce identical bytes for
identical documents. A document already in canonical form round trips exactly — in PHP,
`toArray(fromArray($x)) === $x` including property order and number types. A document that merely
validates (defaults omitted, `60.0` for `60`, coordinates finer than a thousandth of a point) is
canonicalised on first import, and every round trip after that is byte-identical. The property
tests generate a thousand documents on each side and assert exactly that, with strict identity
rather than a float tolerance.

### Why every number in this schema is bounded

Identical bytes on both sides is a claim about *every* value the importer accepts, and it stops
being true at the top of the double range. Above roughly 1e20, PHP's `json_encode()` and
JavaScript's `JSON.stringify()` spell the same number differently — `1.0e+20` against
`100000000000000000000` — so a document containing one would canonicalise to two different byte
strings and therefore two different `field_schema_sha256`, which is the digest every attestation
binds. That is not a rounding disagreement to be tightened away; in that range **the canonical
form is undefined**, and no amount of care in the rounding helper changes it.

Every number this schema had until 1.1 was a coordinate, and a coordinate describes a position on
a page, so the values that break canonicalisation never arose in a document anyone would write.
`anchor.tolerance` was the first number with no page behind it — a distance, not a position — and
it made the range reachable for the first time.

The schema's answer is to **bound the property rather than to canonicalise across encoders**. A
tolerance is a distance between two positions on one page, so the bound is PDF's own maximum page
side, 14400 pt (200 inches): the largest distance that can mean anything here, and five orders of
magnitude below where the encoders start to disagree. The alternative — defining a shared
number-to-string encoding — would mean owning a float formatter in two languages forever, for
values no document will ever hold.

Which numbers are bounded, and by what, is **not written down here** — it is derived from the
contract file and asserted on both sides by
`tests/Unit/Preparation/Schema/NumericBoundsSweepTest.php` and
`resources/js/schema/numericBounds.test.ts`. Read those for the current answer.

That is deliberate, and it is the second thing this section is about. A table here was a second
source of the same truth, maintained by hand, and it drifted in exactly the way a hand-kept list
drifts: it filed `anchor.resolved.rect` under "legacy, see #105" because the property *is* a
`rect` by type, when `resolved` arrived in 1.1 and the property is this schema's own. The contract
file already knows where every number lives and what bounds it, so nobody should have to remember.

The sweep walks `field-schema-1.1.json` for every numeric member, fails if one has no probe, and
for each bounded member checks that its `maximum` is accepted and `maximum + 1` refused — in both
implementations, with the same verdict. The members deliberately left unbounded are swept too,
with that expectation written down, so the set cannot quietly change. Those are `rect.*` and
`anchor.offset.*`, both 1.0 properties: tightening them changes what this build accepts for
documents that were already valid, so it is a version-policy decision rather than a repair
(issue #105).

**Validate what will be stored, not what was typed.** Import canonicalises to three decimals, so
a constraint checked against the submitted value is checking a number the document will not hold:
a `width` of `0.0004` is positive as written and zero as stored, and a document accepted on those
terms failed its own next import with `dimension_not_positive` — accepted into a state that cannot
be read back, which is a latent corruption wearing the shape of a success. Every rectangle
constraint is now applied to the canonical value. This is the same rule as the receipt comparisons
(`checkCrossCheckReceiptAgrees`, `checkReplaceReceiptMatchesRect`) one level down: there it is two
values compared with each other, here it is one value against its own constraint. A **round-trip
sweep** beside the bounds one asserts it for every numeric member the contract declares, probing
one canonical step below the smallest legal value — the lower edge, where the bounds sweep
structurally cannot look.

**Probe at the boundary, not at a large number.** The sweep originally tested `1e20` alone, which
looks like the stronger case and is strictly weaker. `1e20` is a float; the bound it was meant to
guard sits at 2^53, where PHP still has an *integer* — so the probe took a different code path
from the one under test, and PHP accepted 2^53 for three properties while TypeScript refused it. A
bound is distinguished from its absence by exactly two values: the largest accepted and the
smallest refused.

**The rule for the next unbounded property.** A canonicaliser that reaches its result by scaling
has a range where it stops being total, and a schema whose numbers were all bounded never
exercised it. When adding a number that is not a coordinate, give it a bound with a stated reason,
and check the canonical form at the extremes of the *type* rather than the extremes of the
documents you happen to have. The sweep is the checklist, and it enforces itself: a member added
to the contract without a probe fails the coverage assertion before anyone has to notice.

## Rejection rules

Import fails closed and reports **every** problem at once, each with an RFC 6901 JSON Pointer
(`/fields/3/rect/width`), a stable code, and a message. The editor annotates every offending
field in one pass; the native API returns the same list. Codes are API surface: renaming one is a
breaking change.

| Code | Rejected |
|---|---|
| `missing_property` | a partial document, or a section or property that is absent |
| `unknown_property` | any property the schema does not declare, at any level |
| `invalid_type` | wrong JSON type, including a non-integer `page` and a numeric string coordinate |
| `invalid_format` | a malformed id, prefill variable, label, anchor occurrence, or anchor origin |
| `invalid_email` | a recipient email that is not usable as an address |
| `empty_collection` | no recipients, no signing stages, or an empty stage |
| `schema_version_unsupported` | an absent, malformed, unknown-major, or newer-minor version |
| `unsupported_coordinate_space` | any coordinate space other than the one above |
| `unsupported_field_type` | a type outside the declared list |
| `duplicate_id` | two recipients or two fields sharing an id |
| `duplicate_alias` | two fields sharing a template alias |
| `unknown_recipient` | a `recipient_id`, or an id in `signing_order`, that resolves to nothing |
| `recipient_not_in_signing_order` | a declared recipient who would never be asked to sign |
| `recipient_duplicated_in_signing_order` | a recipient in two stages, or twice in one |
| `page_out_of_range` | `page` below 1, or beyond the page count when it is known |
| `coordinate_not_finite` | NaN or infinity in a rectangle or an anchor offset |
| `coordinate_negative` | a negative `x` or `y`, which is off the page by construction |
| `dimension_not_positive` | a zero or negative `width` or `height` |
| `rect_out_of_page` | a rectangle extending past the edge of its page |
| `unresolved_prefill_variable` | a prefill variable the sending context cannot resolve |
| `anchor_optional_on_required_field` | `anchor.required: false` on a field whose own `required` is true |
| `anchor_cross_check_failed` | a `cross_check` receipt records a rectangle further than its stated tolerance from the declared one |

Both are checked without a document, like every other rule here: the first is a relationship
between two declared properties, and the second re-checks a receipt the document already carries.
The failures that need the PDF itself — a text that is not there, a match that is ambiguous, an
offset that walks off the page — belong to resolution and arrive with it.

Every rule has a test on both sides, from the same fixture:
`tests/Unit/Preparation/Schema/FieldSchemaValidatorTest.php` and
`resources/js/schema/fieldSchema.test.ts`.

### Checks that need context

A field document carries page numbers but no page geometry, and prefill variable *names* but no
variable set. Three checks therefore depend on what the caller supplies:

| Check | Needs |
|---|---|
| `page` is at least 1 | nothing; always checked |
| `page` is within the document | page sizes (`PageSizes` / `pageSizes`) |
| the rectangle fits the page | page sizes for that page |
| the prefill variable resolves | the variable list (`$variables` / `variables`) |

Page sizes are the *displayed* size, which is what `Geometry\PageGeometry::nativeWidth()` and
`nativeHeight()` compute. The send-time gate passes both; a draft save from the editor may pass
neither. An omitted check is never reported as a passed one — it is simply not performed, and the
document is not treated as fully validated until it is.

## Versioning

- **Additive change** — a new optional property, a new field type, a new prefill variable — bumps
  the **minor** version.
- **Anything else** — a removal, a rename, a semantic change, a new required property, a different
  coordinate space — bumps the **major** version.
- An importer **refuses an unknown major version outright**. It never reads what it recognises and
  drops the rest.
- An importer also refuses a **newer minor** version, because such a document may carry properties
  it would silently discard.

Both directions fail closed, on purpose: a dropped field is a field nobody was asked to sign, and
a reinterpreted rectangle is a signature in the wrong place. A new version means a new schema file
(`field-schema-1.1.json`) alongside this one, so an archived document can always be read under the
version it was written in.

## Why no JSON Schema library on the server

The JSON Schema file is the published contract and it is enforced — the TypeScript suite validates
the shared fixture against that exact file with `ajv` (a dev dependency), and PHP tests pin the
file's enums, property lists, patterns, limits, and defaults against the PHP classes and against
the TypeScript mirror. What the server does not do is re-implement the checks on top of a PHP
JSON Schema evaluator. Almost every rule above is one JSON Schema cannot express: unique ids,
resolvable references, a recipient in exactly one stage, a rectangle inside a page whose size lives
in the PDF, a variable the sending context can resolve. A library would add a production dependency
and a second error vocabulary with pointer-shaped messages the editor cannot use, in exchange for
the least interesting part of the work. `THIRD_PARTY_NOTICES.md` records the same conclusion about
`opis/json-schema` arriving as a transitive dependency.
