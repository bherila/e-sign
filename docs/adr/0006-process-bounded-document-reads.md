# 0006 — Bound document reads at the process level

**Status:** accepted, 2026-09-13. Tracks issue #112.

## Context

Every read of an uploaded PDF — preflight, text extraction, assembly — is bounded today by
`PreflightBudget`: code that does work reports it (`scan`, `expand`, `step`), and the budget
checks its time and memory backstops at intervals (#109). That bounds only the work a loop
*reports*. Four review rounds on #109 and the first round on #113 kept finding loops that did
not: an inline-image scan that returned without reporting, a CMap destination unpacked whole
before the budget saw it, and `array_filter`/`usort` over a run set, which are native calls
nothing in PHP can interrupt. Each was fixable. None of the fixes ends the class: cooperative
metering is only as complete as the last loop somebody remembered.

`docs/HANDOFF.md` section 13 is explicit about the deployment side. Worker `--max-time` is not a
per-job interrupt; large unsupported PDFs must fail before invitations, not hang after assent;
and per-document limits must be validated on the shared-hosting profile, "including a host
without pcntl/process-spawning capabilities when claiming that support". So a mechanism that
only works when a host can spawn processes cannot be the only mechanism.

Reads happen synchronously in web requests (upload intake, the Firma facade's page geometry and
field placement) as well as in queue jobs (finalization). A queue-only answer would leave the
uploads, which are exactly where hostile bytes arrive.

## Decision

**Every document read runs through one isolation layer, in one of three modes**, set by
`esign.documents.isolation.mode`:

| Mode | Behaviour |
|---|---|
| `auto` (default) | A child process where the host can start one; otherwise in-process. |
| `process` | Always a child process. A host that cannot start one refuses to read documents. |
| `in_process` | Today's behaviour: the cooperative budget alone. |

**The child is lean.** A dedicated entrypoint loads Composer's autoloader and the domain's PDF
adapters, and nothing else: no framework boot, no database, no configuration read. It receives
the operation, the deployment's limits and the document bytes on standard input, runs the same
tc-lib-pdf adapter the in-process path runs, and writes the result to standard output as
serialized value objects, which the parent unserializes against an explicit class allowlist.

**The limits are hard.** The parent starts the child with `-d memory_limit` set from the
configured memory backstop plus headroom, and kills it when the wall-clock backstop plus
headroom expires. Neither depends on any loop reporting anything.

**Failures keep their names.** A killed child is `time_budget_exceeded`; a child that died of
memory exhaustion is `memory_budget_exceeded`; a ceiling the child's own budget reached comes
back with that ceiling's code; anything else is the port's ordinary unreadable-document failure.
Who is told about a ceiling is unchanged: a caller that supplied a budget hears the ceiling, a
caller that did not sees the unreadable-document failure.

**The cooperative budget stays.** It is what turns "this cost too much" into a named, actionable
ceiling (`decompression_limit_exceeded`, `object_limit_exceeded`, ...) before the hard limit is
reached, and it is the whole bound on an in-process host. The process limit is the safety
control; the budget is the explanation.

**The PHP binary is configured, not guessed.** Under a web SAPI, `PHP_BINARY` names the FPM or
LSAPI handler rather than a CLI, and on shared hosting the CLI and the web handler can be
different installations entirely. `esign.documents.isolation.php_binary` names the CLI to run.
Left unset, `auto` looks for one and, finding none it can run, falls back to in-process rather
than failing every upload.

**`esign:doctor` reports the mode it will actually get.** A `document_isolation` probe starts a
trivial child under the configured limits: `ok` when it runs and honours them, `warn` when
`auto` has fallen back to in-process, `fail` when `process` is required and unavailable.

## Alternatives considered

- **`pcntl_alarm` / `SIGALRM` inside the request.** Needs pcntl, which PHP-FPM and LSAPI
  commonly lack, and a signal handler runs only between opcodes, so it does not interrupt a long
  native call either — the exact case that motivated this.
- **`set_time_limit` / `max_execution_time`.** A fatal error, not a refusal: the request ends
  as a 500 with nothing recorded, and a queue job ends as a failure the lease retries.
- **Isolating only queue work.** Uploads and the facade read documents synchronously in the web
  request, so this would leave hostile bytes unbounded where they arrive.
- **Requiring process isolation everywhere.** The strongest guarantee, but a shared-hosting
  account without process spawning could then not process documents at all, which the cPanel
  edition in `docs/HANDOFF.md` does not allow.

## Consequences

- Each read on a process-mode host pays a child start. The entrypoint avoids the framework boot
  so that cost stays in tens of milliseconds, not the hundreds a full `artisan` invocation costs.
- A host in `auto` that falls back to in-process has the cooperative bound only.
  `docs/assurance.md` states that difference, and `esign:doctor` makes it visible.
- The first deployed shared-hosting instance was checked on 2026-09-13, read-only, with no
  changes made: its CLI has no disabled functions, `proc_open` is available, and a child started
  from it honours `-d memory_limit`. It gets process mode under `auto`.
- The two loops that are still unreported are fixed alongside this, so the named ceilings trip
  before the hard limit where they can. The hard limit is what makes that no longer load-bearing.
