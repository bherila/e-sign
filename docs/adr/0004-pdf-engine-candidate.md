# 0004. tc-lib-pdf is the PDF engine to prove first; alternatives only on a no-go

**Status:** accepted, 2026-09-07 (owner decision)

## Context

The runtime must prepare and seal PDFs in PHP alone. `tecnickcom/tc-lib-pdf` (LGPL-3.0)
documents PDF import and PAdES support. Alternatives include FPDI with TCPDF and the
commercial SetaPDF suite.

## Decision

Prototype tc-lib-pdf exclusively in Stage 0. LGPL as an unmodified Composer dependency is
acceptable for this MIT application; obligations are recorded in `THIRD_PARTY_NOTICES.md`.
Evaluate alternatives only if a Stage 0 gate fails on this engine.

## Consequences

- Stage 0 findings decide go/no-go; no editor or signing work builds on the engine before that.
- Any modification to the library must go upstream or live in a separately licensed fork,
  never vendored into this repository.
