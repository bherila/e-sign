# 0005. LGPL dependency handling: use tc-lib-* unmodified, keep it replaceable

**Status:** accepted, 2026-09-08

## Context

The application is MIT (`docs/adr/0001-independent-mit-application.md`). The Stage 0 PDF engine
candidate, `tecnickcom/tc-lib-pdf` and its 13 `tc-lib-*` companions, is LGPL-3.0-or-later
(`docs/adr/0004-pdf-engine-candidate.md`). Those 14 packages are the only copyleft code in the
production inventory.

An MIT application may use an LGPL library without relicensing itself, but only if the library
stays a *separate, replaceable* work. The LGPL conditions that bite on distribution are notices,
source availability for the library, and the recipient's ability to swap in a modified version
of the library and still run the program. We distribute in two shapes — a Docker image and a
cPanel bundle — so both have to satisfy those conditions, and the conditions have to be checked
by something other than memory.

Two alternatives were considered and rejected. Vendoring a patched copy of the engine into the
repository would put LGPL source inside an MIT tree and make every future change a licensing
question; it is already forbidden by ADR 0004. Replacing the engine with a permissively licensed
or commercial one (FPDI/TCPDF, SetaPDF) is a Stage 0 fallback, not a reason to abandon a
candidate that has not failed a gate.

## Decision

Use the `tc-lib-*` family as an **unmodified Composer dependency**, and keep it replaceable.

1. **No modification, no vendoring.** No `tc-lib-*` source is copied, patched, or forked into
   this repository. Any fix goes upstream, or lives in a separately licensed fork that is itself
   consumed as a Composer package. `vendor/` is never edited and never committed.
2. **Source availability.** `composer.lock` is committed, so the exact distributed version of
   every `tc-lib-*` package is identified. Upstream source is
   <https://github.com/tecnickcom/tc-lib-pdf> and the sibling repositories in the same
   organisation. `THIRD_PARTY_NOTICES.md` carries the link; a recipient can obtain
   byte-identical source for the version they received.
3. **Replaceability in both distribution shapes.** The Docker image and the cPanel bundle ship
   `vendor/` as an ordinary directory tree: no phar packing, no bytecode compilation, no
   stripping, no static linking equivalent. A recipient relinks by changing the requirement in
   `composer.json`/`composer.lock` and re-running `composer install`. Nothing of ours needs
   rebuilding.
4. **Notices travel with the artifacts.** `THIRD_PARTY_NOTICES.md` and `LICENSE` are included in
   both distribution shapes: the Docker image copies the repository root in its `production`
   stage, and the cPanel bundle lists both files explicitly in the rsync payload
   (`.github/workflows/deploy.yml`). The upstream `LICENSE` file in each `vendor/tecnickcom/*`
   directory travels with them. The cPanel bundle previously omitted the two root files; that
   was fixed as part of this decision.
5. **The engine sits behind our own interfaces.** Domain code depends on our PDF abstractions,
   not on `tc-lib-*` types, so replacement is a real option rather than a theoretical one. This
   is also what makes the ADR 0004 fallback executable if a Stage 0 gate fails.
6. **LGPL is allowlisted for `tecnickcom/*` and nowhere else.**
   `scripts/check-licenses.php` accepts LGPL only under that package glob. Any other package
   arriving under LGPL fails CI until someone records the decision here. AGPL, GPL (non-Lesser),
   unknown, and unlisted licenses always fail.
7. **Honest labelling.** The library is LGPL and is described as LGPL. The MIT license on our own
   code is not represented as covering it.

## Consequences

- The MIT license on original application code is unaffected, provided points 1–5 hold. They are
  enforced by review, by ADR 0004's no-vendoring rule, and by the `licenses` CI job.
- Modifying the engine is a deliberate, expensive act: it means an upstream contribution or a
  separately licensed fork, not a local patch. That is the intended friction.
- The distribution artifacts must keep `vendor/` inspectable and replaceable. Any future
  optimisation that packs, compiles, or strips `vendor/` breaks the replaceability condition and
  requires this ADR to be revisited first.
- Dropping the engine (the ADR 0004 no-go path) removes the only copyleft obligation in the
  production tree. The allowlist entry should be removed with it.
- A new copyleft dependency cannot arrive quietly; the license gate is the tripwire.
