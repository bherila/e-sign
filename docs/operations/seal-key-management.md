# Service seal key management

Operating the key the service seals executed agreements with: where it lives, how to see what
is in force, how to rotate it, and what to do when it is compromised.

Issue [#29](https://github.com/bherila/e-sign/issues/29).
[`docs/HANDOFF.md`](../HANDOFF.md) section 9 and
[`ADR 0003`](../adr/0003-seal-material-and-timestamp-authority.md) are the rules; this is the
runbook.

## What the seal is, and what it is not

The service holds **one organizational certificate** and seals the finished PDF with it.
Humans provide electronic signatures and assent; the service then seals the result.

- It is **not** a per-signer certificate. No signer owns or exclusively controls it.
- It does **not** make the result an eIDAS advanced or qualified electronic signature.
- The completion report bound into every executed agreement says both of these in plain words,
  and is labelled a report rather than a certificate.

What the seal does establish is integrity and origin: these bytes have not changed since this
service sealed them, and this service sealed them.

## Configuration

Everything lives in `config/esign.php` under `seal` and `tsa`, read from the environment.

| Variable | Meaning |
|---|---|
| `ESIGN_SEAL_KEY_ID` | Versioned identifier for the material below. **Recorded on every artifact.** Required. |
| `ESIGN_SEAL_CERTIFICATE_PATH` | PEM X.509 certificate of the seal. |
| `ESIGN_SEAL_PRIVATE_KEY_PATH` | PEM private key matching it. |
| `ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE` | Passphrase for an encrypted key; empty for an unencrypted one. |
| `ESIGN_SEAL_CHAIN_PATH` | Optional PEM bundle above the seal certificate, leaf first. Embedded in the CMS so a relying party can build a path. |
| `ESIGN_SEAL_DIGEST_ALGORITHM` | `sha256` (default), `sha384`, or `sha512`. SHA-1 is not offered. |
| `ESIGN_TSA_URL` | RFC 3161 endpoint. Unset means the deployment can only produce B-B. |

### Where the files must not be

Not in the repository, not in an image layer, not on a public disk, not in document storage,
and not in any log. They are separate from `APP_KEY`: `APP_KEY` protects application data at
rest and is rotated on a different schedule by different people.

`SealMaterial` reads the files and hands the engine PEM strings, so the PDF engine never gets
filesystem access and the passphrase never travels past the point where the key is decrypted.
`SealMaterial` also wraps the decrypted key in a `SensitiveParameterValue`, which redacts under
`var_dump()`, `var_export()`, and Symfony's VarDumper (what `dd()` and an exception page use)
and refuses to serialize at all.

## Checking what is in force

```bash
php artisan esign:seal:status
```

Reports the key id, the certificate subject, its SHA-256 fingerprint, the digest algorithm, the
expiry date and days remaining, whether a chain is configured, whether a timestamp authority is
configured, and which assurance levels are therefore reachable.

It never prints, logs, or derives anything from the private key. It reads through
`App\Domain\Evidence\Contracts\SealIdentity`, which has no method that could return key
material.

**Exit status is the monitoring surface**: `0` when the material is usable and not expiring
inside the warning window, `1` otherwise. So it can be a cron check rather than something a
person has to read:

```bash
php artisan esign:seal:status --warn-days=45 >/dev/null || alert 'eSign seal material needs attention'
```

The `/health/ready` probe covers the same ground continuously — `signing_material` reports the
material's usability and warns inside `ESIGN_HEALTH_CERT_WARN_DAYS` (30 by default), and `tsa`
reports the timestamp authority. See [`docs/operations/health.md`](health.md).

There is a third gate, and it is the one that matters most operationally: **an envelope cannot
be sent when the material is unusable.** `SealMaterialAssurancePolicyCheck` runs at send time
and refuses before any signer is invited. A deployment whose certificate expired last week
finds out when someone tries to send, not after two people have signed.

## Rotation

**A rotation is a new key id and new files. It is never an edit of the existing ones.**

Old artifacts stay verifiable because each one embeds the certificate that sealed it in its own
CMS, and each `artifacts` row records `seal_key_id` and `seal_certificate_sha256`. Nothing has
to be re-sealed, and nothing may be: re-sealing an executed agreement would produce different
bytes for a document people have already signed.

1. Obtain the new certificate and key. Keep them at paths that do not collide with the current
   ones — dating the directory (`/srv/esign-keys/2027-01/`) is enough.
2. Choose a new `ESIGN_SEAL_KEY_ID`. It must be distinct from every id ever used on this
   deployment; a date-based id is the obvious choice.
3. Put the files in place with the same ownership and permissions as the current ones, readable
   only by the worker's user.
4. Check the new material *before* switching, by pointing a throwaway environment at it:

   ```bash
   ESIGN_SEAL_KEY_ID=seal-2027-a \
   ESIGN_SEAL_CERTIFICATE_PATH=/srv/esign-keys/2027-01/seal.crt \
   ESIGN_SEAL_PRIVATE_KEY_PATH=/srv/esign-keys/2027-01/seal.key \
     php artisan esign:seal:status
   ```

5. Update the environment and restart the **worker** role. Web and scheduler do not seal.
6. Confirm: `php artisan esign:seal:status` shows the new key id, and `/health/ready` is green.
7. Retain the old certificate (and its chain) indefinitely with the export material. The old
   *private key* is no longer needed for verification and should be destroyed on the schedule
   your key policy sets.

Envelopes already sent are unaffected: their assurance level is fixed at send, and the
finalization that runs later uses whatever material is configured then, recording that key id
on the artifacts it produces.

### Expiry planning

Rotate before expiry, not at it. The signature over an already-sealed document does not stop
verifying when the certificate expires — the certificate was valid when it signed — but a
deployment whose certificate has expired cannot seal anything new and cannot send anything at
all, because the send gate refuses.

Watch `days remaining` in `esign:seal:status` and the `signing_material` probe. Set
`ESIGN_HEALTH_CERT_WARN_DAYS` to comfortably more than your procurement lead time.

## Compromise response

A compromised seal key means anyone holding it can produce bytes that look like this service's
seal. Treat it as an incident, not a maintenance task.

1. **Stop sealing.** Move the private key file out of the worker's reach and restart the
   worker. Finalization then fails closed — envelopes go to `finalization_failed`, which is
   visible and retryable — rather than sealing with a key you no longer control. Sending is
   also refused. That is the intended blast radius.
2. **Rotate**, as above, with a fresh key id. Do not reuse the compromised id: the id is how an
   artifact says which material sealed it, and reusing it makes artifacts from before and after
   the incident indistinguishable.
3. **Revoke**, if the certificate was CA-issued. A self-issued certificate has no revocation
   path, which is one of the costs recorded in ADR 0003.
4. **Enumerate the exposure.** Every artifact sealed with the compromised key id is
   `SELECT * FROM artifacts WHERE seal_key_id = '<id>'`. Their published digests are in
   `artifacts.sha256` and in each envelope's evidence document, and those digests were computed
   before the key was exposed if your off-host evidence copies predate the incident. That is
   what off-host copies are *for*.
5. **Say so.** A compromised organizational seal is a fact relying parties need. Nothing in the
   application can decide that for you.
6. **Re-finalize nothing.** Executed agreements are not re-sealed. Their evidentiary value now
   rests on the acceptance record and the off-host copies, and pretending otherwise by
   producing new bytes would destroy the only thing that still distinguishes them.

The honest limit: a hash chain in a database the application can write to is not an independent
witness, and neither is a seal whose key an attacker holds. Separately administered, off-host
evidence checkpoints and signer copies are what make the record survive a compromised
application, key, or host administrator. [`docs/HANDOFF.md`](../HANDOFF.md) section 8 says so,
and this document is not going to say otherwise.

## Deployment profiles

### Docker — mount the key into the worker only

The same image runs three roles: web, queue worker, scheduler. **Only the worker seals.** Mount
the key material into that service and no other:

```yaml
services:
  web:
    image: esign
    # no seal material here

  worker:
    image: esign
    command: php artisan queue:work
    environment:
      ESIGN_SEAL_KEY_ID: seal-2026-a
      ESIGN_SEAL_CERTIFICATE_PATH: /run/secrets/esign-seal/seal.crt
      ESIGN_SEAL_PRIVATE_KEY_PATH: /run/secrets/esign-seal/seal.key
    volumes:
      - /srv/esign-keys/2026-01:/run/secrets/esign-seal:ro

  scheduler:
    image: esign
    command: php artisan schedule:work
    # no seal material here
```

Rules that go with it:

- The material is **never** baked into an image layer. A layer is copied wherever the image is.
- Mount read-only, owned by the container's non-root user, mode `0400`.
- The web role has no seal material, so a request-handling compromise cannot reach the key.
  That is the whole point of the split — it is worth more than any amount of care inside the
  application.
- `esign:seal:status` run in the web container will report the material as unusable. That is
  correct, and it is why the readiness probe's `signing_material` check is meaningful only in
  the worker.

### cPanel / shared hosting — the isolation is not available

On a single-account shared host, cron-driven queue work and the web requests run as the same
user with the same PHP. **There is no way to expose the seal key to the queue worker and not to
application PHP handling a web request.** Anything that can read a file the cron job reads can
read the key.

This is stated plainly rather than worked around:

- Keep the key outside the document root, alongside `.env`, with the tightest permissions the
  host allows (`0400`, owned by the account user).
- Understand that a compromise of the web application is a compromise of the seal key on this
  profile, and plan the compromise response above accordingly.
- If that is unacceptable for the agreements being signed, the Docker profile — or any
  deployment where the worker is a separate account — is the answer, not a configuration flag.

No setting turns this into isolation, and none is offered.

## Backup and restore

The seal material is a **distinct** backup class from the database and from artifact storage,
with its own handling policy:

- Back it up separately, encrypted, with separate access control. A backup that contains both
  the database and the signing key hands an attacker who gets the backup everything at once.
- Retain the certificate and chain for **every key id ever used**, indefinitely. They are what
  makes an old artifact verifiable without the application.
- Test the restore into an isolated environment with outbound mail and webhooks suppressed, and
  verify restored documents against their recorded digests: `artifacts.sha256` is the
  application's own SHA-256 over the bytes, computed outside the PDF, and it is what a restore
  check compares against. Never an object store's ETag.

## Related

- [`docs/evidence/finalization.md`](../evidence/finalization.md) — where the key id is recorded
  and what each digest covers.
- [`docs/stage0/sealing.md`](../stage0/sealing.md) — what the sealing path was measured to do.
- [`docs/adr/0003-seal-material-and-timestamp-authority.md`](../adr/0003-seal-material-and-timestamp-authority.md)
  — why the first release ships a self-issued certificate.
- [`docs/operations/health.md`](health.md) — the continuous probes.
- [`docs/operations/deploy-docker.md`](deploy-docker.md) — the role split this depends on.
