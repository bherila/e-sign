# Signing

See [docs/ARCHITECTURE.md](../../../docs/ARCHITECTURE.md) for what this module owns. Keep
cross-module calls behind interfaces.

The lifecycle, the transition table, the compare-and-swap strategy, the freeze rules, the
event names, and what is deferred to other issues are documented once, in
[docs/signing/state-machine.md](../../../docs/signing/state-machine.md).

## Layout

| Directory | Contents |
|---|---|
| `Contracts/` | The module's ports: `EnvelopeEventSink`, `AssurancePolicyCheck`. |
| `Envelopes/` | The state machine, the factory, the snapshot it copies, and the result and request value objects. |
| `Fields/` | Materiality, value validation, canonical encoding, the material digest, client-evidence minimization. |
| `Models/` | `Envelope`, `EnvelopeRecipient`, `EnvelopeFieldValue`, `RecipientAttestation`. |
| `Assurance/` | The default `AssurancePolicyCheck`, reading the same seal configuration the sealer reads. |
| `Exceptions/` | One catchable class per refusal, each with a stable `code()`. |

Nothing here performs HTTP, sends mail, opens a PDF, or touches a key. The ports are wired in
`App\Providers\SigningServiceProvider`.

## One state machine

`EnvelopeStateMachine` is the only thing that changes an envelope's state. The models hold
rows and their invariants; a helper on a model that quietly moved a state would be a second
state machine, which AGENTS.md rules out. Every transition is a transaction plus
`lockForUpdate()` plus `UPDATE ... WHERE version = <expected>`, so the guard holds on SQLite —
where the lock compiles to nothing — as well as on MySQL and MariaDB.

## What other modules implement

| Interface | Implemented by | Contract |
|---|---|---|
| `Contracts\EnvelopeEventSink` | Delivery (webhook outbox, issue #29) | Called **inside** the transition's transaction. Must be a database write and nothing else. |
| `Contracts\AssurancePolicyCheck` | Evidence, when the sealing worker owns the answer | Returns null when the level's material is declared available, otherwise the reason. Never null on uncertainty. |
| `Envelopes\EnvelopeSourceSnapshot` | Preparation (templates, issue #22) | `TemplateVersion::snapshotForEnvelope()` produces the array `EnvelopeSourceSnapshot::fromArray()` accepts. |

## Immutability

Three things are written once and refuse to change: the envelope's copied snapshot columns,
`recipient_attestations`, and (already) `document_revisions`. All three enforce it in the
model rather than with a database trigger, because triggers are not portable across SQLite,
MySQL, and MariaDB. A deployment that wants the guarantee against a compromised application
grants its database user INSERT and SELECT on the append-only tables and nothing else.
