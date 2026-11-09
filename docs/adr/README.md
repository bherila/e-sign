# Architecture decision records

One file per decision, numbered, named `NNNN-short-title.md`. Record the context, the decision,
the alternatives considered, and the consequences. Decisions already made by the specification
(see `docs/HANDOFF.md`) do not need re-recording unless they change.

## Index

| ADR | Decision | Status |
|---|---|---|
| [0001](0001-independent-mit-application.md) | Build an independent MIT-licensed Laravel application, not a fork | accepted, 2026-09-07 |
| [0002](0002-supported-databases.md) | Support MySQL 8 and MariaDB, tested in CI | accepted, 2026-09-07 |
| [0003](0003-seal-material-and-timestamp-authority.md) | First release seals with a self-issued certificate and a public RFC 3161 TSA | accepted, 2026-09-07 |
| [0004](0004-pdf-engine-candidate.md) | tc-lib-pdf is the PDF engine to prove first; alternatives only on a no-go | accepted, 2026-09-07 |
| [0005](0005-lgpl-dependency-handling.md) | LGPL dependency handling: use tc-lib-* unmodified, keep it replaceable | accepted, 2026-09-08 |
| [0006](0006-process-bounded-document-reads.md) | Bound document reads at the process level | accepted, 2026-09-13 |
