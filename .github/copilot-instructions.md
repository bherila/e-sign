# Copilot instructions

Read `AGENTS.md` (project contract), `TESTING.AGENTS.md` (validation contract), and
`docs/HANDOFF.md` (specification) before proposing changes. The non-negotiables in `AGENTS.md`
apply to every suggestion: one signing state machine, fail closed, no bespoke cryptography,
honest assurance language, fixture-backed coordinate handling, harmless GETs, byte-for-byte
retention of originals, synthetic fixtures only, identity bound on issuer + subject.
