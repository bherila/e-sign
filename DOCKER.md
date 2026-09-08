# Docker

One image, three roles. `.docker/Dockerfile` builds a PHP 8.5 + nginx image that runs as the
unprivileged `www-data` user and serves HTTP on **8080**. The container command selects the role:

| Command | What runs | Notes |
|---|---|---|
| `web` (default) | php-fpm on a unix socket + nginx in the foreground | health at `/up` |
| `worker` | `queue:work` on the database queue, bounded by `--max-time` | the only role that needs signing key material; mount keys here and nowhere else |
| `scheduler` | `schedule:run` every 60 s | |
| `artisan …` | one-shot artisan command | e.g. `migrate --force` as a release step |

Caches (`config`, `route`, `view`) are built at container start when `APP_ENV=production`, so the
image is environment-agnostic. Migrations never run automatically.

The image carries a role-aware `HEALTHCHECK` (`.docker/scripts/healthcheck.sh`, 30s interval, 5s
timeout, 20s start period, 3 retries): the entrypoint writes the active role to `/tmp/esign-role`
before exec'ing it, and the healthcheck reads that file to decide what "healthy" means — `web`
curls `http://127.0.0.1:8080/up`, `worker`/`scheduler` check for their long-running process
(`pgrep -f queue:work` / `pgrep -f schedule:run`) since nginx is not present in those roles.
One-shot `artisan` invocations report healthy unconditionally; they have no steady state to probe.

## Targets

- `production`: immutable. Code, `vendor` (no dev), and the Vite bundle are baked in.
- `dev`: the same runtime plus Node/pnpm; the source tree is bind-mounted by compose. Build
  assets on the host (`pnpm run build`) or inside the container (`docker compose exec esign pnpm run build`).

```bash
docker build --target production -f .docker/Dockerfile -t bwh-esign:$(git rev-parse --short HEAD) .
docker run --rm -p 8080:8080 --env-file .env bwh-esign:<tag>            # web
docker run --rm --env-file .env -v /secure/keys:/keys:ro bwh-esign:<tag> worker
```

## Published image

`.github/workflows/publish-image.yml` pushes the production target to **`ghcr.io/bherila/e-sign`**
on every push to `main` (`:main`, `:sha-<short>`) and on every GitHub Release (`:<semver>`,
`:<major>.<minor>`, `:latest`), for `linux/arm64` and `linux/amd64`. The package is public, so
pulls are anonymous and storage is free. This repository publishes the image and deploys nothing
with it; a consumer pins a tag or, for production, the digest printed in the run summary, and
deploys through its own pipeline. Old `sha-` builds are pruned automatically; semver tags are kept.

### Labels

Every image carries OCI labels: `org.opencontainers.image.title`, `.description`, `.source`
(this repository), `.licenses` (`MIT AND LGPL-3.0-or-later` — the application is MIT, one bundled
dependency is LGPL, see `docs/adr/0005-lgpl-dependency-handling.md`), `.revision` (commit SHA),
and `.version` (release tag, or the commit SHA on a `main` build). `publish-image.yml` sets the
real `.revision`/`.version` at build time via `docker/build-push-action`'s `labels:` input;
`.docker/Dockerfile` also declares them as build `ARG`s with static fallback values
(`IMAGE_REVISION=unknown`, `IMAGE_VERSION=unknown`, `IMAGE_SOURCE` pointing at this repository) so
a local `docker build` without `--build-arg` still produces a labeled image, just without a real
revision/version. The production target also copies `LICENSE` and `THIRD_PARTY_NOTICES.md` into
`/usr/share/doc/bwh-esign/` inside the image — the LGPL dependency's notices obligation travels
with the image, not just the source tree.

### SBOM

The published image does not carry an embedded SBOM attestation. `publish-image.yml` builds each
architecture natively and pushes by digest before a separate job assembles the multi-arch
manifest with `docker buildx imagetools create` (see the "Publish: build each architecture
natively" history on this repository) — `imagetools create` combines existing per-arch manifests
by reference and does not merge the attestation manifests that `sbom: true`/`provenance:
mode=min` would attach to each per-arch build, short of reintroducing a single QEMU-emulated
multi-platform build purely to get attestation merging for free. That trade was rejected once
already for build speed and dist-fetch reliability, so this repository takes the escape hatch
instead: the CycloneDX SBOM is the CI license job's inventory. `.github/workflows/ci.yml`'s
`licenses` job already produces `build/licenses/sbom.cdx.json` from the exact production
dependency tree (`composer licenses` + `pnpm licenses list`, gated by `scripts/check-licenses.php`)
on every change to a lock file. `publish-image.yml` regenerates that same file for the commit it
publishes and attaches it to the GitHub Release (`gh release upload`) on a release, and uploads it
as a workflow artifact on every `main` push. **The image's SBOM is that file**, not something
baked into the manifest; fetch it from the Release assets or the `image-sbom` workflow artifact
for a given publish run.

## Standalone stack (this repository)

`docker-compose.yml` runs eSign with its own MariaDB and Mailpit on ports that do not collide with
the consumer's stack (app 8001, Mailpit 8026, MariaDB 3308 loopback). Storage is the local disk in
a named volume; identity is standalone local-admin mode.

```bash
cp .env.docker .env
docker compose up -d
docker compose exec esign php artisan key:generate
docker compose exec esign php artisan migrate      # explicit
```

## Composed with the consumer

The consumer application's `docker-compose.yml` has an `esign` profile that builds this image from
a sibling checkout (`../e-sign` by default, override with `ESIGN_SRC`) and wires it to that
stack's MariaDB (dedicated `esign` database and user), Garage (dedicated `esign-documents` bucket
and key), and Mailpit. That is the local mirror of the intended production shape: one eSign
instance per VM, sharing the VM's MariaDB and Garage servers but never their schemas, buckets, or
credentials. See that repository's `DOCKER.md`.

## Production deployment (consumer pipeline)

`docker-compose.prod.example.yml` is a template a consumer's own deploy pipeline copies and fills
in — this repository never runs it. It pins `ESIGN_IMAGE` to a manifest digest, runs the three
long-running roles plus a one-shot `esign-migrate` (`profiles: [release]`) release job, mounts the
signing key directory read-only into `esign-worker` only, and hardens the root filesystem
(`read_only: true` with `tmpfs` for `/tmp` and the writable `storage/framework`/`bootstrap/cache`
paths, `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`). It publishes nothing except
the web role on `127.0.0.1:8080`, for a host reverse proxy to front. Full release/rollback
procedure and the environment file and key directory contracts: **[docs/operations/deploy-docker.md](docs/operations/deploy-docker.md)**.

This repository does not run a reverse-proxy container; reuse whatever the host already runs.
For nginx, proxy to the loopback port the compose file publishes:

```nginx
server {
    listen 443 ssl;
    server_name esign.example.com;

    # ... TLS config ...

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        client_max_body_size 64M;   # match .docker/nginx/nginx.conf inside the image
    }
}
```

## What the image deliberately lacks

No Node, Python, Java, Chromium, or Redis at runtime (PHP-only baseline, `docs/HANDOFF.md` §13).
No signing keys in any layer (`.dockerignore` excludes `*.pem`, `*.key`, `*.p12`, `*.pfx`, `.env`).
No `storage/app` contents: documents live in a volume or an S3-compatible disk.

## The other deployment profile

Not every consumer runs containers. [docs/operations/cpanel.md](docs/operations/cpanel.md) is the shared-hosting
counterpart to this document: a `tar.gz` release bundle instead of an image, cron-driven bounded
queue work instead of a persistent `esign-worker` container, and `esign:doctor` instead of a
`HEALTHCHECK`. It also documents a real limitation Docker's `esign-worker` isolation exists to
avoid: on shared hosting, the web process and the queue worker are the same OS user, so the
signing key cannot be isolated from the web-facing PHP process the way it is here.
