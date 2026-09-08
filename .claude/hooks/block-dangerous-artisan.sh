#!/usr/bin/env bash
# Claude Code PreToolUse hook: block unsafe Laravel database commands.
#
# Advisory rules live in AGENTS.md/CLAUDE.md; this hook is the enforcement layer
# for the most dangerous local agent mistakes. It denies the individual Bash
# tool call (PreToolUse permissionDecision=deny) rather than halting the session,
# so the agent can retry with the only permitted form:
#   php artisan migrate --database=sqlite --no-interaction
#
# Design: join line continuations, splice quotes out of words (shell semantics),
# strip word-internal backslash escapes, split into command segments on shell
# separators, drop redirections (keeping the surviving args), stop at comments,
# then check each artisan segment against its OWN flags. Destructive subcommands
# — migrate:* (incl. migrate:status: read-only, but blocked fail-closed to keep
# abbreviation handling simple; use `php artisan migrate --pretend` or ask a
# human), schema:dump (unless --database=sqlite and no --prune), db:wipe, and
# Symfony namespace/command abbreviations of them (migr:f, d:wi, schem:du, ...)
# — are denied; only `migrate` with --database=sqlite and --no-interaction (or
# Symfony's global -n short flag, also in clusters like -qn) and no conflicting
# --database is allowed. A `DB_*=` environment assignment prefixing an artisan
# DB command is denied (it can silently retarget the connection/path that the
# flags appear to pin). `--help` / `-h` / a leading `help` subcommand make the
# segment safe: Symfony renders help instead of executing.
#
# This is defense-in-depth for a cooperative agent, not a sandbox: runtime
# indirection (eval, base64|sh, $(...) producing the command, env-var-stored
# command bodies, xargs feeding args) can still reach the shell and is
# intentionally out of scope — the documented rule + human oversight remain
# the primary guard.
set -euo pipefail

payload="$(cat || true)"
command=""
if command -v jq >/dev/null 2>&1; then
  command="$(printf '%s' "$payload" | jq -r '.tool_input.command // ""' 2>/dev/null || true)"
fi
if [[ -z "$command" ]]; then
  command="$payload"
fi

deny() {
  printf '%s\n' "{\"hookSpecificOutput\":{\"hookEventName\":\"PreToolUse\",\"permissionDecision\":\"deny\",\"permissionDecisionReason\":\"$1\"}}"
  exit 0
}

# 1) Join backslash-newline continuations (bash removes them before tokenizing).
# 2) Strip remaining backslashes: outside quotes bash deletes them, so
#    mi\grate:fresh tokenizes as migrate:fresh.
# 3) Remove quote characters entirely (NOT replace with space): quotes splice
#    into the surrounding word, so mi'grate:fresh' is one word migrate:fresh
#    and --database="sqlite" is --database=sqlite.
norm="${command//\\$'\n'/ }"
norm="${norm//\\/}"
norm="${norm//\'/}"
norm="${norm//\"/}"

verdict="$(printf '%s' "$norm" | awk '
  { all = all $0 "\n" }
  END {
    # Segment on shell separators / subshells (NOT redirections — their trailing
    # args still belong to the same command and must be validated). & also
    # splits &&, &>, 2>&1 harmlessly.
    gsub(/\$\(/, "\n", all)
    gsub(/[;|&()`]/, "\n", all)
    nseg = split(all, segs, "\n")

    dest = 0; bad_migrate = 0; bad_schema = 0; bad_env = 0

    for (s = 1; s <= nseg; s++) {
      m = split(segs[s], tok, /[ \t\r]+/)
      seen = 0; danger = ""; sqlite = 0; nonsqlite = 0; nointer = 0; prune = 0
      expectdb = 0; skipnext = 0; envdb = 0; helpsafe = 0
      for (i = 1; i <= m; i++) {
        t = tok[i]
        if (t == "") continue
        # Comment: rest of the (already separator-split) text is not executed.
        if (t ~ /^#/) break
        if (skipnext) { skipnext = 0; continue }
        # Redirections: drop the operator (and a detached target) but keep the rest.
        if (t ~ /^[0-9]*(>>|<<|>|<)/) {
          if (t ~ /^[0-9]*(>>|<<|>|<)$/) skipnext = 1
          continue
        }
        # Leading DB_* environment assignment (only valid before the command
        # word) can retarget the connection or the sqlite file path.
        if (!seen && t ~ /^DB_[A-Za-z0-9_]*=/) { envdb = 1; continue }
        if (t == "artisan" || t ~ /\/artisan$/) { seen = 1; continue }
        if (!seen) continue
        # A bare --database expects a value, but if the next token is actually a
        # dangerous subcommand, classify it instead of swallowing it as the value.
        if (expectdb) {
          expectdb = 0
          if (!(t ~ /^mi[a-z]*:/ || t ~ /^d[a-z]*:w/ || t == "migrate" || t ~ /^sc[a-z]*:d/)) {
            if (t == "sqlite") sqlite = 1; else nonsqlite = 1
            continue
          }
        }
        # Symfony renders help instead of running the command.
        if (t == "--help" || t == "-h") { helpsafe = 1; continue }
        if (t == "help" && danger == "") { helpsafe = 1; continue }
        if (t == "--no-interaction") { nointer = 1; continue }
        # Short-option cluster: Symfony global -n == --no-interaction (also -qn).
        if (t ~ /^-[A-Za-z]+$/) { if (t ~ /n/) nointer = 1; continue }
        # --prune and its Symfony option abbreviations (--prun, --pru).
        if (t ~ /^--pru/) { prune = 1; continue }
        if (t ~ /^--database=/) { v = substr(t, 12); if (v == "sqlite") sqlite = 1; else nonsqlite = 1; continue }
        if (t == "--database") { expectdb = 1; continue }
        # Destructive: migrate namespace (migrate:* incl. abbrev migr:f) or db:wipe
        # (incl. abbrev d:wi / db:w).
        if (t ~ /^mi[a-z]*:/ || t ~ /^d[a-z]*:w/) { danger = "dest"; continue }
        if (t == "migrate") { if (danger == "") danger = "migrate"; continue }
        # schema:dump and its abbreviations (schem:du, schema:d, ...).
        if (t ~ /^sc[a-z]*:d/) { if (danger == "") danger = "schema"; continue }
      }
      if (!seen || danger == "") continue
      if (helpsafe) continue
      if (envdb) { bad_env = 1; continue }
      if (danger == "dest") dest = 1
      else if (danger == "migrate") { if (!(sqlite && !nonsqlite && nointer)) bad_migrate = 1 }
      else if (danger == "schema") { if (!(sqlite && !nonsqlite && !prune)) bad_schema = 1 }
    }

    if (dest) print "DENY_DEST"
    else if (bad_env) print "DENY_ENV"
    else if (bad_migrate) print "DENY_MIGRATE"
    else if (bad_schema) print "DENY_SCHEMA"
    else print "OK"
  }
')"

case "$verdict" in
  DENY_DEST)
    deny "Blocked destructive database command (migrate:* / schema:dump variants / db:wipe, including Symfony abbreviations). These drop or rewrite data and are never run automatically here; a human must run it deliberately if truly required." ;;
  DENY_ENV)
    deny "Blocked DB_* environment override on a database command (e.g. DB_CONNECTION=... php artisan migrate). The env assignment can silently retarget the connection or sqlite path. Run it without the DB_* prefix: php artisan migrate --database=sqlite --no-interaction." ;;
  DENY_MIGRATE)
    deny "Blocked unsafe migration. Only run migrations when explicitly requested, against SQLite only and non-interactively: php artisan migrate --database=sqlite --no-interaction. Each chained artisan call must carry the flags itself, and no non-sqlite --database is allowed." ;;
  DENY_SCHEMA)
    deny "Blocked unsafe schema dump. Only run when explicitly requested, use --database=sqlite, and never use --prune." ;;
esac

exit 0
