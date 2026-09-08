# CLAUDE.md

@AGENTS.md
@TESTING.AGENTS.md

## Claude Code session rules

- `AGENTS.md` is the project contract; `TESTING.AGENTS.md` is the validation contract;
  `docs/HANDOFF.md` is the specification.
- Keep workstation preferences and personal overrides out of committed files; use a local gitignored file if needed.
- Run the changed-area gate from `TESTING.AGENTS.md` before opening a PR or pushing for review.
- Do not run migrations or schema dumps unless the user explicitly asks. The committed hook also blocks unsafe forms of those commands.
- Prefer the `gh` CLI for all GitHub interactions.
- This repository is public. Never paste infrastructure detail (hosts, IPs, buckets, credentials, other tenants) into commits, issues, or PRs.
