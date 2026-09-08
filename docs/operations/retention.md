# Runbook: retention, legal hold, and privacy deletion

What this deployment deletes, what it refuses to delete, and what only a human can decide.

Issue [#40](https://github.com/bherila/e-sign/issues/40). The rules are `docs/HANDOFF.md`
section 12 and [`docs/BLOB_STORAGE.md`](../BLOB_STORAGE.md). The code is
`app/Domain/Evidence/Retention/`.

## The one-paragraph version

Three policies, configured separately. Authentication logs and abandoned drafts have finite
defaults and are swept by `esign:retention:run`. **Executed agreements are never deleted
automatically** until an operator sets `ESIGN_RETENTION_EXECUTED_DOCUMENTS_DAYS` to a
reviewed value. A legal hold on an envelope overrides every one of them. Deleting an
executed agreement is two passes with a grace period in between, so the decision stays
reversible for thirty days. Erasing a person's contact details never touches their
signature.

## Policies

All four live in `config/esign.php` under `retention`, each with an `ESIGN_RETENTION_*`
environment variable.

| Setting | Default | What it governs |
|---|---|---|
| `auth_logs_days` | `400` | Rows in auth-laravel's `auth_audit_log`: logins, passkeys, 2FA. |
| `abandoned_drafts_days` | `90` | Envelopes in `draft` with a null `sent_at`, and documents no envelope or template version references. |
| `executed_documents_days` | **`null` — never** | Completed envelopes. Null means no automatic deletion, ever. |
| `purge_grace_days` | `30` | How long a soft-deleted envelope keeps its bytes. |

They are three policies and not one because they protect different things. An
authentication row is operational security history and nothing depends on it. An abandoned
draft went to nobody, so there is nothing to prove about it. An executed agreement is the
instrument, and both parties rely on it.

### Why `executed_documents_days` ships unset

Because only you know the answer. The window comes from the jurisdictions the agreements
were signed under and from whatever retention the counterparties agreed to contractually,
and no default can guess either. `docs/HANDOFF.md` section 12 is explicit: *"Default to no
automatic deletion of executed documents until an operator has configured a reviewed
policy."*

An unset value is therefore not an oversight waiting to be filled in with something
plausible. Setting it is a decision to destroy signed agreements on a schedule, and it
should be recorded somewhere outside this repository — who reviewed it, against what
requirement, and when it is next revisited.

`''`, `0`, and any negative number all read as *unset*, so a half-finished `.env` edit
cannot switch deletion on by accident.

## Commands

```bash
php artisan esign:retention:run --dry-run        # report; change nothing
php artisan esign:retention:run                  # apply exactly what the dry run printed
php artisan esign:retention:purge-blobs --dry-run
php artisan esign:retention:purge-blobs          # remove bytes past the grace period

php artisan esign:hold:place   <envelope> --reason="Preservation notice 2026-14"
php artisan esign:hold:release <envelope> --reason="Matter closed"

php artisan esign:privacy:erase-recipient <recipient> --reason="Article 17 request 2026-88"

php artisan esign:artifacts:verify                       # every published artifact
php artisan esign:artifacts:verify --workspace=<ulid> --since=-30days
```

`esign:retention:run` and `esign:retention:purge-blobs` prompt for confirmation when
`APP_ENV=production`; pass `--force` for a scheduled run. `--dry-run` and a real run share
one planner, so what the dry run printed is what the real run does — there is no second
code path that could disagree.

`esign:artifacts:prune-staging` is a different command and belongs to finalization, not
retention: it reclaims objects that no `artifacts` row references, and it has never removed
published evidence. See [`docs/evidence/finalization.md`](../evidence/finalization.md).

## What a sweep does, pass by pass

### Authentication rows

Deletes `auth_audit_log` rows older than the window, in chunks of a thousand so the first
sweep on a long-running deployment does not hold locks for minutes. Writes one
`retention.auth_logs.deleted` audit event with the count.

### Abandoned drafts

Two things go, and both are hard-deleted — rows *and* bytes, because a draft nobody sent is
not evidence:

- Envelopes in `draft` with a null `sent_at`, older than the window, together with their
  recipients and stored field values. An envelope that was sent and then cancelled or
  expired is **not** an abandoned draft: it went to somebody and is history.
- Documents older than the window whose revisions no `envelopes.document_revision_id` and no
  `template_versions.document_revision_id` names.

A draft that somehow carries an attestation or a published artifact is skipped whatever its
state says. That combination should be impossible, and "impossible, therefore delete it" is
not a trade the sweep makes.

Rows are deleted before bytes, deliberately. The reverse order can leave a row pointing at
an object that is not there, which is a document lost; this order can leave an object no row
points at, which is a re-run away from tidy.

**A soft-deleted row still counts as a reference.** A document soft-deleted through the UI,
and the review revision of an envelope retention has soft-deleted but not yet purged, are
both protected. This is `docs/BLOB_STORAGE.md`'s rule, and breaking it would make a
reversible soft delete permanent.

### Executed agreements

Only when the policy is set. For each completed envelope whose `completed_at` is older than
the window and which is not under legal hold:

1. A `retention.executed_envelope.deleted` audit event is written, naming **every artifact
   digest** scheduled for destruction.
2. The envelope is soft-deleted. It disappears from every listing, API surface, and
   download.

Both happen in one transaction, so either the trail says an agreement was scheduled for
destruction and it was, or neither happened.

The `artifacts` rows are never touched — the model refuses updates and deletes. After the
purge, the row still records the kind, the digest, the byte length, the seal key id, the
seal certificate digest, and the validation report of an agreement whose bytes are gone.
Retention destroys the document, not the evidence that there was one.

### The second pass

`esign:retention:purge-blobs` removes the objects, and only for envelopes soft-deleted
longer than `purge_grace_days`. Nothing else makes an object eligible:

- **Never prefix age.** `docs/HANDOFF.md` section 12 forbids garbage-collecting completed
  evidence by prefix age, and an artifact key carries the envelope's ULID rather than a date,
  so the age of a prefix is not even available to be misused.
- **Never a soft-deleted `documents` row.** A user can soft-delete a document, and a soft
  delete is meant to be reversible.
- **Never a held envelope**, re-checked at the moment the purge runs. The grace period is
  long enough for a preservation notice to arrive during it, which is the point.

**To undo a retention decision during the grace period**, place a hold (which stops the
purge immediately) and then clear `deleted_at` on the envelope row. There is no command for
the second half on purpose: restoring an agreement retention destroyed is not a routine
operation and should leave a trace in whatever you use to run SQL.

## Legal hold

```bash
php artisan esign:hold:place 01J... --reason="Preservation notice 2026-14"
```

`legal_hold_at`, `legal_hold_reason`, and `legal_hold_by` on `envelopes`. Every deletion
path in the Evidence module consults them, every placement and release writes an audit
event, and both commands require `--reason`.

**Be precise about what it is.** It is *this application's* deletion restriction: a column
the code reads. It is **not** bucket-enforced legal hold and **not** WORM. Garage's
documented S3 implementation has neither Object Lock nor object versioning, and AGENTS.md
forbids describing the storage as though it did. Anyone with database access and a
willingness to write SQL can clear the column.

What the hold does buy:

- No routine path removes a held agreement — not the sweep, not the blob purge, not a
  privacy erasure.
- The placement and the release are both on the record, with a reason and an actor.
- Placing a hold twice is refused, so `legal_hold_at` — often the fact that matters, the
  date preservation was first required — is never overwritten.
- Releasing copies every cleared column into the audit event, because after the release the
  row has forgotten it was ever held.

Resistance to a *determined* deletion comes from independently administered off-host copies.
See [`backups.md`](backups.md).

A sweep reports the number of held envelopes it excluded rather than silently passing over
them. "Nothing was deleted because everything is held" and "nothing was deleted because
nothing was old enough" are different facts.

## Privacy erasure

```bash
php artisan esign:privacy:erase-recipient 01J... --reason="Article 17 request 2026-88"
```

### What is erased

| Row | Column | Becomes |
|---|---|---|
| `envelope_recipients` | `name` | `Erased recipient` |
| `envelope_recipients` | `email` | `erased-<recipient ulid>@erased.invalid` |
| `envelope_recipients` | `identity_snapshot` | a marker recording that it was erased |
| `outbound_mails` | `to_email`, `to_name`, `subject` | the same tombstones |
| `outbound_mails` | `context.recipient_name`, `context.actor_name` | `Erased recipient` |

The address uses the reserved `.invalid` TLD (RFC 2606), so it is unroutable by
construction, and it embeds the recipient's own ULID so two erasures on one envelope cannot
collide. A blank email would be indistinguishable from a bug.

The message rows themselves stay, with their states and timestamps. That a message was sent
is not personal data, and destroying the record of the contact is not what an erasure
request asks for.

### What is not erased, and why

**Signatures are not erased.** This is the part to be able to explain, because somebody will
eventually ask for it.

A signed agreement holds two things that look alike. One is contact data: the mailbox an
invitation went to, the display name on a reminder, the details captured to decide who was
let in. That has no evidential role once the agreement is executed, and it is what an
erasure request is about. The other is the instrument: the attestation chain, the digests it
binds, the field values drawn on the document, and the sealed executed PDF. Those are not
the service's record *about* a person; they are the agreement that person entered into.

Erasing an attestation would not remove personal data from an executed contract. It would
break the chain that proves the contract was agreed while leaving the contract itself in
force — worse for everybody, including the person asking.

Concretely, and each for its own reason:

- **`recipient_attestations`.** Immutable and chained: each row includes the digest of the
  previous acceptance, so altering any field invalidates every later link. They hold no
  contact details in the first place — an attestation binds recipient *ids*, digests, a
  consent version, a session reference, and hashed client evidence.
- **`envelope_field_values`, including the captured signature image.** Content of the
  agreement. `material_values_sha256` covers the shared ones and the executed PDF was
  rendered from all of them; changing one would leave every digest in `evidence.json`
  describing bytes that no longer exist.
- **The executed PDF, the completion report, and the evidence document.** Published,
  content-addressed, immutable. The name on the signature line is part of the instrument.

The audit event records which columns were rewritten, how many message rows were touched,
the reason, and an explicit list of what was retained and why. It never records the values
that were erased — an erasure whose own trail quotes the address has erased nothing.

An erasure on a held envelope is refused. Running it twice is refused, so the trail cannot
imply two requests where there was one.

## Integrity verification

```bash
php artisan esign:artifacts:verify
```

Streams every published artifact back through the storage adapter, recomputes its SHA-256,
compares it to the row, and re-runs `ArtifactValidator` on the executed PDFs. Exit status is
non-zero the moment anything does not match. `routes/console.php` runs it weekly (Sunday
03:10) and each run is recorded in `artifact_verification_runs`.

The digest is always recomputed and never taken from an ETag: an ETag is a transfer checksum
whose algorithm depends on how the object was uploaded, which `docs/HANDOFF.md` section 12
forbids using as this application's document hash.

It skips artifacts belonging to a soft-deleted envelope. Those bytes were removed on
purpose, and reporting them as missing would make a correctly applied retention policy look
like data loss.

An in-process validator is a self-check, not an independent one — it shares a library with
the sealer. What it adds over the read-back check at publication time is elapsed time:
publication proved the bytes were durable that day, and this proves they still are.

The `artifact_integrity` readiness probe reads the last recorded run; see
[`health.md`](health.md).

## What is never automatic

- **Deleting an executed agreement.** Not until an operator sets a reviewed policy, and even
  then only through `esign:retention:run`, which never runs itself.
- **Removing the bytes of a soft-deleted envelope.** A second command, after a grace period.
- **Clearing a legal hold.** Only `esign:hold:release`, with a reason.
- **Erasing a recipient.** One recipient at a time, by public id, with a reason.
- **Deleting `artifacts` rows, `document_revisions` rows through their model, or
  `esign_audit_events`.** All three refuse.
- **Restoring an agreement retention removed.** Deliberately manual; see the grace-period
  note above.

## Scheduling

`routes/console.php` schedules `esign:artifacts:verify` weekly and nothing else from this
module. The two retention commands are deliberately **not** scheduled by default: a
deployment that has not configured a policy has nothing for them to do, and a deployment
that has should decide for itself when destruction happens. To schedule them, add to
`routes/console.php`:

```php
Schedule::command('esign:retention:run', ['--force'])->dailyAt('02:30')->withoutOverlapping();
Schedule::command('esign:retention:purge-blobs', ['--force'])->dailyAt('03:30')->withoutOverlapping();
```

Run the purge after the sweep, not before. The grace period means the ordering cannot cause
a same-day destruction, but reading the pair in schedule order is how the next operator
understands them.

## Audit actions this module writes

| Action | Written by |
|---|---|
| `retention.legal_hold.placed` | `esign:hold:place` |
| `retention.legal_hold.released` | `esign:hold:release` |
| `retention.auth_logs.deleted` | `esign:retention:run` |
| `retention.abandoned_drafts.deleted` | `esign:retention:run` |
| `retention.executed_envelope.deleted` | `esign:retention:run`, with every artifact digest |
| `retention.artifact_blobs.purged` | `esign:retention:purge-blobs`, with every purged digest |
| `retention.recipient.erased` | `esign:privacy:erase-recipient` |

All of them land in `esign_audit_events`, which is append-only through its model. A
deployment that wants that enforced rather than asserted grants the application's database
user INSERT and SELECT on that table and nothing else.

## Related

- [`backups.md`](backups.md) — what to back up, and the restore drill.
- [`health.md`](health.md) — the `artifact_integrity` probe.
- [`docs/evidence/finalization.md`](../evidence/finalization.md) — artifact publication and
  the staging pruner.
- [`docs/BLOB_STORAGE.md`](../BLOB_STORAGE.md) — why an object is garbage by set membership
  and never by age.
