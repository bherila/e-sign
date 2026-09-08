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

## Targets

- `production`: immutable. Code, `vendor` (no dev), and the Vite bundle are baked in.
- `dev`: the same runtime plus Node/pnpm; the source tree is bind-mounted by compose. Build
  assets on the host (`pnpm run build`) or inside the container (`docker compose exec esign pnpm run build`).

```bash
docker build --target production -f .docker/Dockerfile -t bwh-esign:$(git rev-parse --short HEAD) .
docker run --rm -p 8080:8080 --env-file .env bwh-esign:<tag>            # web
docker run --rm --env-file .env -v /secure/keys:/keys:ro bwh-esign:<tag> worker
```

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

## What the image deliberately lacks

No Node, Python, Java, Chromium, or Redis at runtime (PHP-only baseline, `docs/HANDOFF.md` §13).
No signing keys in any layer (`.dockerignore` excludes `*.pem`, `*.key`, `*.p12`, `*.pfx`, `.env`).
No `storage/app` contents: documents live in a volume or an S3-compatible disk.
