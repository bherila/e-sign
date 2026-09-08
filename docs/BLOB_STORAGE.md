# Blob storage conventions

How apps built from this template should store, serve, and reclaim user-uploaded files.

These conventions were established while migrating two production apps (a PHR holding DICOM
imaging, and a finance app holding tax and bank documents) off Cloudflare R2 and onto the
host filesystem. Each rule below exists because its absence caused, or nearly caused, a
concrete failure — those are noted inline, because a convention whose reason is forgotten
gets "simplified" away.

## 1. Serve files by streaming through the app. Never presign.

Do **not** hand the browser a presigned object-store URL. Have the controller that already
resolved and authorised the record stream the bytes.

```php
// The action already loaded and authorised $file. Serve it.
return $this->streamStoredFile($file);          // attachment
return $this->inlineStoredFile($file, $mime);   // inline, for in-page preview
```

Why:

- **Authorisation becomes per-user and per-record**, not per-URL-possession. A presigned URL
  is a bearer token: anyone holding it has access until it expires. A streamed response is
  checked against the session on every request.
- **The storage driver stops leaking into the application.** The `local` driver does not
  implement `temporaryUrl()` or `temporaryUploadUrl()` — it throws. Any code that presigns
  is silently coupled to `s3`, and the coupling only surfaces at runtime, in production,
  as a 500 on every file.
- **One code path.** Both drivers implement `readStream()`, so the same controller serves
  either. Switching backends becomes a config change, and object storage stays available as
  an option if the host runs short of disk.

Keep the disk *name* stable (`s3` is fine as a name) and let its `driver` decide where bytes
live. Code should never branch on the driver.

### Inline vs attachment are different routes

A route can stream one thing with one `Content-Disposition`. If a record needs both preview
and download, expose two routes (`.../view` and `.../download`), not one that guesses.

This matters more than it looks: an `<iframe src>` requires `Content-Disposition: inline`.
Serve an attachment into an iframe and the browser downloads the file instead of rendering
it — an in-page document viewer breaks with no error anywhere.

## 2. Every local disk root needs a deploy `--exclude`

Deploys that `rsync --delete` the `storage` directory will delete any data directory that is
not in the exclude list, because it is not in git. The workflow still exits 0, so this is
silent.

Add the exclude in the same commit that adds the disk. In the finance app this was one
missing entry away from deleting 824 MB of patient imaging on the next green deploy.

## 3. Reclaiming orphans

Unreferenced objects accumulate. Reclaim them with a pruner built to these rules.

### Declare the reference map fluently, per app

```php
BlobReferences::make()
    ->from('documents', 'storage_path')
    ->from('uploads', 'upload_prefix')->asPrefix()
    ->ignoring('users', 'api_key', because: 'credential, not a storage key');
```

**Per app, not shared.** Each app owns a disjoint storage root; a shared map would let one
app's pruner reason about data it cannot see. The *engine* is generic; only the map is local.

An object is garbage when **no** mapped column references it — set membership, not reference
counting. One object may legitimately be referenced from several tables and is reclaimable
only once all of them let go.

### Derive the map from the live schema, not from grep

Introspect `information_schema` (or `Schema::getColumns()`). Column naming is not as regular
as it looks: a real production table stored utility bills in `pdf_s3_path`, which no pattern
anchored on `s3_path%` matches. A missed column is not a coverage gap — it is a set of files
scheduled for deletion.

### Make an unmapped column a test failure

Ship a coverage test that walks the schema and fails if a key-shaped column is neither mapped
nor explicitly `ignoring()`-ed. This converts "someone added a file-bearing table" from silent
data loss into a red build. When it first ran on a mature app it immediately caught a column
that a hand-written `information_schema` query had missed.

### Soft-deleted rows still count as references

Query through `DB::table()`, never Eloquent — the `SoftDeletes` global scope would hide
trashed rows and condemn their bytes. A soft delete is meant to be reversible; destroying the
file makes it permanent. Reap blobs only once the row is **hard**-deleted.

### Skip recently-written objects

An object can exist before the row that references it: a browser upload lands in storage
first, and the row is written when the upload is registered. Reaping inside that window
deletes a live upload. Ignore anything newer than a threshold (24h is a reasonable default).

### Quarantine, don't delete

Move orphans to a dated `_quarantine/<timestamp>/` prefix and purge later, after a holding
period. A mistake becomes a `mv` away from undone rather than a restore away.

Quarantine matters *more* on object storage, not less: a host filesystem may sit under hourly
snapshots, but a bucket typically has durability without point-in-time recovery, leaving the
holding period as the only safety net.

Exclude the quarantine prefix from the sweep, or quarantined objects — unreferenced by
definition — get re-quarantined forever and inflate the safety ratio below.

### Abort on implausible scale

Refuse to act if orphans exceed a small share of total objects. This is the check that
actually catches a missing map entry: when a column stops being consulted, everything it
protected looks unreferenced at once and the ratio spikes.

### Never take a prefix from the caller

Enumerate swept roots in code. A `--prefix` option defaulting to something safe is still one
empty string away from sweeping everything: a real command in a real app would, given
`--prefix=`, list the whole bucket, compare it against a single table, and delete the rest.

## 4. Dry-run is the default

Destructive commands require an explicit `--apply`. `--dry-run` as an opt-in flag is the
wrong polarity — the safe mode is the one you get when you forget.

## 5. PHP ini values belong in `.htaccess`, not `.user.ini` (LiteSpeed hosts)

On a LiteSpeed SAPI, `.user.ini` is **silently ignored** even when `user_ini.filename` and
`user_ini.cache_ttl` are both set, so values quietly stay at defaults. Use `php_value` inside
`<IfModule LiteSpeed>` in `public/.htaccess`, and keep that file in git so a deploy's
`rsync --delete` cannot remove it.

Verify against the **running vhost** with a temporary probe script — never `php -i` on the
CLI, which reports a different SAPI's configuration. Test any new `php_value` in a throwaway
subdirectory first: a rejected directive there 500s one directory instead of the whole site.

A production app carried a committed `.user.ini` raising `memory_limit` to 1 GB that had
never once taken effect.
