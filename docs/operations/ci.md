# Runbook: CI

`.github/workflows/ci.yml` is one workflow, `CI`, made of independent jobs gated by a `changes`
job (path filters via `dorny/paths-filter`) and fanned into a single required check, `CI result`.
This runbook covers what each job proves and, for the two checks with a local equivalent, how to
run them yourself before pushing.

## What each job proves

| Job | Proves | Gated on |
|---|---|---|
| `changes` | Computes which areas changed, so unrelated jobs skip. On a `schedule` run there is no diff to compute, so every output defaults to `'true'` — a scheduled run always runs everything. | Always runs |
| `test` | Pint formatting, the PHP test suite, and (when frontend files changed) `tsc`, ESLint, and Jest, across the PHP 8.4/8.5 matrix. | `frontend` or `backend` changed |
| `database` | Migrations apply and the feature suite passes against disposable MySQL 8.4, MariaDB 11.4, and MariaDB 10.6 containers — not just SQLite. | `backend` changed |
| `licenses` | Every production dependency's license is on the allowlist in `scripts/check-licenses.php`; builds the CycloneDX SBOM. | `composer.json`/`.lock`, `package.json`, `pnpm-lock.yaml`, the license script, `THIRD_PARTY_NOTICES.md`, or a workflow file changed |
| `audit` | No known-vulnerable dependency is locked in, per `composer audit` and `pnpm audit`, unless a committed, time-boxed allowlist entry says otherwise. | Same filter as `licenses`, plus a weekly `schedule` trigger |
| `image` | The production Docker image builds, serves `/up`, passes its own `HEALTHCHECK`, and dispatches the `artisan`/`worker` roles correctly. | `backend`, `frontend`, or `docker` changed |
| `validation` | Sealed PDFs validate independently under pyHanko (PAdES B-B/B-T), not just against this codebase's own assertions. | `backend` or `docker` changed |
| `result` | Fails if any job above failed or was cancelled; is the one required check branch protection needs. | Always runs |

## The dependency vulnerability audit

The `audit` job runs `composer audit --format=json --locked --abandoned=report` and
`pnpm audit --prod --audit-level=high --json --ignore-registry-errors`, uploads both raw reports
as the `dependency-audit` artifact (14-day retention), and hands them to
`scripts/check-audit.php` together with the committed allowlist, `.audit-allowlist.json`. Neither
package manager's own exit code decides pass/fail — `composer audit`'s default also fails on
*abandoned* packages (a maintenance signal, not a vulnerability, deliberately out of scope here),
and `pnpm audit`'s can be muddied by a flaky registry — so `check-audit.php` is the sole verdict,
driven only by the advisories actually reported in the JSON.

Because advisories are published continuously, not only when a lock file changes, the job also
runs on a weekly `schedule` trigger (`.github/workflows/ci.yml`'s `on:`), independent of whether
anything in the repository changed that week.

### The allowlist: format and review rule

`.audit-allowlist.json`:

```json
{
  "entries": [
    {
      "id": "GHSA-xxxx-xxxx-xxxx",
      "ecosystem": "npm",
      "reason": "Vulnerable code path is unreachable here: <specific, checkable justification>.",
      "expires": "2026-12-31"
    }
  ]
}
```

- `id` — the advisory identifier: a GHSA id for an npm/pnpm advisory, or a Packagist `PKSA-...`
  id (or a CVE, if that is all the advisory has) for a Composer one.
- `ecosystem` — `"composer"` or `"npm"`.
- `reason` — **owner-visible and specific.** "not exploitable in our usage" is not a reason;
  name the code path, the constraint, or the mitigating configuration that makes it so. This is
  the line a reviewer reads to decide whether to approve suppressing a real, published advisory.
- `expires` — an ISO `YYYY-MM-DD` date. `check-audit.php` fails the build once that date has
  passed, whether or not the advisory still matches anything — an expired entry cannot silently
  keep suppressing something nobody has looked at again. Renew deliberately (bump the date, in a
  reviewed commit) or remove the entry once the dependency is fixed.

An advisory not on the allowlist fails the job outright. There is no severity threshold to tune
around: `composer audit` reports everything it finds, and `pnpm audit` is invoked with
`--audit-level=high` because pnpm's own filtering happens before `check-audit.php` ever sees the
report. The correct response to a new failure is almost always `composer update <pkg>
--with-dependencies` or `pnpm update <pkg>`, not a new allowlist entry.

### Running it locally

```bash
composer audit --format=json --locked --abandoned=report > /tmp/composer-audit.json
pnpm audit --prod --audit-level=high --json --ignore-registry-errors > /tmp/pnpm-audit.json
php scripts/check-audit.php \
  --composer=/tmp/composer-audit.json \
  --pnpm=/tmp/pnpm-audit.json \
  --allowlist=.audit-allowlist.json
```

Exit codes: `0` nothing outstanding, `1` usage or input error (a report or the allowlist could
not be parsed), `2` at least one unallowed advisory or an expired allowlist entry.

## The license/SBOM gate

```bash
composer install --no-dev
composer licenses --format=json > /tmp/composer-licenses.json
pnpm licenses list --json --prod > /tmp/pnpm-licenses.json
php scripts/check-licenses.php \
  --composer=/tmp/composer-licenses.json \
  --pnpm=/tmp/pnpm-licenses.json \
  --sbom=/tmp/sbom.cdx.json
```

See the header comment in `scripts/check-licenses.php` for the allowlist and per-package
exception rules; `THIRD_PARTY_NOTICES.md` and `docs/adr/0005-lgpl-dependency-handling.md` cover
the LGPL exception for the `tecnickcom/*` (tc-lib-*) family specifically.

## PHP static analysis: not yet adopted

There is no PHPStan/Larastan job. A trial run of Larastan at level 5 against `app/` (September
2026) found 98 errors, dominated by narrowed-type/dead-code findings from PHPDoc types PHPStan
treats as certain and a handful of real gaps (undefined `Model` properties accessed via magic
`__get`, `catch` blocks for exceptions that can't be thrown). Adopting it is tracked separately;
see the issue this job's PR closed for the current status.
