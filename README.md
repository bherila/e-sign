# BWH eSign

Self-hosted preparation, electronic signing, cryptographic sealing, and retention of PDF
agreements, built as an independently deployable Laravel application with a documented native
API and a versioned Firma-compatible HTTP/webhook facade.

**Status:** pre-release, not yet deployed anywhere, and no release has been cut. The sealing
pipeline, signer flow, evidence model, native API, Firma-compatible facade, delivery outbox and
both deployment profiles are built and tested; Stages 0 to 4 are closed. What remains open:

- deployment hardening ([#37](https://github.com/bherila/e-sign/issues/37)): the cPanel
  end-to-end smoke test and a restore drill against a real backup, both of which need a deployed
  instance;
- the staged pilot with the first consumer ([#43](https://github.com/bherila/e-sign/issues/43))
  and the identity-provider registration it needs
  ([#44](https://github.com/bherila/e-sign/issues/44));
- the sender UI for agreements ([#174](https://github.com/bherila/e-sign/issues/174)), and
  organization seals ([#164](https://github.com/bherila/e-sign/issues/164), deferred).

The build order, acceptance gates, and open questions live in [docs/HANDOFF.md](docs/HANDOFF.md)
and the GitHub issues; [docs/security/release-gates.md](docs/security/release-gates.md) maps each
release gate to the test or CI job that proves it, and [docs/assurance.md](docs/assurance.md)
says what an executed document does and does not prove.

"BWH eSign" is a working label, not a cleared trademark.

## What it is for

- Capture each person's informed assent to a specific, immutable revision of an agreement.
- Preserve the exact agreement they reviewed, the original upload, and the evidence.
- Seal the executed PDF under the service's disclosed certificate (PAdES B-B baseline, B-T with a
  configured RFC 3161 timestamp authority) with independently verifiable output.
- Prepare agreements of one PDF or an ordered set of up to 20, with signers, approvers and
  cc recipients, sequential or parallel, from templates or directly through the API.
- Replace the Firma workflows used by its first consumer without that consumer rewriting its
  integration, via the `firma-compat-v1` compatibility profile (pinned to the reference API
  v1.40.4; every difference is listed in
  [the capability matrix](docs/compatibility/firma-capability-matrix.md)).
- Run without any BWH-operated service: standalone local-admin mode, local disk or any
  S3-compatible store, ordinary SMTP.

People provide electronic signatures. The service then cryptographically seals the executed PDF.
That seal is an organizational service seal, not a personal certificate controlled by each signer.
The product must never describe it otherwise.

**[docs/assurance.md](docs/assurance.md) is the full statement of what this product claims and
what it does not** — the seal's exact profile, why a stock deployment's certificate will show as
untrusted in a PDF viewer, what "TSA trusted" would require, the absence of any eIDAS
advanced/qualified or WORM/legal-hold-by-storage claim, the residual threats from a compromised
application, key, or host administrator, and why ESIGN/UETA support is a workflow objective
needing counsel's review rather than a feature. Read it before relying on an executed document.

## Tech stack

- **Backend**: Laravel 13 on PHP 8.5+. MySQL 8 or MariaDB in production;
  database-backed queue, cache, and locks. No Redis, Node, Python, Java, or Chromium at runtime.
- **Identity**: [`bherila/auth-laravel`](https://github.com/bherila/auth-laravel) OAuth 2.0
  authorization-code client with PKCE against an [auth-manager](https://github.com/bherila/auth-manager)
  issuer, or standalone local admin. Recipients sign through a separate guest flow.
- **PDF**: pure-PHP preparation and sealing on `tecnickcom/tc-lib-pdf` (LGPL-3.0), chosen after
  the Stage 0 feasibility work ([ADR 0004](docs/adr/0004-pdf-engine-candidate.md),
  [docs/stage0/](docs/stage0/)). Executed PDFs are validated independently in CI by pyHanko and
  the European Commission's DSS.
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

Validation (the same gates CI runs; see [docs/operations/ci.md](docs/operations/ci.md) for every
job):

```bash
./vendor/bin/pint --parallel --test && composer test
pnpm run type-check && pnpm run lint && pnpm run test && pnpm run build
```

`scripts/e2e/compose-smoke.sh` runs the Docker end-to-end smoke test locally against a built
image (Docker and Python 3 required).

## Repository map

| Path | Purpose |
|---|---|
| `app/Domain/` | Module boundaries: Identity, Preparation, Signing, Evidence, Delivery, Integration. One signing state machine, shared by UI, native API, and the Firma facade. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). |
| `docs/HANDOFF.md` | The implementation specification: data model, invariants, native JSON schema, compatibility contract, security policy, deployment profiles, release gates. |
| `docs/BRIEF.md` | The project brief that motivated the specification. |
| `docs/assurance.md` | What the product claims and does not: the seal's profile, self-issued-certificate consequences, no eIDAS advanced/qualified claim, no WORM or storage-enforced legal hold, residual threats, the evidence bundle's digests, and the validators used with their stated limits. |
| `docs/security/` | [`review-2026-09.md`](docs/security/review-2026-09.md) is the adversarial security review with each finding's severity, reproduction, and fix or accepted residual. [`release-gates.md`](docs/security/release-gates.md) maps every release gate in `docs/HANDOFF.md` section 14 to the test or CI job that proves it, or an explicit "not yet proven". |
| `docs/adr/` | Architecture decision records. |
| `docs/preparation/`, `docs/signing/`, `docs/evidence/`, `docs/delivery/` | How each module works: documents, document sets, templates, the field schema and coordinate space, anchors, the editor; the state machine, guest access and embedded events; finalization and sealing; mail, webhooks and the envelope event catalog. |
| `docs/compatibility/` | [`firma-capability-matrix.md`](docs/compatibility/firma-capability-matrix.md): every in-profile Firma route, member and behaviour, its status (supported, intentionally different, unsupported) and the test that proves it. |
| `docs/ux/` | Design canvases (HTML) for the admin and signing flows, and the proposed sender UI (issue #174). |
| `docs/api/` | The HTTP surfaces. [`native-v1.md`](docs/api/native-v1.md) documents `/api/v1`: authentication, scopes, idempotency, pagination, errors, and the endpoint table. The OpenAPI 3.1 document is committed at [`resources/api/openapi-v1.json`](resources/api/openapi-v1.json) and served at `GET /api/v1/openapi.json`. [`firma-compat-v1.md`](docs/api/firma-compat-v1.md) documents the compatibility facade at `/functions/v1/signing-request-api`: the raw-key header, the percent coordinate convention, the three status representations, the download URL semantics, every intentional difference, and what is deliberately not impersonated. |
| `docs/operations/` | Operator runbooks. [`bootstrap.md`](docs/operations/bootstrap.md) provisions the first workspace owner; there is no default administrator. [`retention.md`](docs/operations/retention.md) covers deletion policies and legal hold — executed agreements are never deleted automatically until an operator configures a reviewed policy — and [`backups.md`](docs/operations/backups.md) covers the four separate backups and the restore drill. |
| `docs/stage0/` | Stage 0 feasibility findings, including what PAdES level the sealing pipeline actually reaches and how that was verified: [docs/stage0/sealing.md](docs/stage0/sealing.md) (soundness and trust, pyHanko) and [docs/stage0/pades-profile.md](docs/stage0/pades-profile.md) (ETSI profile conformance, European Commission DSS). |
| `tests/Fixtures/` | Synthetic PDFs, captured API fixtures, synthetic key material, and validation vectors. Never real agreements or real people. |
| `tests/EndToEnd/` | Contract tests that drive the application from outside as its first consumer would, plus the Compose stack (`tests/EndToEnd/compose/`) the Docker end-to-end CI job runs. |
| `THIRD_PARTY_NOTICES.md` | License inventory. Original code is MIT; dependencies keep their own licenses. |

## Deployment profiles

Two supported profiles. Each must pass the same end-to-end smoke test (authenticate, upload,
invite, sign, seal, download, validate, recover from a worker interruption) before a release:

- **Docker**: one image in web, worker, and scheduler roles behind an existing reverse proxy;
  signing keys mounted only into the worker role, which attests their readiness for the web role's
  send gate (`ESIGN_SEAL_READINESS=attested`). See [DOCKER.md](DOCKER.md) for the image itself
  (base images pinned by digest) and
  [docs/operations/deploy-docker.md](docs/operations/deploy-docker.md) for the consumer
  pipeline's release/rollback steps and the env-file/key-directory contracts. **Proven in CI:**
  the `compose-e2e` job runs the production image against MySQL 8.4 and Garage behind TLS, signs
  one agreement end to end, kills the worker mid-finalization, and verifies the recovered sealed
  PDF.
- **cPanel / shared hosting**: prebuilt assets, `public/` as document root, cron-driven bounded
  queue work with a database lease. `.github/workflows/deploy.yml` calls a commit-pinned shared
  action for guarded rsync, migrations, cron and health checks, and is off until `DEPLOY_ENABLED`
  is set. Keys, `.env`, and private storage stay outside the synced tree.
  See [docs/operations/cpanel.md](docs/operations/cpanel.md) for the release bundle
  (`scripts/build-release.sh`), install steps, `esign:doctor`, the crontab lines, and the
  shared-account key-isolation limitation. **Not yet proven:** this profile has no automated
  end-to-end smoke test ([#37](https://github.com/bherila/e-sign/issues/37)).

## Secrets

No real secret belongs in this repository. `.env.example` holds placeholders only. Signing keys
are separate from `APP_KEY`, versioned, and never in source, images, document storage, or logs.

## License

MIT for original application code. See [LICENSE](LICENSE) and
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
