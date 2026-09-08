# Runbook: backups and the restore drill

Four things need backing up, they need it in different ways, and the only thing that turns a
backup into a backup is having restored it.

Issue [#40](https://github.com/bherila/e-sign/issues/40). Retention and deletion are
[`retention.md`](retention.md); the deployment shapes are
[`deploy-docker.md`](deploy-docker.md) and `docs/HANDOFF.md` §13.

## The four things, and why they are not one thing

| What | Where it lives | Backed up | Restoring it without the others |
|---|---|---|---|
| **Database** | MySQL 8 / MariaDB in production, SQLite in development | Logical dump, on a schedule | Every agreement is a row pointing at bytes that are not there |
| **Documents disk** | The `documents` disk — uploads, review revisions, published artifacts | Object-store or filesystem copy | Every digest is orphaned; nothing can be located |
| **`.env`** | Not in git, never in an image | With the secret store, not with the dump | `APP_KEY` is gone, so encrypted columns and signed cookies are unreadable |
| **Seal key material** | `/etc/esign/keys`, mounted only into the worker | **Separately, under different handling** | Nothing new can be sealed; already-sealed documents still verify |

They are separate backups because they have different blast radii. A database dump is
routine operational data and belongs on the ordinary schedule. The seal private key is the
one artifact whose disclosure lets somebody forge this service's seal on any document, and
it must not be sitting in the same bucket as the nightly dump — an operator who can read
backups to answer a support question should not thereby be able to sign agreements.

### What to back up, concretely

```bash
# 1. Database. Consistent, transactional, and with routines/triggers if you added any.
mysqldump --single-transaction --quick --routines --events \
  --databases esign > esign-$(date -u +%Y%m%dT%H%M%SZ).sql

# 2. The manifest, taken next to the dump. See "The manifest" below.
php artisan esign:backup:manifest --path=/backup/esign-manifest.json

# 3. The documents disk.
#    Object store: server-side copy or replication to a bucket with separate credentials.
#    Local disk:   rsync of storage/app/documents (never --delete onto the live tree).

# 4. .env — into the secret store, alongside the other secrets for this deployment.

# 5. Seal key material — separately. See "Seal key material" below.
```

The manifest step goes **in the same script as the dump**, not in a later cron entry. A
manifest taken an hour after the dump describes a different database, and the comparison
then produces failures that mean nothing — which is how a check gets switched off.

`.github/workflows/deploy.yml` excludes `/storage/app/*` from its `rsync --delete`, so
`storage/app/documents` and `storage/app/backups` survive a deploy. That exclusion is the
only thing standing between a green deploy and an empty documents disk on a local-disk
deployment (`docs/BLOB_STORAGE.md` rule 2). Do not remove it, and add a matching exclusion
in the same commit as any new local disk.

### Seal key material

Different handling, in every dimension:

- **Separate destination.** Not the backup bucket. A secret store, or offline media held
  where the deployment's operators are not the only people who can reach it.
- **Separate credentials.** Whatever can read the database dump must not be able to read
  this.
- **Encrypted at rest with a passphrase that is not in `.env`.** `.env` travels with the
  application backup; a passphrase in it makes the two backups one backup.
- **Versioned, not overwritten.** `ESIGN_SEAL_KEY_ID` is recorded on every artifact
  precisely so a document sealed before a rotation stays attributable. Restoring only the
  current key leaves older artifacts unverifiable against their recorded key id. Keep every
  retired certificate — the *certificate*, at minimum, forever; see
  [`seal-key-management.md`](seal-key-management.md).
- **Not in a restore drill.** The drill does not need to seal anything, and mounting the
  real key into a throwaway environment is how a private key ends up in a snapshot nobody
  is tracking. Leave `ESIGN_SEAL_*` pointing at nothing there; `signing_material` in
  `/health/ready` will report `fail`, which is correct — that instance genuinely cannot
  seal.

**Losing the seal private key is survivable; disclosing it is not.** Already-published
artifacts carry the full certificate chain inside their CMS and keep verifying without it.
What is lost is the ability to seal new documents under the same key id, which is a
rotation. Weight the two accordingly when deciding where the backup goes.

### Off-host copies

`docs/HANDOFF.md` section 12 is explicit: *"Use independently administered off-host copies
for stronger deletion/tampering resilience; same-host duplication alone is not
independence."*

Garage's documented S3 implementation has neither Object Lock nor object versioning, so
nothing at the storage layer prevents an object being deleted or replaced. The legal hold in
this application is a column the code consults (see [`retention.md`](retention.md)); it is
not bucket-enforced and is not WORM. An independently administered copy — different
credentials, different administrator, different host — is the only thing in this design that
resists a deletion somebody with production access actually intends. Treat it as a
requirement rather than a nicety if the agreements matter.

## The manifest

`esign:backup:manifest` writes JSON next to the dump:

```json
{
  "schema_version": 1,
  "generated_at": "2026-09-08T02:31:07+00:00",
  "app_env": "production",
  "database_driver": "mysql",
  "volatile_tables": ["cache", "sessions", "jobs", "..."],
  "tables": { "envelopes": 1841, "artifacts": 5523, "recipient_attestations": 3702, "...": 0 },
  "artifacts": {
    "count": 5523,
    "bytes": 8241002331,
    "digest_total": "9f2c…"
  }
}
```

Why it exists: **a restore that silently drops rows looks exactly like a restore that
worked.** A dump taken while a transaction was open, a table caught by a stale
`--ignore-table`, an import that ran out of disk 90% of the way through — each produces a
database that connects, migrates, and serves pages. The only way to notice is to have
written down what was there.

- **Table counts** are derived from the live schema rather than a hardcoded list, so a table
  added next month is covered without anybody remembering to add it.
- **`digest_total`** is the SHA-256 of the sorted `kind:sha256` lines of every published
  artifact. One string that changes if any artifact is added, removed, or has a different
  digest. Comparing one value is what makes the check usable in a runbook.
- **Volatile tables** — sessions, cache, the queue tables, idempotency keys — are counted and
  then excluded from the comparison. A restored copy legitimately has a different number of
  queued jobs, and comparing them would fail every drill.

The manifest contains counts and digests only: no document bytes, no addresses, no key
material. It is safe next to the dump.

## The restore drill

A backup you have not restored is a hypothesis. Run this on a schedule you can defend —
quarterly is a reasonable floor, and after any change to the storage backend, the database
engine, or the backup tooling.

### 1. Build a throwaway environment

A container, a VM, a scratch database. Nothing it can reach may be production.

```dotenv
APP_ENV=drill                  # anything but production; the drill refuses production
ESIGN_RESTORE_DRILL=1          # suppression + the drill commands' precondition
APP_URL=http://localhost:8080

MAIL_MAILER=log                # belt; ESIGN_RESTORE_DRILL is the braces
QUEUE_CONNECTION=sync

# Point at the restored copies, never the live ones.
DB_DATABASE=esign_drill
AWS_BUCKET=esign-drill
AWS_ACCESS_KEY_ID=…            # read-only credentials for the restored copy
```

**`ESIGN_RESTORE_DRILL=1` is not optional and not cosmetic.** The restored database holds
real recipient addresses, real webhook endpoint URLs, and queued mail and pending deliveries
against both. Nothing in the data says "you are a copy". With the variable set:

- `MailOutbox::enqueue()` and `resend()` refuse, so nothing new is queued.
- `OutboundMailSender::send()` refuses, so the rows the copy *inherited* are not sent either.
  This is the one that matters — those were queued before the backup was taken, so guarding
  only the enqueue end would suppress nothing.
- `WebhookDispatcher` refuses to create any attempt, and `DeliverWebhook` refuses before it
  puts anything on the wire.

Each is a refusal, not a redirect to a log mailer. A suppressed send recorded as a
successful one would make the copy's own mail rows lie, and the point of the drill is to find
out what the restored instance actually does.

Do **not** start a queue worker in the drill environment. The guard means an accidental one
fails loudly rather than sending, but the correct configuration is not to run one.

### 2. Restore

```bash
mysql esign_drill < esign-20260908T023107Z.sql
# Copy the documents bucket/directory into the drill's own.
# Copy .env from the secret store and edit it as above. Do NOT mount the seal keys.
php artisan config:clear
```

Do not run `php artisan migrate` as part of the drill. The dump carries the schema it was
taken with, and migrating changes what you are verifying. If the current release needs a
migration the dump predates, that is a *second* exercise — restore, verify, then migrate,
and verify again.

### 3. Verify

```bash
php artisan esign:restore:verify --manifest=/backup/esign-manifest.json
```

It refuses unless `ESIGN_RESTORE_DRILL=1` **and** `APP_ENV != production`. Both, because a
variable can be set anywhere and every staging instance satisfies the second on its own.

Then it does two things, and both must pass:

1. **Manifest comparison.** Every non-volatile table's row count, plus the artifact count
   and digest total. Catches the truncated dump, the excluded table, the import that ran
   short.
2. **Integrity verification.** Every published artifact re-read through the storage adapter,
   re-hashed, and its seal re-validated. Catches the likelier failure: a database that
   restored perfectly beside an object store that did not. The two are backed up by
   different mechanisms on different schedules, so they fail independently.

Exit status is non-zero if either fails, so the drill can be a step in a script rather than
something somebody reads.

`--skip-artifacts` compares the manifest only. It is for a quick check on a very large
corpus and it says on screen that the documents were not checked. A drill that always uses
it has not verified a backup.

### 4. Spot-check by hand

Automation cannot tell you the document is the right document.

```bash
php artisan esign:artifacts:verify --since=-90days   # narrower, faster, same check
```

Then pick two or three completed envelopes and, in the drill environment, export their
evidence and open the executed PDF. Confirm the signature panel validates in a reader, the
completion report names the parties you expect, and `evidence.json`'s digests match the
files in the bundle. `EvidenceExporter::bundle()` produces all of that with a
`manifest.json`.

### 5. Write down what happened

Date, the backup's timestamp, what the two checks reported, how long the restore took, and
anything you had to do by hand. The last one is the valuable part: every manual step is a
step that will be forgotten in an emergency, and the drill exists to turn it into a script.

Then destroy the environment. It holds a full copy of production.

## What a drill has told you, and what it has not

It tells you the dump is complete and loadable, the objects are present and intact, the
digests still agree, and the seals still validate.

It does not tell you how long a real restore takes under load, whether your credentials for
the off-host copy still work (test that separately — expired credentials are the classic
finding), or whether the *most recent* backup is good. A drill validates the backup you
restored, and the one taken last night is a different backup.

## Related

- [`retention.md`](retention.md) — deletion policies, legal hold, integrity verification.
- [`seal-key-management.md`](seal-key-management.md) — rotation, expiry, compromise.
- [`health.md`](health.md) — the readiness probes, including `artifact_integrity`.
- [`docs/BLOB_STORAGE.md`](../BLOB_STORAGE.md) — the deploy-exclude rule and why nothing
  presigns.
