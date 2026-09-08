# 0001. Build an independent MIT-licensed Laravel application, not a fork

**Status:** accepted, 2026-09-07

## Context

DocuSeal, Documenso, and OpenSign are credible self-hosted alternatives. All are AGPL-family
licensed, and none is a PHP/Laravel foundation that fits a cPanel-capable deployment. The
first consumer needs a Firma-compatible HTTP surface, not a different product's API.

## Decision

Write the application independently under MIT. Reuse libraries under their own licenses; the
first PDF engine candidate is `tecnickcom/tc-lib-pdf` (LGPL-3.0), which must be proved in
Stage 0 before the editor is built. Never embed AGPL application code.

## Consequences

- We own the signing pipeline and its security model end to end.
- LGPL obligations (notices, source availability, replaceability) attach to distribution.
- The product cannot honestly be called a drop-in DocuSign clone; it targets a narrow NDA
  release first.
