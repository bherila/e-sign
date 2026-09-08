# BWH eSign

Self-hosted preparation, electronic signing, cryptographic sealing, and retention of PDF
agreements, built as an independently deployable Laravel application with a documented native
API and a versioned Firma-compatible HTTP/webhook facade.

**Status:** scaffold. Nothing here signs a document yet. The build order, acceptance gates, and
open questions live in [docs/HANDOFF.md](docs/HANDOFF.md) and the GitHub issues.

"BWH eSign" is a working label, not a cleared trademark.

## What it is for

- Capture each person's informed assent to a specific, immutable revision of an agreement.
- Preserve the exact agreement they reviewed, the original upload, and the evidence.
- Seal the executed PDF under the service's disclosed certificate (PAdES B-B baseline, B-T with a
  configured RFC 3161 timestamp authority) with independently verifiable output.
- Replace the Firma workflows used by its first consumer without that consumer rewriting its
  integration, via the `firma-compat-v1` compatibility profile.
- Run without any BWH-operated service: standalone local-admin mode, local disk or any
  S3-compatible store, ordinary SMTP.

People provide electronic signatures. The service then cryptographically seals the executed PDF.
That seal is an organizational service seal, not a personal certificate controlled by each signer.
The product must never describe it otherwise.

## Tech stack

- **Backend**: Laravel 13 on PHP 8.4+ (8.5 is the Docker target). MySQL 8 or MariaDB in production;
  database-backed queue, cache, and locks. No Redis, Node, Python, Java, or Chromium at runtime.
- **Identity**: [`bherila/auth-laravel`](https://github.com/bherila/auth-laravel) OAuth 2.0
  authorization-code client with PKCE against an [auth-manager](https://github.com/bherila/auth-manager)
  issuer, or standalone local admin. Recipients sign through a separate guest flow.
- **PDF**: pure-PHP preparation and sealing. `tecnickcom/tc-lib-pdf` (LGPL-3.0) is the first
  candidate engine and is being proved in Stage 0 before anything is built on it.
- **Frontend**: React 19 + TypeScript, Vite, Tailwind CSS v4, shadcn-style components on Base UI,
  locally served PDF.js. pnpm only.
- **Storage**: private Laravel Storage disks streamed through the app. See
  [docs/BLOB_STORAGE.md](docs/BLOB_STORAGE.md).

## Getting started

```bash
composer install
pnpm install
cp .env.example .env && php artisan key:generate
composer dev
```

Development and tests use SQLite; tests run in-memory and refuse any other driver. Do not run
migrations unless you mean to: `php artisan migrate --database=sqlite --no-interaction`.

There is no seeded account and no default password. With no OAuth client configured the
application runs in standalone mode; create the first account and give it a workspace:

```bash
php artisan esign:create-user --name="Ada Lovelace" --email="ada@example.com"
php artisan esign:bootstrap-owner --user="ada@example.com" --workspace="acme"
```

Signing in is not the same as having access to anything: nobody becomes an administrator by
logging in, and `esign:bootstrap-owner` is the only thing that grants the first owner. See
[docs/operations/bootstrap.md](docs/operations/bootstrap.md), which covers both this and the
single sign-on shape.

Validation:

```bash
./vendor/bin/pint --parallel --test && composer test
pnpm run type-check && pnpm run lint && pnpm run test && pnpm run build
```

## Repository map

| Path | Purpose |
|---|---|
| `app/Domain/` | Module boundaries: Identity, Preparation, Signing, Evidence, Delivery, Integration. One signing state machine, shared by UI, native API, and the Firma facade. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). |
| `docs/HANDOFF.md` | The implementation specification: data model, invariants, native JSON schema, compatibility contract, security policy, deployment profiles, release gates. |
| `docs/BRIEF.md` | The project brief that motivated the specification. |
| `docs/adr/` | Architecture decision records. |
| `docs/api/` | The HTTP surfaces. [`native-v1.md`](docs/api/native-v1.md) documents `/api/v1`: authentication, scopes, idempotency, pagination, errors, and the endpoint table. The OpenAPI 3.1 document is committed at [`resources/api/openapi-v1.json`](resources/api/openapi-v1.json) and served at `GET /api/v1/openapi.json`. |
| `docs/operations/` | Operator runbooks. [`bootstrap.md`](docs/operations/bootstrap.md) provisions the first workspace owner; there is no default administrator. [`retention.md`](docs/operations/retention.md) covers deletion policies and legal hold — executed agreements are never deleted automatically until an operator configures a reviewed policy — and [`backups.md`](docs/operations/backups.md) covers the four separate backups and the restore drill. |
| `docs/stage0/` | Stage 0 feasibility findings, including what PAdES level the sealing pipeline actually reaches and how that was verified: [docs/stage0/sealing.md](docs/stage0/sealing.md). |
| `tests/Fixtures/` | Synthetic PDFs, captured API fixtures, and validation vectors. Never real agreements or real people. |
| `THIRD_PARTY_NOTICES.md` | License inventory. Original code is MIT; dependencies keep their own licenses. |

## Deployment profiles

Two supported profiles, both must complete the same end-to-end smoke test (authenticate, upload,
invite, sign, seal, download, validate, deliver a verified webhook, recover from a worker
interruption):

- **Docker**: one image in web, worker, and scheduler roles behind an existing reverse proxy;
  signing keys mounted only into the worker role. See [DOCKER.md](DOCKER.md) for the image itself
  and [docs/operations/deploy-docker.md](docs/operations/deploy-docker.md) for the consumer
  pipeline's release/rollback steps and the env-file/key-directory contracts; the consumer's
  compose stack has an `esign` profile that runs this image beside it.
- **cPanel / shared hosting**: prebuilt assets, `public/` as document root, cron-driven bounded
  queue work with a database lease. `.github/workflows/deploy.yml` is the rsync path and is off
  until `DEPLOY_ENABLED` is set. Keys, `.env`, and private storage stay outside the synced tree.

## Secrets

No real secret belongs in this repository. `.env.example` holds placeholders only. Signing keys
are separate from `APP_KEY`, versioned, and never in source, images, document storage, or logs.

## License

MIT for original application code. See [LICENSE](LICENSE) and
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
