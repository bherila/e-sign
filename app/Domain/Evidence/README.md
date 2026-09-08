# Evidence

See docs/ARCHITECTURE.md for what this module owns. Keep cross-module calls behind interfaces.

- `Sealing/` — the seal ports and their tc-lib-pdf implementations.
- `Finalization/` — staged artifact publication, evidence export, the staging pruner.
  See docs/evidence/finalization.md.
- `Retention/` — legal hold, the three deletion policies, privacy erasure, artifact integrity
  verification, and the backup manifest / restore drill. See docs/operations/retention.md and
  docs/operations/backups.md.

Two rules hold across the whole module and are the ones most easily lost in a refactor: an
object is garbage by **set membership** against the rows that could reference it and never by
the age of its prefix, and a soft-deleted row still counts as a reference
(docs/BLOB_STORAGE.md).
