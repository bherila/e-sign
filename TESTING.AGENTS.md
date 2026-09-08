# Testing — Agent Requirements

Routine validation checks for AI coding agents in this template.

## Frontend gate

Run before committing frontend changes (TypeScript, React, CSS, Vite config):

```bash
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build
```

All four must pass. Fix type errors and lint violations before pushing — do not suppress them without justification.

## Backend gate

Run before committing PHP changes:

```bash
./vendor/bin/pint --parallel --test
composer test
```

`pint --test` checks formatting without writing; fix violations by running `./vendor/bin/pint` (without `--test`).

During iteration, targeted tests are acceptable:

```bash
php artisan test tests/Feature/SomeFeatureTest.php
php artisan test --filter="some_specific_test"
```

Before finalizing, broaden to the full backend gate.

## Database safety

Never run migrations or schema dumps unless the user explicitly requests it. When explicitly requested:

```bash
php artisan migrate --database=sqlite --no-interaction
php artisan schema:dump --database=sqlite
```

Never use `--prune`. Tests must use SQLite in-memory and must never run against a production or shared database.

## CI-only engine jobs

The `database` job in `.github/workflows/ci.yml` runs migrations and the feature suite against
disposable MySQL 8.4 and MariaDB 11.4 service containers, gated by the same backend-changed
filter as the `test` job. This is the one place `php artisan migrate --force` runs against a
non-SQLite database: the container is created for the job and destroyed with it, never a
persistent or shared database, so the migration ban above does not apply there.

`Tests\SafeTestCase` still refuses every other non-SQLite connection. The `database` job's
opt-in works by setting `ESIGN_TEST_DB_ENGINE=mysql` or `mariadb`; `Tests\Support\TestDatabaseGuard`
(covered by `tests/Unit/Support/TestDatabaseGuardTest.php`) accepts that opt-in only when the
active connection also targets the `esign_ci_test` database (or a paratest-suffixed
`esign_ci_test_test_{n}`) on `127.0.0.1` or `localhost`. Do not set `ESIGN_TEST_DB_ENGINE` when
running tests locally or anywhere outside that CI job.

Because `phpunit.xml` hardcodes `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` (and those
values win over the shell environment even without touching the file), the job generates a
`phpunit.ci-db.xml` from `phpunit.xml` with those two values swapped for the engine under test,
and runs `php artisan test --configuration=phpunit.ci-db.xml --parallel`. Laravel's
`ParallelTesting` creates one `esign_ci_test_test_{token}` database per paratest process, which
the CI root user is permitted to do.
