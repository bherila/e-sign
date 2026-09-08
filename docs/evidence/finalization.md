# Finalization and artifact publication

How a signed envelope becomes a published, verifiable executed agreement — and what happens
when that goes wrong half way through.

Issue [#28](https://github.com/bherila/e-sign/issues/28). The rules are
[`docs/ARCHITECTURE.md`](../ARCHITECTURE.md) ("Artifact publication", invariants 5 and 6) and
[`docs/HANDOFF.md`](../HANDOFF.md) sections 8 and 12.

## The problem this shape exists to solve

The database and the object store cannot commit together. Any design that pretends otherwise
ends up in one of two states after a badly timed crash: a completed envelope whose PDF is not
there, or a PDF nobody can find because no row points at it. The first is a lie told to a
signer; the second is a document lost.

So the work is staged, and the stages are arranged so that **every crash point leaves a state
that is either correct or visibly incomplete**. There is no window in which the system claims
completion it cannot back up.

## How finalization starts

Three things, in order of how often each one is what happened
([issue #94](https://github.com/bherila/e-sign/issues/94)).

**The trigger.** `App\Domain\Evidence\Finalization\FinalizationTrigger` is an
`EnvelopeEventSink`, composed into the container's sink alongside the audit store and the
webhook outbox by `EvidenceServiceProvider`. When the state machine publishes
`signing_request.recipient.signed` **and** the envelope it publishes it for is now
`finalizing`, it dispatches `FinalizeEnvelope` with `->afterCommit()`. That pair is the exact
condition: the event alone fires for every acceptance, and the state is already committed by
the time the sink is called, so nothing has to recount outstanding recipients.

`afterCommit()` is not a detail. The sink is called *inside* the acceptance transaction, and a
worker that could see the job before that transaction commits would find an envelope that is
not `finalizing` — or, if the acceptance rolled back, one that never was. Everything else in
the sink is a database write; this is the one member that is not, which is why it is last in
the composite and why its single side effect is deferred.

**The resume sweep.** `esign:finalization:resume`, every five minutes in `routes/console.php`
with `withoutOverlapping()`. A dispatch is not a guarantee of execution: a worker can be killed
before it starts the job, a `jobs` table can come back from a backup without it, a queue can be
purged during an incident. Nothing further ever happens to an envelope in `finalizing`, so no
later transition would notice. The sweep re-dispatches any envelope that has been waiting
longer than `esign.finalization.resume_after_minutes` (default 10) — measured from its newest
`finalization_runs` row, or from the envelope itself if there is none. It is a prompt, not an
authority: a second attempt on an envelope a worker is quietly still sealing loses the
compare-and-swap in step 3 rather than doing any harm.

It deliberately never touches `finalization_failed`. That state is a *visible* failure an
operator retries on purpose (`retryFinalization()` plus a fresh dispatch); sweeping it would
turn one legible failure into a new one every five minutes.

**The `finalization_backlog` readiness probe.** Counts exactly the set the sweep would
re-dispatch, so the two cannot disagree — both read
`App\Domain\Evidence\Finalization\StalledFinalizations`. Any waiting envelope is a `warn`;
more than ten, or one that has waited an hour, is a `fail`. A backlog that survives into the
next scrape means the sweep itself is not running, or the work is not being picked up. See
[`docs/operations/health.md`](../operations/health.md).

## The four steps

`App\Domain\Evidence\Finalization\EnvelopeFinalizer` is the whole of it. The queued entry
point is `App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope`.

```
1. lock envelope ─ require finalizing ─ allocate generation ─ capture immutable input ─ run(started)
                                         (transaction commits; the lock is released)
2. render ─ seal ─ validate ─ hash ─ upload ─ READ BACK and compare digests ─ run(rendered→uploaded)
                                         (no lock held; this is the slow part)
3. lock envelope ─ re-check state, generation, and that nothing is published ─ insert artifact
   rows ─ markCompleted() ─ run(published)   (one transaction; the completion event is in it)

4. any failure after step 1 ─ run(failed, redacted) ─ markFinalizationFailed() ─ publish nothing
```

### Step 1 — capture under the lock

`EnvelopeFinalizer::begin()`, `FinalizationInput::capture()`.

The envelope row is locked, its state must be `finalizing`, a new `generation` is allocated,
and everything the render will need is copied out: the document revision and its digest, the
field-schema digest, one digest per stored field value, the material-values digest, the
assurance level, and every attestation with its chained digest. A `finalization_runs` row is
written in state `started`.

The capture is the reason a concurrent edit cannot change a document between the moment it is
sealed and the moment it is published. It is also what makes recovery decidable: two attempts
with the same snapshot digest were rendering the same document from the same evidence.

The *values themselves* are not persisted on the run row — only their digests. A signature
value is a captured image, and copying it into an operational table would put it somewhere it
does not need to be. They are re-read from `envelope_field_values` when a render is needed,
which is safe because they are frozen by then; if anything did move, the recomputed snapshot
digest differs and nothing is reused.

### Step 2 — render, seal, validate, upload, read back

`EnvelopeFinalizer::prepare()`, outside any lock. Holding a row lock here would block the
transitions that ought to be able to race with it, and sealing at B-T involves a network round
trip to a timestamp authority.

1. The reviewed revision's bytes are read and re-hashed against the digest the envelope froze.
   Everything downstream asserts the document was built from exactly those bytes.
2. `CompletionReport::build()` assembles the report's content;
   `CompletionReportDocument::render()` turns it into a standalone one-page PDF.
3. `ExecutedDocumentRenderer::render()` draws every field value on the reviewed revision at its
   own native rectangle and appends the completion report as the last page, through
   `App\Domain\Preparation\Contracts\PdfAssembler`. The same report bytes are published
   separately, so the page bound into the agreement and the published report cannot disagree.
4. `PdfSealer::seal()` applies the service seal at the envelope's assurance level. It fails
   closed: a level that cannot be reached is an exception, never the level below.
5. `ArtifactValidator::validate()` re-reads the produced bytes. Publication is refused unless
   the artifact's *own* properties reach the requested level. "The backend was asked for
   profile X" is not evidence that the bytes reach profile X.
6. `EvidenceDocument::build()` writes the machine evidence, naming the other two artifacts by
   digest.
7. Each artifact is stored through `ArtifactStore::putVerified()`, which writes, **reads back
   through the same storage adapter, and re-hashes**. A driver returning true means it accepted
   the bytes, not that they are retrievable.
8. `finalization_runs.outputs` is written. That column is the durable-bytes signal: it exists
   only after every object was stored *and* verified.

### Step 3 — publish

`EnvelopeFinalizer::publish()`, in a new transaction. The envelope is locked again and three
compare-and-swap guards are checked:

- the state is still `finalizing`;
- this run's generation is still the highest for the envelope;
- no artifact row exists for the envelope yet.

Then the `artifacts` rows are inserted with `published_at`, and
`EnvelopeStateMachine::markCompleted($executedArtifactPublicId)` runs **inside the same
transaction**, publishing `signing_request.completed` through the event sink. The state change,
the artifact rows, and the completion event are one atomic fact.

Losing one of those guards is a correct outcome, not an error. `PublicationSuperseded` is
handled separately from a real failure precisely so a superseded attempt does not push a
completed envelope into `finalization_failed`.

There is also a database-level backstop: `artifacts` has a unique key on
`(envelope_id, kind)`, and rows are only ever inserted in this transaction.

### Step 4 — failure

The run is marked `failed` with a redacted, length-capped error, and
`markFinalizationFailed()` puts the envelope in `finalization_failed` — visible, retryable,
and never `completed`. An `IllegalTransition` here is expected rather than exceptional: the
envelope may already have been moved by something else, and its current state is then the
legal outcome.

Errors are redacted with the same `TextRedactor` the delivery modules use. A finalization error
can name a storage key (which contains a digest), a certificate subject, or a TSA endpoint.

## Recovery

| Crash point | What is left behind | What the retry does |
|---|---|---|
| before step 1 commits | nothing | the envelope is still `finalizing`; `esign:finalization:resume` dispatches again |
| during render or seal | run `failed`, no objects | re-renders from the same immutable input |
| after upload, before publish | run `failed` **with `outputs`**, objects in storage, no rows | **republishes those exact bytes** |
| after publish commits | everything | step 1 refuses: the envelope is no longer `finalizing` |

Retrying is `EnvelopeStateMachine::retryFinalization()` followed by a fresh dispatch — an
explicit decision, which is why the job sets `tries = 1`. An automatic queue retry would find
the envelope no longer `finalizing` and fail again for a different, more confusing reason.

The republish path re-derives each key from its own digest, confirms every object is still
present, and re-hashes it before publishing. It does not re-seal: a second seal would burn a
second timestamp, write a second set of objects, and give the agreement a different digest from
the one the crashed attempt had already proved durable.

## Storage, staging, and the pruner

Keys are content-addressed and scoped by workspace and envelope
(`App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey`):

```
envelopes/{workspace public id}/{envelope public id}/{kind}-{sha256}.{pdf|json}
```

Different bytes are a different key, so a write can only replace an object with itself. "New
artifact keys only" is a property of the layout rather than a rule anyone has to remember.

**There is no separate staging prefix, deliberately.** An object uploaded in step 2 already
sits at the key it will be published under; what makes it *staging* is that no row points at
it. That is a fact `esign:artifacts:prune-staging` can check, whereas "in a staging folder"
would be a convention it would have to trust.

```bash
php artisan esign:artifacts:prune-staging                       # dry run, reports only
php artisan esign:artifacts:prune-staging --older-than=7d --apply
```

Four rules, each because its absence deletes evidence:

1. **Set membership, not age.** An object is a candidate only when no `artifacts` row names its
   disk and path. Age is a second filter on candidates, never the test itself.
2. **Read through the query builder**, so no model scope can hide a row and condemn its bytes.
3. **A minimum age**, because an object exists before the row that references it — that is the
   whole shape of the staged publication. Seven days by default, far longer than any
   finalization takes.
4. **Dry run by default.** `--apply` is required.

The prefix is enumerated in code and is never taken from the caller. Completed evidence is
never garbage-collected by prefix age, and `artifacts` rows refuse both update and delete.

## What each digest covers

Stated here, in `evidence.json`'s own `covers` fields, and in the export manifest — because a
digest whose subject is unstated proves nothing.

| Digest | Covers |
|---|---|
| `document_sha256` | the complete bytes of the reviewed document revision, as retained, and as every attestation binds it — **not** the executed PDF |
| `field_schema_sha256` | the canonical JSON of the field schema copied onto the envelope at creation |
| `material_values_sha256` | the canonical encoding of the shared agreement content only; signer-specific values are excluded by design |
| `field_value_sha256:<id>` | the canonical JSON encoding of one stored field value |
| `attestation_sha256:<id>` | one acceptance: envelope and recipient ids, the document/schema/material digests, the consent version displayed, the session it was given in, the server acceptance time, the verification method, minimized client evidence, and the previous acceptance's digest |
| `artifact_sha256:executed_pdf` | the complete published bytes of the sealed executed PDF, computed **outside** that PDF |
| `artifact_sha256:completion_report` | the complete published bytes of the standalone report |
| `seal_certificate_sha256` | the DER of the certificate the CMS verified against |

An object store's ETag is never any of these. An ETag is a transfer checksum whose algorithm
depends on how the object was uploaded; `EnvelopeFinalizationTest` greps the whole of `app/`
for the word and fails if it appears.

The evidence document's own digest is not inside it, and the executed PDF's digest is not on
the completion page bound into that PDF. Both would be self-referential.

## Three timestamps, kept apart

| Fact | Where | Whose clock |
|---|---|---|
| acceptance | `recipient_attestations.accepted_at`, `timestamps.acceptance[]` | this server, when assent was recorded |
| sealing | `timestamps.sealing.sealed_at` | this server, when the seal was applied |
| timestamp token (B-T only) | inside the CMS | an external authority, and only as far as it is trusted |
| publication | `artifacts.published_at` and the `signing_request.completed` audit event | this server, when the rows committed |

`timestamps.publication` in `evidence.json` is deliberately `null`, with the reason stated in
the file: the document is written and durably stored *before* publication, so a publication
time inside it would be a prediction.

## The three artifacts

| Kind | Format | What it is |
|---|---|---|
| `executed_pdf` | PDF | the reviewed revision with every field value drawn on it, the completion report appended, sealed under the service certificate |
| `completion_report` | PDF | the same report content on its own, for a reader who should not have to open the agreement |
| `evidence_json` | JSON | the canonical machine record: `evidence_version: 1`, every digest and what it covers, the attestation chain, the distinct timestamps, and an explicit list of limitations |

The completion report is labelled, in as many words on the page itself, as a report and **not**
a certificate. It says the people named provided electronic signatures and assent and that the
service then sealed the result under its own organizational certificate; it makes no eIDAS
advanced or qualified claim.

## Getting evidence back out

- `ArtifactDownloader::stream()` streams one artifact through the application with a content
  type fixed by its kind and a filename built from the envelope title as an ASCII slug plus a
  digest prefix. Nothing presigns; an unpublished artifact is refused.
- `EvidenceExporter::bundle()` writes a ZIP containing the original upload, the reviewed
  revision, the executed PDF, the evidence JSON, the completion report, the seal certificate
  chain, the validation report, and a `manifest.json` listing every file with its SHA-256 and
  what that digest covers.

The manifest is **not** signed, on purpose: signing an inventory of its own evidence with the
same key adds no independent assurance. The seal that matters is inside the executed PDF.

Neither has a route. The HTTP surfaces own their own authorization and call these once a policy
has run.

## Assurance policy at send time

`SealMaterialAssurancePolicyCheck` is the bound `AssurancePolicyCheck`. It runs the cheap
configuration check (`ConfiguredSealAssurancePolicyCheck` — are the paths set, do the files
exist, is a TSA named) and then `PdfSealer::preflight()`, which constructs the seal material.
Constructing it *is* the gate: an expired certificate, a key that does not match its
certificate, a wrong passphrase, and an unsupported digest algorithm are each a typed
exception. For B-T it also asserts the timestamp authority is configured and passes the
outbound destination policy.

All of it is local — a file read, an OpenSSL parse, a URL and DNS check. No timestamp authority
is contacted, so inviting a signer never waits on a third party. The alternative is discovering
at finalization that the certificate expired last week, after everyone has already signed.

## Tests

`tests/Feature/Evidence/EnvelopeFinalizationTest.php` is the release gate.

| Test | Pins |
|---|---|
| `it_publishes_three_artifacts_and_completes_the_envelope` | the happy path, content-addressed keys, `artifact_ref` naming the executed artifact |
| `the_published_pdf_is_sealed_validated_and_verifiable_by_openssl` | the artifact validates, and its CMS also verifies through PHP's OpenSSL binding — a second, independent implementation |
| `the_executed_document_carries_the_field_values_and_the_completion_page` | field values are drawn; the report is the last page and is the published report |
| `the_evidence_document_digests_match_the_published_bytes` | every digest in `evidence.json` is the digest of the bytes that were published |
| `a_failure_while_rendering_publishes_nothing` | crash before upload: run failed, envelope `finalization_failed`, no rows, no objects |
| `a_crash_after_upload_is_recovered_by_republishing_the_same_bytes` | crash after upload: durable bytes and no rows; the retry publishes those bytes and stores nothing new |
| `two_concurrent_attempts_publish_exactly_once` | two interleaved attempts; exactly one publishes, the loser records itself and leaves the envelope alone |
| `an_envelope_that_leaves_finalizing_mid_attempt_is_never_published` | a cancel is refused while finalizing, and an envelope that leaves `finalizing` is never published into |
| `b_t_without_a_timestamp_authority_fails_closed` | a level that cannot be met is an error, never a downgrade |
| `completion_is_published_only_after_every_artifact_reads_back` | the *ordering*, observed with a spy storage adapter and a spy event sink sharing one log |
| `no_application_code_uses_an_etag_as_a_digest` | an ETag never becomes a document hash |

`tests/Feature/Evidence/EvidenceDeliveryTest.php` covers the downloader, the export bundle, and
the pruner. `tests/Feature/Evidence/SealKeyManagementTest.php` covers the key-id recording and
the status command. `tests/Feature/Evidence/ArtifactStorageTest.php` covers the key layout and
the read-back refusal.

### Independent validation

`scripts/validate-seal.sh` validates `tests/Fixtures/validation/finalized-executed.pdf` with
pyHanko under the strict `fixture-only` trust mode. That artifact is produced by the whole of
this pipeline — a genuinely signed envelope, field values drawn, completion report appended,
then sealed — rather than by sealing a synthetic input directly, so the external validator sees
what a deployment actually publishes.

It is deterministic in the way that matters (same document, same key, same anchor, same
verdict every run) but not byte-stable: each seal carries a fresh signing time and fresh
document identifiers. That is true of every artifact in that directory, which is why they are
regenerated rather than diffed:

```bash
ESIGN_WRITE_VALIDATION_FIXTURES=1 php artisan test --filter=ValidationFixturesTest
scripts/validate-seal.sh
```

## Related

- [`docs/operations/seal-key-management.md`](../operations/seal-key-management.md) — rotation,
  expiry, compromise.
- [`docs/operations/retention.md`](../operations/retention.md) — legal hold, the three deletion
  policies, and `esign:artifacts:verify`, which re-reads everything this pipeline published.
- [`docs/operations/backups.md`](../operations/backups.md) — what to back up, and the restore
  drill that re-verifies every artifact against its recorded digest.
- [`docs/stage0/sealing.md`](../stage0/sealing.md) — what the sealing path was measured to do,
  and its open gaps.
- [`docs/signing/state-machine.md`](../signing/state-machine.md) — the transitions this module
  drives.
- [`docs/BLOB_STORAGE.md`](../BLOB_STORAGE.md) — why nothing presigns.
