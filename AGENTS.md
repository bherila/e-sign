# AGENTS.md

For AI coding agents working on BWH eSign, a Laravel 13 + React/TypeScript/Vite application for
preparing, signing, cryptographically sealing, and retaining PDF agreements.

## Read first

- [docs/HANDOFF.md](docs/HANDOFF.md) is the implementation specification. Its invariants,
  release gates, and "do not" rules are binding. If a task conflicts with it, say so rather than
  quietly deviating.
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) fixes the module boundaries and lifecycle.
- `TESTING.AGENTS.md` is the validation contract.
- Work is tracked in GitHub issues grouped by stage milestone. Stage 0 (contract and crypto
  feasibility) gates everything that builds on the PDF pipeline.

## Operating principle

Make the smallest coherent change that solves the problem. Include adjacent low-risk fixes only
when directly relevant. Inspect related files before editing, parallelize independent reads and
checks, and run targeted validation during iteration. Avoid unrelated refactors, dependency
changes, broad formatting churn, and production builds unless the task requires them.

## Non-negotiables specific to this product

- **One state machine.** The Firma facade and the native API call the same domain services.
  Never put signing rules in a compatibility controller.
- **Fail closed.** Unsupported routes and options return clear errors, never a successful no-op.
  A completion event is published only after the final PDF is generated, validated, durably
  stored, and retrievable. A requested assurance level that cannot be met is an error, not a
  silent downgrade.
- **No bespoke crypto.** CMS/ASN.1/PDF signature byte ranges come from established libraries.
  Signing keys are separate from `APP_KEY`, versioned, and never in source, images, logs, or
  document storage.
- **Honest language.** Humans provide electronic signatures/assent; the service seals the PDF
  under its own certificate. Do not describe the seal as a per-signer certificate, do not claim
  eIDAS advanced/qualified signatures, and do not call Garage/R2 storage WORM or legal hold.
- **Coordinates are never guessed.** One defined native coordinate space (`pt`, top-left,
  CropBox, displayed rotation). Facade conversions are fixture-backed. Never infer percent vs
  points from a number's magnitude.
- **GET is harmless.** Signing pages never apply signatures, consume one-shot tokens, or advance
  recipients on GET. Mail scanners and previews must be safe.
- **Retain originals byte-for-byte.** Uploads and imported executed PDFs are never re-rendered,
  re-sealed, or overwritten. New artifact keys only.
- **Synthetic fixtures only.** No real agreements, names, or addresses in the repository.
- **Identity binds on issuer + subject, never email.** First login never becomes an
  administrator; bootstrap is an explicit CLI command.
- **PHP-only runtime.** No Node, Python, Java, Chromium, Redis, or remote PDF service in the
  production path. Validation tools (pyHanko, DSS) run in CI against synthetic artifacts only.

## Project shape

- **Stack**: Laravel 13 on PHP ^8.4 (8.5 Docker target), React 19 + TypeScript, Vite, Tailwind CSS v4.
- **Package manager**: pnpm. Never npm or npx directly.
- **Database**: SQLite in development and tests (in-memory for tests). MySQL 8 or MariaDB in
  production; both are in the CI matrix (see `docs/adr/0002-supported-databases.md`).
- **Dependency management**: Composer (PHP) + pnpm (JS). Do not mix.
- **Auth**: `bherila/auth-laravel` OAuth client (PKCE) or standalone local admin. Config is
  published at `config/bherila-auth.php`; do not copy package routes.
- **Blob storage**: follow [docs/BLOB_STORAGE.md](docs/BLOB_STORAGE.md). Stream through the app,
  never presign, keep every disk private.

## Commands

```bash
# Setup
composer install && pnpm install
cp .env.example .env && php artisan key:generate
# Do not run migrations unless explicitly requested; see Database Safety.

# Development
composer dev

# Frontend checks
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build

# Backend checks
./vendor/bin/pint --parallel --test
composer test
```

## Database safety

1. Never run `php artisan migrate` or `php artisan schema:dump` unless the user explicitly requests it.
2. When explicitly requested, use SQLite only: `php artisan migrate --database=sqlite --no-interaction`.
3. For schema dumps: `php artisan schema:dump --database=sqlite`. Never use `--prune`.
4. Tests must use SQLite in-memory. Do not configure tests to use any other driver.
5. Production is MySQL 8 or MariaDB. Migrations must be valid on SQLite and both engines; never
   rely on an engine-specific feature without a CI test on the other engine.

## Laravel conventions

- Typed return types on all methods.
- Use Form Requests for validation; do not inline `$request->validate()` in controllers.
- Eager-load relationships; avoid N+1 queries.
- Follow PSR-4 autoloading; keep class files in the directory matching their namespace.
- Domain code lives under `app/Domain/<Module>/`; HTTP adapters under `app/Http/`.

## React / TypeScript conventions

- Use `interface` for component props.
- Named function declarations for components (not arrow-function const exports).
- Strict TypeScript; no `any` unless unavoidable and commented.
- Import aliases via `@/` where configured in `tsconfig.json`.
- Use existing utility and component abstractions before creating new ones.
- Signing pages: no third-party CDNs or analytics, `Referrer-Policy: no-referrer`, PDF.js served
  locally.

## File uploads

- Every upload endpoint enforces a server-side MIME allowlist in its Form Request. For PDFs,
  MIME is not enough: run the real preflight parser and reject encrypted, already-signed, and
  unsupported interactive structures with actionable messages.
- Inline image previews gate on `isBrowserRenderableImage()` from
  `resources/js/lib/isBrowserRenderableImage.ts`, never on a `mime_type.startsWith('image/')`
  check.

## Review policy

Pull requests touching cryptography, sealing, key handling, the signing state machine,
guest access, service credentials, webhooks, or either HTTP API surface require an
independent review (`@codex review`) before merge, in addition to green CI. Documentation,
configuration, UI-only, dependency, and test-only PRs merge on green CI. Squash merge always.

## Context budget

Keep the active context focused on the files relevant to the change. Load specific controllers,
services, tests, and config for the touched paths only. Read the relevant section of
`docs/HANDOFF.md`, not the whole file, once you know which module you are in.
