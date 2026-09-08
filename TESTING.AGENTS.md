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
./vendor/bin/pint --test
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
