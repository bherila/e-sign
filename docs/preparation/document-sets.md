# Document sets (issue #163)

An envelope, or a template version, may carry an **ordered set of up to 20 PDF documents**
instead of one. This page is the design; `docs/HANDOFF.md` §6 points here.

## The model

| Thing | Where |
|---|---|
| The set's members, in order | `envelope_documents` / `template_version_documents`: one row per document, `position` from 1, the **review** revision, its SHA-256, its page count and its first absolute page |
| A single-document envelope | no rows: the set is `envelopes.document_revision_id` alone, exactly as before |
| The set as a value | `App\Domain\Preparation\Documents\DocumentSet` |

A single-document envelope is not migrated or re-described. Every reader asks
`DocumentSet::forEnvelope()` / `::forTemplateVersion()`, which returns the rows when there are
some and a set of one built from the envelope's own revision when there are none.

## Pages are absolute

A field's `page` counts across the whole ordered set: the first document's pages are 1..n, the
second's start at n+1. This is upstream's contract ("the absolute page numbers of its fields")
and it is why the field schema does **not** change. The schema still declares one coordinate
space and 1-based pages; it does not list the documents. The set's boundaries are recorded once,
on the set rows, and nothing else stores them, so the two cannot disagree. Geometry validation,
anchor resolution, the signing page and the renderer all read pages through the set.

## What an acceptance binds

`envelopes.document_sha256` is what every attestation binds (`AttestationDigest`). For a set of
two or more it is the **set digest**:

```
sha256( canonical({ "encoding": "esign.document_set.v1",
                    "documents": [ { "position": 1, "sha256": "<review sha256>" }, ... ] }) )
```

So an acceptance covers every document, in order, and moving, removing or replacing any one of
them changes the digest. A set of one is not re-hashed: its `document_sha256` is the revision's
own digest, so every existing envelope, attestation and evidence document keeps its meaning.

## What finalization publishes

| Artifact | `kind` | `slot` | Sealed |
|---|---|---|---|
| The executed agreement: every document's pages, in order, with every field drawn, then the completion report | `executed_pdf` | 0 | yes |
| Each document on its own: its pages with its fields drawn, then the completion report | `executed_document` | the document's `position` | yes |
| The completion report | `completion_report` | 0 | no |
| The evidence document | `evidence_json` | 0 | no |

Every sealed artifact is read back and validated at the requested assurance level before
anything is published, as the single executed PDF always was: a set of N documents publishes
N + 1 sealed PDFs or none. A single-document envelope publishes exactly what it did before; its
executed PDF already is the document on its own.

The evidence document lists the set (each document's position, title, original and review
digests, page range) and the digest of each per-document artifact, so a reader holding one
document's sealed copy can tie it to the agreement.

## Limits

At most 20 documents and 100 MB per set, upstream's limits. Each member is a document that passed
intake and preflight on its own; the set does not re-admit them.

## Surfaces

- **Native API:** `POST /api/v1/envelopes` accepts `document_ids` (an ordered list) in place of
  `document_id`. Envelope reads carry `documents[]`.
- **Native API:** artifacts list each `executed_document` with its `document_position`.
- **Firma facade, read side:** `documents[]` on `GET /signing-requests/{id}` and
  `GET /signing-requests/{id}/documents/{document_id}/download?type=original|document`. For a set
  the top-level `document_url` is null. See the capability matrix for what differs from upstream.
- **Firma facade, write side (a follow-up):** `document_ids` on create, upload by
  `POST /documents` (streamed through the application, never a pre-signed URL), and editing a
  draft's set. Each answers `501` by name until then.
- **Templates:** a draft version takes `additional_document_ids` after `document_id`; the editor
  shows every document, its pages numbered across the set.
