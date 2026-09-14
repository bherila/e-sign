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
headroom expires. Neither depends on any loop reporting anything. Each backstop counts once for
every read the operation gives a budget of its own: one for a preflight or an extraction, and for
an assembly one per input's preflight, one per input's geometry read and import, and one for the
read-back. A hard limit sized for a single read would stop an assembly — at finalization, after
assent — that the in-process path, with a fresh budget per read, completes.

**Failures keep their names.** A killed child is `time_budget_exceeded`; a child that died of
memory exhaustion is `memory_budget_exceeded`, recognised by the exit status the entrypoint's
shutdown handler sets rather than by the fatal error's text, which the child binary's
`error_reporting` may suppress; a ceiling the child's own budget reached comes back with that
ceiling's code; and a document the child read and could not accept is the port's ordinary
unreadable-document failure. Who is told about a ceiling is unchanged: a caller that supplied a
budget hears the ceiling, a caller that did not sees the unreadable-document failure.

**A read that failed on the service side is not an unreadable document** (amended 2026-09-14,
issue #119). A child that could not be started, exited unexpectedly or answered with something
undecodable learned nothing about the bytes, and neither did a host that cannot provide the
isolation `process` requires. Every port declares one failure for both, `DocumentReadUnavailable`,
beside the failure it promises for a document it could not read. As first written, each adapter
reported it as that document failure instead, which stored valid uploads as `preflight_failed`,
failed finalizations a retry would have completed, and told integrations to correct requests that
needed no correcting. Callers answer it as the deployment's failure:

| Path | Answer |
|---|---|
| Upload | `503`, `code: document_unavailable`; no document is recorded |
| Native API | `503 document_unavailable`, `details.retryable: true` |
| Firma facade | upstream's `500 internal_error`, with a sentence saying nothing needs to change |
| Publish and send | `anchor_document_unavailable` / `document_unavailable`, both `503` |
| Finalization | a failed child leaves the envelope `finalizing` for `esign:finalization:resume`; unavailable isolation is `finalization_failed`, because the host has to be fixed before any retry can succeed |

Each is reported to the error log, and none puts the exception's own message on the wire, since
that can name the host's PHP binary.

**The cooperative budget stays.** It is what turns "this cost too much" into a named, actionable
ceiling (`decompression_limit_exceeded`, `object_limit_exceeded`, ...) before the hard limit is
reached, and it is the whole bound on an in-process host. The process limit is the safety
control; the budget is the explanation.

**The PHP binary is configured, not guessed.** Under a web SAPI, `PHP_BINARY` names the FPM or
LSAPI handler rather than a CLI, and on shared hosting the CLI and the web handler can be
different installations entirely. `esign.documents.isolation.php_binary` names the CLI to run.
Left unset, `auto` looks for one and, finding none it can run, falls back to in-process rather
than failing every upload.

**A child is trusted only after a trial.** The trial child must run this application's PHP
major and minor version, honour `-d memory_limit`, and have every extension the tecnickcom
packages declare in `composer.lock` (a test keeps the list equal to the lock). A binary that
fails any of these is not used: its reads would fail as unreadable documents instead of saying
what is wrong.

**The mode reads actually get is reported where they run.** The CLI and the web handler can
have different ini files, and uploads are read in the web request while finalization is read
on the queue. So the `document_isolation` probe runs in both places: `esign:doctor` answers for
the command line, and `/health/ready` — served by the web handler — answers for web requests.
Each is `ok` when a trusted child runs, `warn` when reads are in-process (configured, or `auto`
fell back), and `fail` when `process` is required and unavailable. A deployment that has seen
`ok` from both should set `process`, so losing the child refuses reads rather than dropping the
hard bound silently.

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
  `docs/assurance.md` states that difference, and the `document_isolation` probe makes it
  visible in each SAPI.
- Every `/health/ready` request starts one trial child, the same cost a web request pays on its
  first document read.
- The first deployed shared-hosting instance was checked on 2026-09-13, read-only, with no
  changes made: its CLI has no disabled functions, `proc_open` is available, and a child started
  from it honours `-d memory_limit`. It gets process mode under `auto`.
- The two loops that are still unreported are fixed alongside this, so the named ceilings trip
  before the hard limit where they can. The hard limit is what makes that no longer load-bearing.
