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
| `ESIGN_SEAL_RETIRED_KEYS` | Certificates of key versions this deployment has retired, so their artifacts stay verifiable. Comma-separated `key-id\|certificate-path[\|chain-path]` entries. **Certificates only — never a retired private key.** |
| `ESIGN_TSA_URL` | RFC 3161 endpoint. Unset means the deployment can only produce B-B. |

### One active key, many verifiable keys

Sealing uses exactly one key: the one `ESIGN_SEAL_KEY_ID` names. Verification cannot, and this
is the distinction the whole rotation story rests on.

Every artifact records the `seal_key_id` and `seal_certificate_sha256` that produced it, and
keeps them forever. After a rotation, the deployment is holding documents whose key id is no
longer the active one. `esign:artifacts:verify` and the `signing_material` probe resolve the
certificate for **the key id the artifact recorded**, through
`App\Domain\Evidence\Sealing\SealCertificateDirectory` — never from the active
configuration. So:

- an artifact sealed under a retired key still verifies, against the retired certificate;
- a deployment that can no longer resolve some artifact's key id says so, loudly, as a
  readiness **failure** and a non-zero `esign:artifacts:verify`. That is an evidence gap: the
  bytes are intact and nobody on this host can say who sealed them;
- **verifying never needs a private key.** A retired key's private half should already have
  been destroyed on whatever schedule your key policy sets. Only the certificate is kept.

`ESIGN_SEAL_RETIRED_KEYS` is a compact list rather than a directory convention on purpose. It
is one line in one file on every deployment profile, including shared hosting where there is
no orchestration to mount a directory; the key id is written down rather than derived from a
filename, so renaming a file cannot silently re-map artifacts to a different certificate; a
certificate that merely happens to sit on disk is not thereby accepted; and the parsed result
is plain arrays, so `config:cache` exports it unchanged.

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

Then it lists the **retired** keys, each with whether its certificate still loads, its
fingerprint, and its expiry. An expired retired certificate is reported as expired and is not
a problem — the artifacts it sealed were sealed while it was valid, which is the whole reason
the certificate is retained. A retired certificate that cannot be *loaded* is a problem, and
fails the exit status.

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

`signing_material` asks a second question that only matters after a rotation: **does every
published artifact name a key id this deployment can still resolve?** If any does not, the
probe fails. It is a failure and not a warning because the fix is small and mechanical — put
the retired certificate back in `ESIGN_SEAL_RETIRED_KEYS` — and because warning about it
would train an operator to scroll past the one signal that says the archive stopped being
verifiable.

There is a third gate, and it is the one that matters most operationally: **an envelope cannot
be sent when the material is unusable.** `SealMaterialAssurancePolicyCheck` runs at send time
and refuses before any signer is invited. A deployment whose certificate expired last week
finds out when someone tries to send, not after two people have signed.

## Rotation

**A rotation is a new key id and new files. It is never an edit of the existing ones.**

Old artifacts stay verifiable because each one embeds the certificate that sealed it in its own
CMS, each `artifacts` row records `seal_key_id` and `seal_certificate_sha256`, and this
deployment keeps the retired certificate in `ESIGN_SEAL_RETIRED_KEYS`. Nothing has to be
re-sealed, and nothing may be: re-sealing an executed agreement would produce different bytes
for a document people have already signed.

### 1. Check the new material before changing anything

```bash
php artisan esign:seal:rotate \
  --key-id=seal-2027-a \
  --certificate=/srv/esign-keys/2027-01/seal.crt \
  --private-key=/srv/esign-keys/2027-01/seal.key \
  --chain=/srv/esign-keys/2027-01/chain.crt
```

This is a **read-only check**. It opens the proposed certificate and key, proves they are
usable and belong together, compares them with what is in force, and prints the exact
environment change that adopts them. It never edits `.env`, never copies a key file, never
prints a byte of a private key, and never touches an artifact. Running it on a live deployment
changes nothing about how that deployment seals.

It refuses, with a non-zero exit status, when:

| Refusal | Why it matters |
|---|---|
| The certificate or key will not load | Rotating onto material that cannot seal takes the service down at the next finalization. |
| The certificate has expired, or is not yet valid | Same. |
| The key does not match the certificate | Same, and it is the failure that looks like a working configuration until something signs. |
| `--key-id` repeats the id in force | The id is how an artifact says which material sealed it. Reusing it makes artifacts from before and after the rotation indistinguishable. |
| `--key-id` is already in `ESIGN_SEAL_RETIRED_KEYS` | Same ambiguity, one generation further back. |
| The certificate is the one already in force | A new id over the same material is not a rotation. |

If the key is passphrase-protected, pass the **name** of an environment variable holding the
passphrase, never the passphrase itself: `--passphrase-env=ESIGN_NEW_SEAL_PASSPHRASE`. A
`--passphrase=` option would put the secret in shell history, in `ps` output for the life of
the process, and in any command-line auditing the host does; there is no such option.

Add `--dry-run` to check without recording anything. Without it, a successful run records a
`seal.key.rotation_prepared` audit event carrying the outgoing and incoming key ids and
certificate fingerprints — and no filesystem path and nothing derived from a key.

### 2. Apply the change the command printed

The command prints the five or six lines to change. The one that is easy to forget is the
last:

```
ESIGN_SEAL_KEY_ID=seal-2027-a
ESIGN_SEAL_CERTIFICATE_PATH=/srv/esign-keys/2027-01/seal.crt
ESIGN_SEAL_PRIVATE_KEY_PATH=/srv/esign-keys/2027-01/seal.key
ESIGN_SEAL_CHAIN_PATH=/srv/esign-keys/2027-01/chain.crt
ESIGN_SEAL_RETIRED_KEYS="seal-2026-a|/srv/esign-keys/2026-01/seal.crt|/srv/esign-keys/2026-01/chain.crt"
```

**`ESIGN_SEAL_RETIRED_KEYS` is the line the rotation lives or dies on.** Dropping the outgoing
key id out of it is what turns every document sealed before today into evidence this
deployment cannot attribute. The command builds the new value from the current one with the
outgoing key appended, so it is a copy rather than a composition.

Put the new files in place first, with the same ownership and permissions as the current ones,
readable only by the role that seals.

### 3. Restart, and prove it

```bash
php artisan config:clear                # or config:cache, if this deployment caches config
# restart the worker role. Web and scheduler do not seal.

php artisan esign:seal:status                          # the new key id is in force;
                                                       # the old one is listed as retired
php artisan esign:artifacts:verify --key-id=seal-2026-a # the retired generation still verifies
php artisan esign:artifacts:verify                      # and so does everything else
```

`--key-id` narrows a verification run to one key generation, and the run row records that it
was narrowed: a run that looked only at the retired key's artifacts is not evidence about the
others.

Retain the old certificate and its chain indefinitely with the export material. The old
*private key* is not needed for verification and should be destroyed on the schedule your key
policy sets.

Envelopes already sent are unaffected: their assurance level is fixed at send, and the
finalization that runs later uses whatever material is configured then, recording that key id
on the artifacts it produces.

### Deployment-profile steps

**Docker (worker-only key mount).** The seal material is mounted into the worker service and
no other, so a rotation touches exactly one service:

1. Put `/srv/esign-keys/2027-01/` on the host with the new certificate, key, and chain, owned
   by the container's non-root user, mode `0400`.
2. Run the check from inside the worker container, where the mount is visible:
   `docker compose exec worker php artisan esign:seal:rotate --key-id=… --certificate=… --private-key=…`
   The check must run where the files are; running it in `web` will report the paths as
   unreadable, correctly.
3. Add the new directory to the worker's `volumes:` **alongside** the current one — both are
   mounted during the changeover, because the retired certificate has to stay readable:

   ```yaml
   worker:
     environment:
       ESIGN_SEAL_KEY_ID: seal-2027-a
       ESIGN_SEAL_CERTIFICATE_PATH: /run/secrets/esign-seal/2027-01/seal.crt
       ESIGN_SEAL_PRIVATE_KEY_PATH: /run/secrets/esign-seal/2027-01/seal.key
       ESIGN_SEAL_RETIRED_KEYS: "seal-2026-a|/run/secrets/esign-seal/2026-01/seal.crt"
     volumes:
       - /srv/esign-keys/2026-01:/run/secrets/esign-seal/2026-01:ro   # certificate only, from now on
       - /srv/esign-keys/2027-01:/run/secrets/esign-seal/2027-01:ro
   ```

4. `docker compose up -d worker`. Web and scheduler are not restarted and get no seal material.
5. Once the retired private key has been destroyed per policy, the `2026-01` mount holds only
   the certificate and chain. Keep the mount. Removing it is what breaks verification of every
   document sealed in 2026, and `signing_material` will fail the moment it happens.

**cPanel / shared hosting.** One account, one `.env`, one PHP:

1. Put the new material outside the document root, alongside the current material and `.env`,
   mode `0400`, owned by the account user.
2. `php artisan esign:seal:rotate …` over SSH, using the account's PHP CLI binary. If the
   web vhost runs a different PHP version than the CLI (`esign:doctor` reports both), the
   rotation check still stands: it is OpenSSL, not a PHP-version-sensitive path.
3. Edit `.env`, including `ESIGN_SEAL_RETIRED_KEYS`, then `php artisan config:clear`.
4. There is no worker to restart; the next cron tick of `esign:queue:work-bounded` picks up
   the new configuration. A finalization already in flight finishes under the old key, which
   is correct — it records that key id.
5. **The isolation limit applies to the retired key exactly as it does to the active one.**
   Anything that can read the active key file can read the retired one. That does not matter
   for the retired *certificate* (it is public), and it is the reason the retired private key
   should be removed from the host as soon as policy allows, rather than left beside it.

### The drill, and what it proves

`tests/Feature/Evidence/SealRotationDrillTest.php` performs a rotation on every CI run: it
seals a document under key A, rotates to key B, seals a second document, and then proves

- both artifacts still verify;
- each verifies against **its own** certificate — registering the wrong certificate under the
  retired key id is caught, so "resolved from the recorded key id" is a tested claim and not a
  comment;
- removing key A's certificate from the deployment makes the first artifact fail verification
  as an *unresolvable seal key*, with the bytes reported as intact, and makes the
  `signing_material` probe fail;
- putting it back repairs both;
- the new envelope seals under key B, and one unfiltered `esign:artifacts:verify` passes over
  both generations.

That is a self-check: it verifies with the same library it signs with. The independent half is
in CI's `validation` job, where pyHanko — which shares no code with this application — is
given `rotation-retired-key.pdf` and `rotation-active-key.pdf` and must judge each VALID
against its own key's trust anchor and INVALID against the other's. The negative direction is
what makes the positive one mean something: without it, a validator that trusted everything
would produce the same two VALID verdicts. See `scripts/validate-seal.sh` and
`tests/Fixtures/validation/manifest.tsv`.

### Expiry planning

Rotate before expiry, not at it. The signature over an already-sealed document does not stop
verifying when the certificate expires — the certificate was valid when it signed, and
`SealCertificateDirectory` deliberately does not refuse an expired certificate for
verification — but a deployment whose certificate has expired cannot seal anything new and
cannot send anything at all, because the send gate refuses.

Watch `days remaining` in `esign:seal:status` and the `signing_material` probe. Set
`ESIGN_HEALTH_CERT_WARN_DAYS` to comfortably more than your procurement lead time.

## Compromise response

A compromised seal key means anyone holding it can produce bytes that look like this service's
seal. Treat it as an incident, not a maintenance task.

1. **Stop sealing.** Move the private key file out of the worker's reach and restart the
   worker. Finalization then fails closed — envelopes go to `finalization_failed`, which is
   visible and retryable — rather than sealing with a key you no longer control. Sending is
   also refused. That is the intended blast radius.
2. **Revoke**, if the certificate was CA-issued, and do it before or alongside the rotation
   rather than after: revocation is the only signal that reaches a relying party who never
   talks to you. A self-issued certificate has no revocation path at all, which is one of the
   costs recorded in ADR 0003 — on that profile, step 5 is the revocation.
3. **Rotate**, with a fresh key id:
   `php artisan esign:seal:rotate --key-id=… --certificate=… --private-key=…`, then the
   environment change it prints. Do not reuse the compromised id: the id is how an artifact
   says which material sealed it, and reusing it makes artifacts from before and after the
   incident indistinguishable.

   **Keep the compromised certificate in `ESIGN_SEAL_RETIRED_KEYS`.** This is counter-intuitive
   and it is correct. The certificate is public, it was always public, and retaining it is not
   a residual exposure — the private key is the exposure, and that is destroyed. What retaining
   it buys is the ability to say, of any given artifact, "this one was sealed by the
   compromised generation": drop it and the deployment loses the ability to sort its own
   history into before and after.
4. **Re-verify, and record the result.** Two runs, both worth keeping:

   ```bash
   php artisan esign:artifacts:verify --key-id=<compromised id>   # the exposed generation
   php artisan esign:artifacts:verify                             # everything
   ```

   A pass here means the stored bytes still hash to their published digests and still carry a
   seal that verifies against the certificate the row names. Be precise about what that is
   worth after a key compromise: it proves the artifacts **have not been altered in this
   deployment's storage**. It does not, and cannot, prove that no *other* document was sealed
   elsewhere with the stolen key. Nothing running here can prove that.
5. **Enumerate the exposure.** Every artifact sealed with the compromised key id is
   `SELECT * FROM artifacts WHERE seal_key_id = '<id>'`. Their published digests are in
   `artifacts.sha256` and in each envelope's evidence document, and those digests were computed
   before the key was exposed if your off-host evidence copies predate the incident. That is
   what off-host copies are *for*.
6. **Say so.** A compromised organizational seal is a fact relying parties need. Nothing in the
   application can decide that for you.
7. **Re-seal nothing.** This is the step people ask for, so it is worth being blunt about what
   re-sealing would and would not mean.

   Re-sealing an executed agreement under the new key would produce **a new artifact**: new
   bytes, a new SHA-256, a new signing time, sealed today by a key the signers never saw. It
   would not restore the original execution, because there is nothing in it that says the
   document was in this state on the day the parties accepted it — the new seal attests only
   that this service held these bytes today. It would replace a seal made at the time of
   signing, and now doubtful, with a seal made after the incident, which is worse, and it would
   destroy the byte-for-byte artifact people actually signed. `artifacts` is immutable and the
   application will not do it.

   What still carries weight after a seal-key compromise is everything that is *not* the seal:
   the acceptance record and its chained attestation digests, the evidence document, the
   published digests in your off-host copies, the signers' own copies, and — the strongest of
   them — an RFC 3161 timestamp taken **before** the compromise, from an authority the relying
   party already trusts. That timestamp is the one thing that distinguishes an artifact sealed
   before the key was exposed from one forged after. It is the strongest argument for running
   at PAdES B-T rather than B-B.

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
      ESIGN_SEAL_CERTIFICATE_PATH: /run/secrets/esign-seal/2026-01/seal.crt
      ESIGN_SEAL_PRIVATE_KEY_PATH: /run/secrets/esign-seal/2026-01/seal.key
      # Certificates of retired generations. Empty until the first rotation; after one, this
      # is what keeps the previous generation's artifacts verifiable. Certificates only.
      ESIGN_SEAL_RETIRED_KEYS: ""
    volumes:
      # Dated subdirectory from day one, so the first rotation adds a mount instead of
      # rearranging the ones that already exist.
      - /srv/esign-keys/2026-01:/run/secrets/esign-seal/2026-01:ro

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
  the worker. The same applies to its rotation half: the web role cannot resolve any key id,
  so it reports the missing material first and does not additionally report every artifact as
  unattributable.
- A retired generation's mount stays. It holds only a certificate once the private key has
  been destroyed, and removing it is what breaks verification of everything that key sealed.

### cPanel / shared hosting — the isolation is not available

On a single-account shared host, cron-driven queue work and the web requests run as the same
user with the same PHP. **There is no way to expose the seal key to the queue worker and not to
application PHP handling a web request.** Anything that can read a file the cron job reads can
read the key.

This is stated plainly rather than worked around:

- Keep the key outside the document root, alongside `.env`, with the tightest permissions the
  host allows (`0400`, owned by the account user). Retired *certificates* live in the same
  place and are listed in `ESIGN_SEAL_RETIRED_KEYS`; they are public and their exposure is not
  a finding. Retired *private keys* should be off the host entirely as soon as policy allows,
  because on this profile they are exposed to exactly what the active key is exposed to.
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
- Retain the certificate and chain for **every key id ever used**, indefinitely, and keep every
  one of them listed in `ESIGN_SEAL_RETIRED_KEYS` on the live deployment. They are what makes an
  old artifact verifiable, with or without the application. A restore that brings the database
  back without the retired certificates beside it produces a deployment that fails
  `signing_material` and `esign:artifacts:verify` — correctly, because it is holding evidence
  it cannot attribute.
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
