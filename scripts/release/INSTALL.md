# Installing this release bundle (cPanel / shared hosting)

This archive is a self-contained BWH eSign install: application code, production Composer
dependencies (`vendor/`, no dev packages), and a built frontend (`public/build/`). It contains
no `.env`, no signing keys, and no database.

This file is a quick reference. The full runbook —
[`docs/operations/cpanel.md`](https://github.com/bherila/e-sign/blob/main/docs/operations/cpanel.md)
— covers prerequisites, the crontab lines, queue-delay characteristics, memory/time limits, the
shared-account key-isolation limitation, backup pointers, and the end-to-end smoke test
checklist in full. Read it before a production install; what follows is the short version.

## 1. Prerequisites

- PHP 8.4 or newer, **both** the CLI binary and the account's web vhost handler, with:
  `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`, `bcmath`, `openssl`, `exif`.
- MySQL 8 or MariaDB 10.6+, a dedicated database and user for this instance.
- `public/` as the document root. Everything else (`app/`, `config/`, `storage/`, `.env`,
  signing keys) must live **outside** the web-served tree.

## 2. Unpack

Unpack this archive somewhere outside the web-served directory, e.g. `~/bwh-esign/`, and point
the account's document root (or a `public_html` symlink) at `~/bwh-esign/public`.

## 3. Configure

```bash
cp .env.example .env   # not shipped in this bundle; copy it from the repository
php artisan key:generate
```

Fill in `.env`: database credentials, mail transport (never `log` in production), storage
(`local` or an S3-compatible disk), and — if this account's web vhost is not the same PHP
version as the CLI `php artisan` will run under — `ESIGN_CPANEL_WEB_PHP_VERSION`.

## 4. Diagnose before migrating

```bash
php artisan esign:doctor
```

Fix everything it reports before continuing. It cannot see the crontab or the live web PHP
version directly — see its own output and `docs/operations/cpanel.md` for what those checks can
and cannot actually detect.

## 5. Migrate (explicit, not automatic)

```bash
php artisan migrate --force
```

## 6. Bootstrap the first owner

```bash
php artisan esign:bootstrap-owner --help
```

See `docs/operations/bootstrap.md` for the full SSO/standalone walkthrough.

## 7. Cron

Two lines, edited into a **file** and installed with `crontab <file>` — never
`crontab -l | ... | crontab -`, which silently drops every other cron job already on the
account if anything about that pipeline goes wrong:

```cron
* * * * * cd /home/USER/bwh-esign && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/bwh-esign && php artisan esign:queue:work-bounded >> /dev/null 2>&1
```

## 8. Smoke test

Authenticate, upload, invite, sign, seal, download, validate, deliver a webhook, and recover
after a worker interruption. Full checklist: `docs/operations/cpanel.md`.
