# Documents: intake, retention, and the review revision

How an uploaded PDF becomes something that can be prepared for signing, what is kept, where
the bytes live, and what "normalization" is allowed to do to them.

Implements issue #19. Read with [docs/BLOB_STORAGE.md](../BLOB_STORAGE.md) (rule 1 in
particular), [docs/stage0/pdf-import.md](../stage0/pdf-import.md) for the preflight verdicts,
and sections 6, 7, and 12 of [docs/HANDOFF.md](../HANDOFF.md).

## Lifecycle

```
upload -> stage & hash -> preflight -> [rejected]  status=preflight_failed, original retained
                                    \- [accepted]  store original + review -> status=ready
```

Concretely, `App\Domain\Preparation\Documents\DocumentIntake` does this, in this order:

1. **Stage and hash.** The upload is copied to a temporary path the process owns, and its
   SHA-256 and length are computed during the copy. Every later comparison is against that
   digest. Nothing trusts a length or a type supplied by the client.
2. **Preflight.** `PdfPreflight` parses the document for real, with the ceilings from
   `config('esign.documents')`. A MIME check is not preflight; the Form Request's
   `mimetypes:application/pdf` rule is a cheap first gate and nothing more.
3. **Review revision.** For an accepted document, `ReviewNormalizer` decides whether
   anything needs to be done before the document is shown for assent. Normally nothing does,
   and the review revision is the original bytes with the original digest.
4. **Store and verify.** Both objects are written to the private `documents` disk and then
   **read back through the storage adapter and re-hashed**. A driver returning "written" is
   not evidence that the bytes are retrievable.
5. **Record.** The `documents` row, both `document_revisions` rows, and the audit event are
   written in one transaction.

Storage happens before the database on purpose. A failure then leaves unreferenced objects,
which the orphan pruner reclaims; the opposite order would leave rows pointing at bytes that
never arrived, which nothing can repair. This is the staged pattern from
[docs/ARCHITECTURE.md](../ARCHITECTURE.md).

### Statuses

| `documents.status` | Meaning |
|---|---|
| `uploaded` | Created; the review revision has not been recorded yet. Every document passes through this state inside the intake transaction, so a row resting here means something is wrong. Never shown for assent. |
| `preflight_failed` | Terminal. The original is retained; no review revision exists and none will. |
| `ready` | Original retained, review revision recorded. The document can be prepared. |

## Key layout

```
documents/{workspace public id}/{document public id}/{kind}-{sha256}.pdf
```

Built only by `DocumentStorageKey`, which refuses any segment that is not a plain
alphanumeric identifier and any digest that is not 64 lowercase hex characters. Nothing in a
key comes from an uploaded filename.

Three consequences, each load-bearing:

- **Nothing is ever overwritten.** Different bytes produce a different key, so a write can
  only replace an object with itself. "Retain the original byte-for-byte" is a property of
  the layout, not a rule someone has to remember.
- **A key is self-verifying.** The object's contents must hash to the digest in its own name.
  That is what the read-back check asserts.
- **The prefix is a tenancy boundary.** Retention, export, and orphan sweeps can work per
  workspace without a join.

The original and the review revision get separate objects even when their bytes are
identical, which costs one duplicated object per document. That is deliberate: one revision
row points at exactly one object, so deleting or exporting a revision never has to reason
about whether another row shares its bytes.

### The disk

The `documents` disk (`config/filesystems.php`) is private, is never served by the
framework's storage route, and is never presigned. Its driver is `local` or `s3` per
`ESIGN_DOCUMENTS_DRIVER`; the disk *name* is stable, and no application code branches on the
driver. The local root is `storage/app/documents`, which the deploy workflow's
`rsync --delete` already excludes via `/storage/app/*` — moving the root outside that prefix
means adding an exclude in the same commit, or the next green deploy silently deletes every
uploaded agreement.

### Downloads

Two routes, because a response carries one `Content-Disposition` and an `<iframe>` needs the
inline one:

- `GET /workspaces/{workspace}/documents/{document}/revisions/{revision}/download` — attachment
- `GET .../view` — inline, for the locally served PDF.js viewer

Both stream `readStream()` through the application after the workspace policy has run, with
a fixed `application/pdf` content type, `X-Content-Type-Options: nosniff`, and a filename
slugged from the document title. There is no presigned URL anywhere, on any driver, and
`tests/Unit/Preparation/DocumentsAreNeverPresignedTest.php` fails the build if one appears.

## What "normalization" may do

> Any normalization happens before review, is disclosed to the sender, and is traceable to
> the original. — HANDOFF §7

**Today, on an accepted document, normalization does nothing, and that is a fidelity
decision rather than an omission.** The review revision is the uploaded bytes, carries the
same SHA-256 as the original, and its revision row records:

```json
{
  "applied": false,
  "steps": [],
  "summary": "None. The review revision is the uploaded file, byte for byte, and carries the same SHA-256 digest as the original.",
  "disclosures": [],
  "source_sha256": "…"
}
```

The reason is that the only transformation available is a full re-import through
`PdfAssembler`, and on the engine measured in
[docs/stage0/pdf-import.md](../stage0/pdf-import.md) that is lossy: it drops annotations and
`/AcroForm` (finding 3), drops `/UserUnit` (finding 2), and emits fresh document identifiers
and timestamps on every run (finding 6). Running it over a document that needs nothing done
to it would lose visible content and produce a review revision that cannot be reproduced
from the original. Refusing to normalize by default is therefore the honest choice, not the
lazy one.

The rebuild path is nevertheless built, switchable, and tested, because the first genuinely
required normalization — AcroForm flattening is the expected one — has to run somewhere.
Enabling `ESIGN_DOCUMENTS_NORMALIZE_REBUILD_PAGES` makes every accepted document go through
the importer, and the revision then records:

```json
{
  "applied": true,
  "steps": ["rebuild_pages"],
  "engine": "tc-lib-pdf",
  "summary": "Every page was re-imported and the document rewritten, so the review revision has a different SHA-256 from the original. …",
  "disclosures": ["Link, widget and markup annotations are not carried …", "…"],
  "source_sha256": "…",
  "engine_warnings": []
}
```

### How it is disclosed

`normalization` is returned verbatim on `GET /workspaces/{workspace}/documents/{document}`,
alongside the full preflight report including its warnings. The sender therefore sees, in
one response and before anything is sent for signature: both digests, whether the review
revision differs from what they uploaded, which steps ran, and what each step costs.

### What normalization is not

It is not sanitization, and it must never be described as one. Hazardous structures are
*rejected* by preflight before any of this runs; nothing here makes an unsafe document safe.
HANDOFF §7 is explicit: either reliably normalize an allowed feature before review, or reject
it with an actionable message — a generic "sanitization pass" claim is not available.

## Rejections

Every rejection carries a message written for the person who uploaded the file, saying what
is wrong and what to do about it. The classes, from the Stage 0 fixture matrix:

| Code | Why it is refused |
|---|---|
| `encrypted` | The parser cannot decrypt, so nothing derived from the document would be trustworthy. |
| `already_signed` | Importing would rebuild the pages and invalidate the existing signature. Executed files belong in retention, not preparation. |
| `javascript` | The script would not run in the signing view and its effect on visible content cannot be reproduced. |
| `xfa` | XFA form fields would be lost. |
| `embedded_file` | Attachments are not carried into the signed document and would silently disappear. |
| `launch_action` | Asks a reader to run an external program. |
| `unparseable` | Anything that cannot be classified is refused, never passed through. |
| `size_limit_exceeded`, `object_limit_exceeded`, `page_limit_exceeded` | Resource ceilings from `config('esign.documents')`. Until issue #108 a page count over the ceiling was reported as `invalid_page_geometry`; that code now means only a page tree that could not be read. |
| `invalid_page_geometry` | The page tree does not describe pages — no `/Pages`, a cycle, a page box that cannot exist. |
| `decompression_limit_exceeded` | A compressed stream expands past what one stream, or the whole document, is allowed to produce. See the limits table below. |
| `time_budget_exceeded`, `memory_budget_exceeded` | The backstops. Preflight ran longer, or grew further, than one document is allowed to. |

### Why a rejected upload is still retained

A document that fails preflight keeps its original bytes, gets a row with status
`preflight_failed`, and stores the report. The upload endpoint answers 422 with the
actionable findings and the document that was created.

The alternative — storing only the report — was rejected because the report describes bytes
nobody can then examine. An operator investigating "the system rejected our contract" needs
the file, and so does anyone triaging a deliberately malformed upload. The digest in the
report is only checkable against an artifact that still exists.

The costs are real and are accepted explicitly:

- rejected files consume storage and fall under the same retention policy as any other
  document. They are not exempt from a workspace's deletion or export decisions.
- a rejected document is terminal. It has no review revision and can never gain one, because
  nothing may be shown for assent that preflight did not accept.
- the upload Form Request refuses an oversized body *before* intake runs, so the retention
  rule cannot be turned into an unbounded storage sink by uploading one enormous file.

## Limits

These are not preflight's limits, they are the deployment's: every place that reads a document
applies the same set. Three do — `TcPdfPreflight` when an upload is inspected, `TcPdfTextLocator`
when text is located, and `TcPdfAssembler` when a document is imported — and each
resolves them from the same container binding rather than from a built-in default. A ceiling that
one honoured and another defaulted would mean a deployment could accept a document at upload and
refuse the same bytes at the next step that touched it.

Whoever supplies the budget is told when a ceiling stops the read. A caller that supplies one is
accounting for the cost and can act on the difference between "too expensive here" and "this file
is broken"; a caller that supplies none is still read under the configured limits, but sees only
the ordinary "could not be read" failure, because it never asked to account for the cost and would
not catch an exception about it.

**The import engine is the one reader that cannot be handed a budget.** tc-lib-pdf parses the
source again with a parser it builds itself, keeping only two of the options it is given. The
assembler covers it from outside: the geometry read and the import share one budget per document,
whose time and memory backstops are consulted between imported pages; the import is pinned to not
decoding page content; and what it does decode is the same bytes, through the same filters, that
the budgeted geometry read already decoded — at a per-stream ceiling the assembler keeps equal to
the engine's by refusing any `max_decoded_stream_bytes` above it.

`tests/Feature/Preparation/DocumentReadBudgetTest.php` holds the list of readers and fails both
when a known one stops honouring the configuration and when a new one is added that defaults.

`config/esign.php` → `documents`, all overridable per deployment:

| Setting | Default | Enforced by | What it rejects |
|---|---|---|---|
| `max_bytes` | 32 MiB | Form Request (`max:` in KB) **and** the preflight parser | An upload larger than the ceiling, before it is parsed. `size_limit_exceeded`. |
| `max_pages` | 500 | Every page-tree walk: preflight, text extraction, assembly | A page tree with more pages than the ceiling. `page_limit_exceeded`. |
| `max_objects` | 100,000 | Every parse, **while the document is read** | A document that declares or materializes more indirect objects than the ceiling. `object_limit_exceeded`. |
| `max_decoded_stream_bytes` | 32 MiB | Every parse, per decoded stream | One stream that inflates past the ceiling. `decompression_limit_exceeded`. Cannot be raised above 32 MiB or set to 0: the import engine re-reads each document under that fixed ceiling, so the assembler refuses a value it could not keep. |
| `max_decompressed_bytes` | 256 MiB | Preflight, aggregated over the document | Every decoded stream in one document added up. `decompression_limit_exceeded`. |
| `preflight_time_budget_seconds` | 30 | Preflight, between units of work | Backstop. `time_budget_exceeded`. |
| `preflight_memory_budget_bytes` | 256 MiB | Preflight, between units of work | Backstop. `memory_budget_exceeded`. |
| `allowed_mimetypes` | `application/pdf` | Form Request, against the sniffed type | Anything that does not sniff as a PDF. |

### How the decompression ceilings relate

**The aggregate one is the control.** A per-stream ceiling bounds one stream and nothing
else, so sixteen streams each just under 32 MiB are ~500 KB on the wire and ~500 MB
retained — a small upload that exhausts a 512 MB `memory_limit` synchronously inside a web
request. That was findings U-1 and U-2 in
[docs/security/review-2026-09.md](../security/review-2026-09.md), and
`max_decompressed_bytes` is what closes it: the running total is charged as each stream is
decoded, and the remaining budget is handed to the filter layer as its output cap, so an
over-budget stream is refused by zlib rather than inflated and then measured.

`max_decompressed_bytes` defaults to **8× `max_bytes`**. The multiple has to be large
enough that no legitimate document reaches it — Flate content streams expand by roughly an
order of magnitude, and DCT/JPX image payloads are handed back untouched — and small enough
that the whole budget still fits inside the 512 MB `memory_limit` the shipped `php.ini`
sets. 20× would be 640 MiB and could never bind before the process died, which is the
failure the ceiling exists to prevent.

**The time and memory budgets are backstops, not controls.** They exist for the shapes the
byte and object budgets do not model: pathological tokenizer input, a filter chain that is
slow rather than large, an amplification inside the parser library that never reaches this
application's counters. Set them generously and tune the byte and object budgets instead —
a symptom makes a poor gate, because memory usage depends on everything else the request
has already done and a wall clock rejects a legitimate document on a loaded host. Either
can be switched off with `0`, which leaves the byte and object budgets in force.

### Tuning

Raise a ceiling with the matching `ESIGN_DOCUMENTS_*` variable in `.env`; every value is
read through `config('esign.documents')` and resolved once in
`App\Providers\PreparationServiceProvider`, so nothing has to be changed in two places.
Two relationships are worth keeping when you do:

- keep `max_decompressed_bytes` **above** `max_decoded_stream_bytes`, or the per-stream
  ceiling can never bind and only the aggregate message is ever produced;
- keep `max_decompressed_bytes` comfortably **below** the process `memory_limit`. The
  budget bounds what preflight retains, not what the rest of the request has already
  allocated.

Object-graph recursion depth is not configurable: the hazard walk fixes its recursion depth
at 32 and the parser refuses object nesting past 256. The other defaults come from the
measured cost table in [docs/stage0/pdf-import.md](../stage0/pdf-import.md) and are expected
to move once there is a corpus of real uploads.

### The bomb corpus

`tests/Fixtures/pdf/bombs/`, generated by `php tests/Fixtures/pdf/generate-bombs.php`, is
synthetic documents that each cost far more to read than to store:

| Fixture | On disk | What it proves |
|---|---|---|
| `single-stream-bomb` | ~13 KB | One Flate stream inflating to 12 MiB is refused without being materialized. |
| `many-small-streams-bomb` | ~18 KB | 16 streams of 1 MiB each: every one is under any sane per-stream ceiling, and only the aggregate budget refuses the 16 MiB total. This is U-1 stated as a fixture. |
| `deep-nesting-bomb` | ~1 KB | 400 levels of nested arrays fail closed inside the parser rather than recursing until the stack decides. |
| `object-count-bomb` | ~0.5 KB | A cross-reference stream declaring 5,000,000 entries in 20 bytes is refused while the cross-reference data is read, not after the entries are built. This is U-2. |
| `large-legitimate-control` | ~69 KB | 40 pages of ordinary contract prose, ~1.6 MiB decoded, is still accepted. A budget that rejects this one is set wrong. |
| `overflowing-transform` | ~2 KB | Accepted: preflight bounds the document, not the arithmetic inside it. Text extraction multiplies the page's transforms past the double range and must leave as an unreadable document, not an uncaught geometry error. |
| `long-shown-string` | ~2 KB | Accepted. One `Tj` showing a one-megabyte string: text extraction decodes its glyphs in slices under the document's budget rather than expanding the whole string in one unmetered step. |
| `operand-flood` | ~2 KB | Accepted. Half a million numbers and no operator, so no operation is ever yielded: the tokenizer charges its own tokens. |
| `cmap-flood` | ~2 KB | Accepted. A font whose `/ToUnicode` map is a megabyte of entries is lexed under the same budget as the page that uses it. |

The last three are accepted by preflight on purpose, and are refused — under a budget small
enough to prove it — by `DocumentReadBudgetTest`: their cost is inside one operation, which is
where text extraction, not preflight, spends it.

`tests/Feature/Preparation/PdfPreflightLimitsTest.php` runs them against a deliberately
small profile — a 4 MiB aggregate budget against a 2 MiB per-stream ceiling — because
proving the mechanism at 256 MiB would mean inflating 256 MiB inside a paratest worker whose
own `memory_limit` is 128 MB, to learn what a 4 MiB budget already shows. The shipped numbers are pinned separately in
`DocumentIntakeTest::test_the_shipped_ceilings_are_the_documented_ones`.

## Authorization

- Uploading takes `createEnvelopes` — sender and above. An auditor can read a document's
  metadata and download its revisions but cannot upload one.
- Reading metadata and downloading bytes take `view`, which every workspace role has.
- Route parameters are resolved through `Workspace::whereMemberOf()`, so a workspace the
  caller is not a member of is a **404**, not a 403: an outsider must not be able to
  distinguish a workspace they cannot see from one that does not exist. A document is looked
  up inside the resolved workspace and a revision inside the resolved document, so an
  identifier from another tenant is a 404 at every level.
- 403 is reserved for the case it actually describes: a member of this workspace whose role
  does not carry the permission.

## Immutability

`document_revisions` refuses updates and deletes at the model level, like the audit event
store, and for the same reason a trigger is not used: triggers are not portable across
SQLite, MySQL, and MariaDB. A correction is a new revision. This matters because invariant 2
in [docs/ARCHITECTURE.md](../ARCHITECTURE.md) binds an acceptance to a specific review
revision — a mutable revision would silently move what a signer agreed to.

`documents.workspace_id` and `document_revisions.document_id` are both `RESTRICT`, per the
no-cascade rule in the memberships migration: executed agreements outlive the membership
that created them and the workspace that held them. Documents are soft-deleted, which is why
the orphan pruner must read this table through the query builder rather than Eloquent —
`SoftDeletes` would hide trashed rows and condemn their bytes.
