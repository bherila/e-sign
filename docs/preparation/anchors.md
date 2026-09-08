# Anchor placement

A field can say where it goes in two ways. It can carry coordinates — a rectangle somebody
dragged in the editor, or one a consumer's own layout engine computed — or it can carry an
**anchor**: find this exact text in the document, and put the box next to it.

This page is about the second way: what an anchor means, when it is turned into a rectangle,
what happens when it cannot be, and when a consumer should use coordinates instead.

The rule underneath all of it is `AGENTS.md`'s: **coordinates are never guessed.** An anchor is
resolved by parsing page content streams and measuring real glyph advances
(`App\Domain\Preparation\TcPdf\TcPdfTextLocator`), never by a regular expression over PDF bytes
and never by a model. If that produces no answer, or more than one, the answer is an error — not
a default position and not a dropped field.

## The shape

```json
{
  "id": "counterparty_signature",
  "recipient_id": "counterparty",
  "type": "signature",
  "page": 2,
  "rect": {"x": 330, "y": 650, "width": 170, "height": 36},
  "required": true,
  "read_only": false,
  "anchor": {
    "text": "Counterparty signature:",
    "occurrence": "sole",
    "placement": "replace",
    "origin": "bottom_left",
    "offset": {"dx": 0, "dy": 12.5},
    "required": true
  }
}
```

| Property | Required | Notes |
|---|---|---|
| `text` | yes | exact string, matched case-sensitively against decoded text runs |
| `occurrence` | yes | `"sole"` (must occur exactly once) or a 1-based index in document order |
| `placement` | yes | `"replace"` or `"cross_check"`; see below |
| `origin` | no, default `top_left` | corner of the matched text the offset is measured from |
| `offset` | no | `dx`, `dy` in points, `dy` downwards, either may be negative |
| `required` | no, default `true` | false is the compatibility option below |
| `tolerance` | no | points; `cross_check` only |
| `resolved` | written by the service | the receipt; see [What gets stored](#what-gets-stored) |

Three of those are required with **no default**, and all three for the same reason: an unstated
placement rule is a guess, and a guess moves a signature box.

- **`occurrence`** has no "first match wins" fallback. A string that matches twice, in an anchor
  that does not say which match it means, is under-specified — and silently taking the first would
  move the box the day somebody adds a paragraph above it.
- **`placement`** exists because a field always carries a rectangle. Schema 1.0 requires `rect`
  and always will: it is what the editor draws, what assembly stamps, and it carries the field's
  *size*, which an anchor never supplies — an anchor says where a field goes, never how big it is.
  So an anchored field holds two statements about its position, and `placement` is the document
  saying which one wins.
- **`required`** is the field-level rule applied to the anchor: an unstated requirement fails
  closed.

`occurrence`, `origin` and the matching rules are the serialised form of the value objects in
`App\Domain\Preparation\Text`, so a document cannot express a placement the resolver does not
implement. The resolver's third occurrence mode, `all`, places one box per match, which a single
field with a single id cannot represent; a document that wants several boxes says so with several
fields.

### `placement: "replace"`

The anchor decides the position. The rectangle's `width` and `height` are the field's size; its
`x` and `y` are a declared placeholder that resolution overwrites, and **nothing is ever left at
the placeholder** — if the anchor cannot be resolved, the publish or the send is refused.

This is the mode for a document somebody else produced: a counterparty's paper, a form from a
regulator, anything whose layout you do not control but whose wording you do.

### `placement: "cross_check"`

The rectangle decides the position, and the anchor is a check on it. The document is asserting
two things at once — "the box is at these coordinates" and "the words `X` are there" — and
resolution requires them to agree within `tolerance` points of the rectangle's top-left corner.

A larger disagreement is an error. Exactly one of the two statements is stale and there is no way
to tell which: trusting the rectangle would stamp a signature beside text that moved, and
trusting the anchor would move a box the consumer's own layout positions. Both are silent, and a
refusal is not.

`tolerance` defaults to `config('esign.preparation.anchor_cross_check_tolerance')`, one point.
Deliberately tight: the point of a cross-check is to catch a layout that moved, and a generous
tolerance catches nothing. `tolerance` on a `replace` anchor is a validation error, because there
is no declared rectangle for it to be a tolerance of.

## Scope: one page, the field's own

An anchor is searched **only on the page the field declares.** A field states its page, the page
is checked against the document's page count, and an anchor able to move a field to a different
page would turn that statement into a suggestion. Scoping the search is also what makes "matched
twice" a fact a sender can act on rather than a document-wide coincidence.

A field that should follow text onto whichever page it lands on is not expressible in version 1.0,
and that is a deliberate omission rather than an oversight.

## When resolution happens

Twice, and never again after that.

**Publishing a template version** resolves every anchor against the review revision the version
snapshots. It is the first moment a field set and the exact bytes it will be placed on are both
fixed, so it is the first moment an unplaceable anchor can be reported — while the sender is still
authoring and a fix costs nothing. It is also why a published version is already placed: every
envelope drawn from it starts with the geometry settled.

**Sending an envelope** is the authoritative resolution. An envelope does not have to come from a
template: the native API and the Firma-compatible facade both build one straight from a document,
and neither has a publish step to have caught anything at. Resolution runs inside `send()`, before
anything is written, and the resolved schema is stored in the same statement as the transition to
`sent`.

Storing the schema at send looks like an exception to "the envelope's snapshot never changes"
(`docs/ARCHITECTURE.md` invariant 2) and is not. An anchored field arrives carrying a *question* —
put this box next to the words `Signature:` — and resolution is where that question becomes a
coordinate. It happens before anybody is invited, so nothing has been shown for assent and there
is nothing an acceptance could already bind to. Afterwards the rule holds without an exception:
**nothing re-resolves**, and a rectangle a signer saw can never move.

Two things make that guarantee checkable rather than a matter of care. Every receipt names the
digest of the bytes it was measured in, so a field already resolved against an envelope's own
document is skipped — and one carrying a receipt from a *different* revision is resolved again
rather than trusted. And no code path outside `send()` and `publish()` calls the resolver at all;
`tests/Feature/Signing/EnvelopeAnchorResolutionTest.php` counts the calls through a send, two
acceptances, and completion, and expects exactly one.

## What gets stored

Both halves. The anchor is provenance — the question that was asked — and the field's `rect` is
the answer everything downstream uses.

```json
"rect": {"x": 330, "y": 646.9, "width": 170, "height": 36},
"anchor": {
  "text": "Counterparty signature:",
  "occurrence": "sole",
  "placement": "replace",
  "origin": "bottom_left",
  "offset": {"dx": 0, "dy": 12.5},
  "required": true,
  "resolved": {
    "document_sha256": "3b53…0a",
    "page": 2,
    "occurrence_index": 1,
    "anchor_rect": {"x": 330, "y": 622.4, "width": 165.6, "height": 12},
    "rect": {"x": 330, "y": 646.9, "width": 170, "height": 36}
  }
}
```

`anchor_rect` is where the matched *text* sits; `rect` is what came out of it once the origin
corner and offset were applied. Keeping both means a reader can check the placement without
re-running extraction, which is the one thing that must not happen again.

A resolved field is **indistinguishable from a hand-placed one** to everything that reads a
rectangle: the editor, assembly, the signing page, and finalization all read `rect` and none of
them look at `anchor`. That is the property that keeps the rest of the system free of a second
placement path.

## When it fails

Every failure names the field id, the anchor text, and what was actually found — as structured
values, not only inside a sentence — and every failure in a document is reported at once, so a
sender fixing one does not discover the next afterwards.

| Code | Fires when |
|---|---|
| `anchor_not_found` | a required anchor's text does not occur on the field's page |
| `anchor_ambiguous` | `occurrence: "sole"` matched more than once |
| `anchor_occurrence_out_of_range` | `occurrence: n` and fewer than *n* matches exist |
| `anchor_cross_check_failed` | a `cross_check` anchor resolved further than `tolerance` from the declared rectangle |
| `anchor_resolved_off_page` | the offset put the rectangle off the page it was found on |
| `anchor_text_unreadable` | the document's text could not be extracted at all |
| `anchor_optional_on_required_field` | `anchor.required: false` on a field whose own `required` is true |

At **publish** they arrive as an ordinary field-schema rejection: a 422 with
`field_schema_errors[]`, each entry carrying the code and an RFC 6901 pointer
(`/fields/5/anchor`), which is what lets the editor put the message on the offending field
instead of on the document. Nothing is saved and the version stays a draft.

At **send** they arrive as `send_preconditions_failed`: a 422 whose `details.problems[]` carries
one entry per unplaceable field with `code`, `field`, `recipient`, `anchor_text` and `found`,
listed alongside every other reason the envelope is not ready. The envelope stays a draft and
nobody is invited.

`anchor_optional_on_required_field` is different from the rest: it needs no document, so it is
caught by the importer wherever a field set is written, including a template draft.

## The compatibility option: an anchor that may be absent

`docs/HANDOFF.md` section 7 allows exactly one narrow exception, and this is it. An anchor may
declare `"required": false`, which says: *this text may legitimately not appear in this document,
and if it does not, do not place the field at all.*

It is narrow in three ways.

1. **Only on an optional field.** An absent anchor omits the field, and a required field that is
   never placed can never be completed. The combination is a validation error
   (`anchor_optional_on_required_field`), refused where the field set is written.
2. **It never excuses ambiguity.** `required: false` says the text may be missing. It says nothing
   about what to do when the text is there three times, and a field that could go in three places
   is not a field anybody can be asked to sign. An ambiguous optional anchor is refused exactly
   like a required one.
3. **The field is omitted, not placed.** It is removed from the envelope's field set. There is no
   fallback position, because a field at a fallback position is a field nobody agreed to sign
   there.

### Where the omission is recorded

Two places, deliberately, and neither is a log line.

**On the envelope**, in `envelopes.omitted_anchor_fields`. Null for almost every envelope; when it
is not, each entry names the field, its recipient, the field type and alias, the page, the anchor
text that was looked for, the occurrence that was asked for, and `"reason":
"optional_anchor_absent"`. It is a column because the question it answers — *why is there no
witness signature on this agreement?* — is asked about a specific envelope, long afterwards, by
somebody looking at that envelope. An answer in a log file is an answer nobody finds.

**In the audit trail**, on the `signing_request.sent` event, whose payload carries the same list
alongside `anchors_resolved`. The two are a fact and its history rather than a duplicate: the
column says what is true of this envelope now, the event says when it became true and as part of
what.

Publishing a template version reports an absent optional anchor without acting on it — the
`preparation.template_version_anchors_resolved` audit event lists it with `omissions_applied:
false` — because which fields an envelope leaves out is a fact about that envelope, and different
envelopes from one template can be sent against the same bytes but recorded separately.

## Explicit rectangles instead

An anchor is the right tool when you control the *wording* of a document but not its layout.
Explicit rectangles are the right tool when you control the layout.

**Use explicit rectangles (no anchor) when the consumer generates the document itself.** A
consumer that renders its own PDF already knows where every box goes: it computed the layout.
Asking the service to find the coordinates again by searching for text is strictly worse — it can
fail for reasons the layout engine cannot, and its answer can only ever be an approximation of a
number the producer already had exactly:

- Text extraction slices a run's bounding box evenly across its characters. That is exact for a
  monospaced font and an approximation for a proportional one.
- A producer is free to split one visible line across several text-showing operators. An anchor
  that spans such a seam does not match at all (`docs/stage0/pdf-import.md`).
- Ligatures, hyphenation and non-breaking spaces change the decoded string without changing what a
  human reads. Matching is exact and case-sensitive; it normalises none of that.
- The anchor text becomes a hidden dependency of the layout: a copy edit to the visible wording
  becomes a failed send.

**Use an anchor when the document comes from somewhere else** and the wording is the only stable
handle you have.

**Use `cross_check` when both are true** — you generated the layout *and* you want the coordinates
proved against the words before anybody signs. It is the belt-and-braces option, and it is the one
that catches a template whose layout drifted.

The compatibility difference between the two paths is exactly this: an explicit rectangle cannot
fail to resolve, so a document placed that way has no publish-time or send-time anchor gate to
pass. Everything after resolution is identical, because a resolved field *is* a rectangle.

## Where it lives

| Concern | Code |
|---|---|
| Matching semantics: which match wins, absent, ambiguous | `App\Domain\Preparation\Text\AnchorResolver` |
| Positioned text extraction | `App\Domain\Preparation\TcPdf\TcPdfTextLocator` |
| Field-document rules: what a resolution does to a field | `App\Domain\Preparation\Anchoring\SchemaAnchorResolver` |
| Pairing a field set with a revision's bytes | `App\Domain\Preparation\Anchoring\RevisionAnchorResolver` |
| The envelope's half of it | `App\Domain\Signing\Envelopes\EnvelopeAnchorResolution` |
| The serialised shape | `App\Domain\Preparation\Schema\AnchorPlacement`, `resources/schema/field-schema-1.0.json` |

| Behaviour | Test |
|---|---|
| The stored rectangle is what the resolver returns, on every page geometry | `tests/Unit/Preparation/Anchoring/SchemaAnchorResolverTest.php` |
| Absent, ambiguous, out-of-range, off-page, cross-check | same file, and both feature suites |
| Resolution at publish, and every failure as a 422 with pointers | `tests/Feature/Preparation/TemplateAnchorPublishTest.php` |
| Resolution at send, omission recording, one read of the document | `tests/Feature/Signing/EnvelopeAnchorResolutionTest.php` |
| The facade's create-and-send anchor path | `tests/Feature/Integration/Firma/FirmaCreateAndSendTest.php` |
| The editor's mirror of the same rules | `resources/js/schema/fieldSchema.test.ts` |
