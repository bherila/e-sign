# Anchor placement

An anchor is a *request* carried in a field document: find this text, then put the field's
rectangle relative to it. This describes the resolver that answers one — what counts as an answer,
what it records, and what it refuses.

The document shape is [field-schema.md](field-schema.md); this is the semantics behind it.

> **Not yet reachable from the API.** Resolution is not wired into publishing or sending yet, and
> the two anchor options that promise it — `placement: "cross_check"` and `anchor.required: false` —
> are refused at the service boundary with `anchor_resolution_unavailable` until it is. See
> `Schema\AnchorResolutionGate`, which names the branch that removes it.

## What resolution is

`Text\AnchorResolver` matches an exact, case-sensitive string against decoded text runs — a real
content-stream parse, never a regular expression over compressed bytes — and returns where the text
was and where the field goes. `Anchoring\SchemaAnchorResolver` is the field-document half: it walks
a document, resolves every anchored field, and hands back a new document or refuses the old one.

Four rules, and none of them is a preference.

1. **An anchor is searched on the page its field declares.** A field states its page, the page is
   checked against the document, and an anchor that could move a field to another page would make
   that statement a suggestion. Scoping the search is also what lets "matched twice" mean something
   a sender can act on.
2. **`replace` writes the rectangle; `cross_check` checks it.** In `cross_check` the declared
   rectangle survives untouched and a disagreement beyond the tolerance is a refusal — never a
   silent move in either direction.
3. **A resolved rectangle must land on its page.** It is built from where the text turned out to be
   plus the caller's offset, so nothing before resolution knows whether it fits. A field a signer
   cannot reach is refused rather than stored. The *matched text's* own box is not checked that
   way and must not be: a heading's ascender routinely crosses the top of the CropBox.
4. **Absent is only acceptable when the document says so, and only for an optional field.** Then
   the field is omitted rather than placed at its placeholder. Ambiguity is never acceptable.

Every failure in a document is collected, not thrown at the first one, and each names the field,
the anchor text, and what was actually found.

## The geometry, exactly

The resolved rectangle is the corner of the matched text named by `origin`, displaced by `offset`,
at **the field's own width and height**. An anchor decides where a field goes and never how big it
is. That single sentence is the whole placement rule, and `Anchoring\ReceiptVerifier` is it written
as a check.

## A receipt is a record, never a licence to skip

`anchor.resolved` records what resolution found: the digest of the bytes the text was located in,
the page, the occurrence taken, the measured text box, and the resulting placement.

It does not let resolution be skipped. A receipt binds a document, a page and an occurrence — but
not the anchor *text*, the origin corner, or the offset — so honouring one would let an edited
request keep the answer to the question it used to ask, and a required anchor whose text is no
longer in the document would place silently at the old coordinates instead of failing. Resolution
is a pure function of the request and the bytes: re-running it on an unchanged pair costs one parse
and returns the same rectangle.

The receipt is checked against the request that produced it, and the resolver checks **its own**
output before returning it — a failure there is this service contradicting itself, reported as
`anchor_receipt_inconsistent` rather than stored.

### Why the checks live here

They used to live in the field-schema validator, and they did not work there. A receipt is the
output of a computation, and *could the resolver have produced this?* has no referent in a
component that does not contain the resolver: five successive review rounds each found another
property of that output the validator did not know it should assert, because there was nothing to
check the assertions against.

Beside the resolver the question is answerable by asking. `ReceiptVerifierTest` runs the real
resolver over every combination of occurrence, origin, offset, placement and page geometry, and
requires each receipt to satisfy every rule *and* every single-value mutation of it to break one.
A verifier that accepted everything would pass the first half and fail the second.

One limit is worth stating rather than hiding: `anchor_rect` is a measurement, and only the corner
named by `origin` reaches the placement. So a receipt can misreport the *size* of the text it
matched whenever that size does not feed the chosen corner, and the request alone cannot tell —
checking it would need the document, which is the resolver's input and not the receipt's. The
mutation test encodes exactly which combinations can and cannot catch it.

## What is refused

| Code | Refused |
|---|---|
| `anchor_not_found` | a required anchor's text is not on the field's page |
| `anchor_ambiguous` | `occurrence: "sole"` matched more than once |
| `anchor_occurrence_out_of_range` | `occurrence: n` with fewer than *n* matches |
| `anchor_resolved_off_page` | the offset put the rectangle off the page it was found on |
| `anchor_cross_check_failed` | a `cross_check` resolved further than its tolerance from the declared rectangle |
| `anchor_text_unreadable` | the bytes were read and could not be parsed |
| `anchor_receipt_inconsistent` | the resolver produced a receipt that contradicts its own request |

A document that could not be **read** is a different thing from one that could not be parsed:
storage failures raise `AnchorDocumentUnavailable`, which is a retryable server failure rather than
a validation error, and the disk name and object path go to the log rather than to a response.

## The bytes are proved, not assumed

`Documents\RevisionBytes` reads a revision's object and re-hashes it against the row before anyone
measures anything in it. A write-time check cannot see an object replaced afterwards, and measuring
replacement bytes while stamping the recorded digest would invite signers against a document the
envelope is not bound to — with finalization noticing only after signing.

Proven bytes are remembered for the life of the request, which is what lets a caller resolve once
and a later step in the same request resolve again without a second read.
