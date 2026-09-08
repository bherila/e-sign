# Envelope state machine

One authority decides what an envelope may do next:
`App\Domain\Signing\Envelopes\EnvelopeStateMachine`. The administrative UI, the guest signing
UI, the native API, and the Firma-compatible facade all call it. None of them holds a rule of
its own — AGENTS.md: "Never put signing rules in a compatibility controller."

| | |
|---|---|
| Service | `app/Domain/Signing/Envelopes/EnvelopeStateMachine.php` |
| Models | `app/Domain/Signing/Models/` (`Envelope`, `EnvelopeRecipient`, `EnvelopeFieldValue`, `RecipientAttestation`) |
| Ports | `app/Domain/Signing/Contracts/` (`EnvelopeEventSink`, `AssurancePolicyCheck`) |
| Tests | `tests/Feature/Signing/`, `tests/Unit/Signing/` |
| Specification | `docs/HANDOFF.md` sections 6 and 8; `docs/ARCHITECTURE.md` invariants 1–7 |

## States

```
draft ──send──▶ sent ──▶ in_progress ──▶ finalizing ──▶ completed
                  │           │                │
                  │           │                └──▶ finalization_failed ──retry──▶ finalizing
                  └───────────┴──▶ cancelled | declined | expired
```

| State | Meaning |
|---|---|
| `draft` | Being prepared. Nobody is invited; content is fully editable. |
| `sent` | Invitations issued, no recipient has acted yet. |
| `in_progress` | At least one recipient has submitted a value or accepted, but not all have signed. |
| `finalizing` | Everyone signed. Rendering, sealing, validation, and publication are pending. |
| `completed` | The final PDF exists, was validated, is durably stored, and is retrievable. |
| `cancelled` | Withdrawn by the sender before completion. |
| `declined` | A recipient refused. |
| `expired` | The expiry computed at send arrived before everyone signed. |
| `finalization_failed` | Finalization was attempted and did not succeed. Retryable, never completion. |

`completed`, `cancelled`, `declined`, and `expired` are terminal. `finalization_failed` is
not, and that is the point: a run that did not produce a durable, validated, retrievable PDF
stays visibly failed rather than becoming a completed row with nothing behind it.

## Transitions

The table below is the code's own table, `EnvelopeStateMachine::TRANSITIONS`, and
`EnvelopeTransitionMatrixTest::test_the_documented_transition_table_matches_the_code()` fails
if the two drift. Every pair not listed is refused with `IllegalTransition`; the same test
attempts all 79 of them.

<!-- transitions:start -->
| Transition | Legal from |
|---|---|
| `send` | `draft` |
| `submit_values` | `sent`, `in_progress` |
| `set_sender_values` | `draft`, `sent`, `in_progress` |
| `accept` | `sent`, `in_progress` |
| `decline` | `sent`, `in_progress` |
| `cancel` | `draft`, `sent`, `in_progress` |
| `expire` | `sent`, `in_progress` |
| `freeze_for_parallel` | `sent`, `in_progress` |
| `mark_completed` | `finalizing` |
| `mark_finalization_failed` | `finalizing` |
| `retry_finalization` | `finalization_failed` |
<!-- transitions:end -->

Two of these are not state changes on their own. `submit_values` and `set_sender_values`
write field values and advance the envelope's `version`; `submit_values` also moves `sent` to
`in_progress`, because `sent` stops being an accurate description the moment somebody acts.

`cancel` is deliberately absent from `finalizing`. By then everybody has signed and an
artifact is being produced from their acceptances; cancelling would either discard an
executed agreement or race the finalizer for the right to describe the same envelope.

`expire` additionally refuses an envelope whose `expires_at` is null or still in the future.
Expiry is a fact about the clock, not a button. A sender stopping an envelope early is
cancelling it, which records a reason and says so.

## Recipients

Recipient progress is modelled independently of envelope completion (`docs/HANDOFF.md`
section 6). A recipient is `pending`, `active`, `signed`, or `declined`.

- **Sequential** — `send()` activates stage 1 of the schema's `signing_order`. Each
  acceptance activates the next stage once no recipient in the current one is still
  outstanding.
- **Parallel** — `send()` activates everyone, and freezes all material values first. The
  envelope factory refuses parallel mode with a multi-stage `signing_order`: honouring one
  and dropping the other would be a silent no-op.

Only an `active` recipient may submit values, accept, or decline. A later signer's invitation
resolves from the moment the envelope is sent — plenty of deployments deliver all the mail at
once — so the ordering guard lives in the domain, never in whether a link works.

## Compare-and-swap

Every transition does the same four things:

1. Open a transaction.
2. Re-read the row with `lockForUpdate()`.
3. Check the caller's expected `version` against the row's, then check the transition is legal
   from the state the row actually holds.
4. Write with `UPDATE ... WHERE version = <expected>`, and assert exactly one row changed.

Steps 2 and 4 are not redundant. `lockForUpdate()` compiles to an empty string on SQLite
(`SQLiteGrammar::compileLock()`), so on the development and test engine the version predicate
is the only thing between two callers; on MySQL 8 and MariaDB 11 the lock serialises them and
the predicate confirms it. Writing the guard so it holds *without* the lock is what makes one
implementation correct on all three engines (`docs/adr/0002-supported-databases.md`).

`version` defaults to the value on the model instance the caller passed in, so acting on a
model read before somebody else changed it fails rather than silently overwriting. A caller
that wants "whatever is true now" re-reads the row first, which is a visible decision. The
loser of a race gets `StaleEnvelope` — or `StaleReview` for an acceptance, which is the same
failure with a meaning attached — and is never told it succeeded.

Recipients carry their own `version`, so two recipients acting at once do not contend on one
counter. Locks are always taken envelope-first, then recipient, so two transitions cannot
deadlock by approaching the same pair from opposite ends.

Because the guard needs no real concurrency to exercise, the race tests do not use threads:
they load two model instances, transition one, and assert the other's attempt loses.

## Freezing and materiality

The native field schema has no per-field "signer specific" flag, and adding one would be a
schema version bump plus a new way for a sender to mislead a signer. It has something better,
a closed list of field *types*, so `App\Domain\Signing\Fields\FieldMateriality` derives the
classification from the type and it is the same for every document.

| Type | Class |
|---|---|
| `signature`, `initials`, `name`, `title`, `company` | signer-specific |
| `signing_date` | signer-specific, and supplied by the service from the owner's attestation |
| `text`, `checkbox` | **material** |
| `agreement_date` | **material** |

Material values are covered by `material_values_sha256` and frozen at the first acceptance
(or at `send()` in parallel mode). After the freeze, only a signer-specific field of a
recipient who has not signed may still be written — by that recipient, or by the sender for
a prefill. `text` and `checkbox` are material even when a later signer owns them: free text
on an agreement changes what the agreement says, and a tick box beside a term is the term
being accepted.

That conservative reading has a consequence the send gate handles rather than deferring: a
required material field whose owner only acts after the freeze can never be completed. Rather
than let the envelope deadlock in front of a person with an uncompletable form, `send()`
refuses it with `required_material_field_unfillable` and asks the sender to supply a value.

## Send gate

`send()` reports every problem at once, the way the field-schema importer does, and the way
the Firma profile's two-phase validation surfaces them.

| Code | Refused |
|---|---|
| `no_recipients` | an envelope nobody is asked to sign |
| `recipient_missing_email` | a recipient row with no usable address |
| `recipient_has_no_required_field` | a recipient with nothing required of them |
| `required_read_only_field_missing_value` | a required read-only field nobody can fill |
| `required_material_field_unfillable` | a required material field its owner will never be allowed to fill |
| `assurance_material_unavailable` | the requested assurance level's material is not declared available |

The last one comes from the `AssurancePolicyCheck` port. `docs/HANDOFF.md` section 9 requires
rejecting absent, unusable, or mismatched signing material *before* inviting signers, and
never downgrading a requested level to one that can be met. The default implementation reads
the same `config('esign.seal')` and `config('esign.tsa')` the sealer reads; it checks that the
material is configured and readable, not that it is cryptographically sound, which the health
probe does on a schedule and the sealer does at seal time.

## Acceptance and evidence

An acceptance writes one `recipient_attestations` row and that row *is* the acceptance. Every
fact it binds is a digest rather than a reference, so nothing it describes can move
underneath it: the document bytes, the field schema, the material values, the consent version
displayed, and the session it was given in.

The order of checks in `accept()` is deliberate:

1. **Idempotency first.** A retry necessarily arrives against a version its own predecessor
   moved, so a staleness check ahead of the replay lookup would make invariant 7
   unimplementable. Same session and same material digest returns the existing attestation,
   publishes nothing, and reports `replayed`.
2. **Version compare-and-swap**, which decides races.
3. **Consent**, **eligibility**, **required fields**, then the **reviewed material digest**.

`prev_attestation_sha256` chains each acceptance to the previous one on the same envelope.
The chain shows the sequence has not been edited by something that did not also recompute it.
It lives in a database the application can write to, so it is **not** an independent witness,
and `docs/HANDOFF.md` section 8 requires saying so rather than implying tamper-proof storage.
Off-host checkpoints and signer copies are what would make it one, and they are separate work.

`client_evidence` is minimized by an allowlist (`ip`, `user_agent`, `accept_language`,
`client_timezone`, `channel`), not by a scrubber: an allowlist only has to be right about
what it keeps. It corroborates a session and is never identity proof.

## Events

Published to `EnvelopeEventSink` **inside the transaction that made the change**, so an event
and its state change commit or roll back together. An implementation must therefore be a
database write and nothing else; the webhook outbox (issue #29) is exactly that shape —
record a row now, deliver it afterwards. The default binding writes to the append-only
`esign_audit_events` store.

| Transition | Event | Source |
|---|---|---|
| envelope created | `signing_request.created` | Firma profile |
| `send()` | `signing_request.sent` | Firma profile |
| `accept()` | `signing_request.recipient.signed` | Firma profile |
| `decline()` | `signing_request.recipient.declined` + `esign.envelope.declined` | profile + ours |
| `markCompleted()` | `signing_request.completed` | Firma profile |
| `cancel()` | `signing_request.cancelled` | Firma profile |
| `expire()` | `signing_request.expired` | Firma profile |
| `markFinalizationFailed()` | `esign.envelope.finalization.failed` | ours |

Two names are ours because the profile has none.
`docs/compatibility/firma-capability-matrix.md` records (D12) that the profile has
`signing_request.recipient.declined` but no envelope-level declined event, even though
`status.declined` and `timestamps.declined_on` both exist; emitting a fabricated
`signing_request.declined` would put a name on the wire nobody subscribes to. And the profile
has no concept of a visibly failed finalization at all.

`signing_request.completed` is published from `markCompleted()` and nowhere else — later than
upstream publishes it, deliberately, because AGENTS.md allows a completion event only after
the final PDF is generated, validated, durably stored, and retrievable. The capability matrix
already marks that row "intentionally different".

## Invariants to tests

| Invariant (`docs/ARCHITECTURE.md`) | Where it is proven |
|---|---|
| 1. Own fields only, only while eligible | `EnvelopeFieldValuesTest`, `EnvelopeSigningOrderTest` |
| 2. Acceptance binds revision, material, consent, session | `EnvelopeAcceptanceTest` |
| 3. Required fields, consent, ordering validated server-side | `EnvelopeSendGateTest`, `EnvelopeAcceptanceTest` |
| 4. Content frozen after the first acceptance | `EnvelopeFieldValuesTest`, `EnvelopeSendGateTest` |
| 5. Completion needs a durable artifact reference | `EnvelopeFinalizationTest` |
| 6. Races resolve to one legal outcome | `EnvelopeRaceTest` |
| 7. Retries produce one logical acceptance | `EnvelopeAcceptanceTest` |
| One transition table | `EnvelopeTransitionMatrixTest` |
| Events are transactional | `EnvelopeEventSinkTest` |

## Deferred

This module stops where the next issue starts, and takes what it cannot verify as an opaque
argument rather than pretending to check it.

| Deferred to | What |
|---|---|
| **#25 guest access** | Recipient credentials and sessions. `sessionRef` is an opaque string here; issuing, scoping, expiring, and rotating it — and keeping GET harmless — belongs there. |
| **#26 capture and consent** | Typed and drawn signature adoption, the accessible alternative, controlled image re-encoding, and rendering the consent text. `FieldValueValidator` checks only that a value is the right *kind of thing*; it never decides that a canvas represents intent. |
| **#28 finalization** | Rendering, sealing, validation, digesting, upload, read-back, and publication. `markCompleted()` takes an `artifactRef` this module cannot verify, which is exactly why completion is something the finalizer asserts after the fact. The artifacts table, the generation id, and crash recovery live there. |
| **#29 webhook outbox** | Delivery. This module records events; nothing here talks to a network. |
| **templates (#22)** | `EnvelopeSourceSnapshot` is the meeting point. `envelopes.source_template_version_id` is provenance and carries no foreign key until the template tables exist. |

`signing_date` fields are deliberately never written into `envelope_field_values`: the value
is the owning recipient's `recipient_attestations.accepted_at`, and a value the service
derives from an immutable attestation should be derived, not copied into a mutable table
where the two could disagree. Rendering it is #28's job.
