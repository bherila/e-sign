# What this product claims, and what it does not

**Audience:** an operator deciding whether to sign something with this, a relying party asked
to accept the result, and counsel reviewing either. It is a statement of assurance, not a
sales page, and every "no" in it is load-bearing.

**Status:** written for the first release. Issue
[#41](https://github.com/bherila/e-sign/issues/41). It describes the pipeline as measured and
tested in this repository, and says so wherever something is proposed, deferred, or unproven.

Two other documents sit under this one and are the evidence for it:
[`docs/stage0/sealing.md`](stage0/sealing.md) is what the sealing path was measured to do, and
[`docs/security/review-2026-09.md`](security/review-2026-09.md) is the adversarial review of
the code that does it.

---

## 1. The one-paragraph version

People provide **electronic signatures and assent** to a specific, frozen revision of a PDF.
The service then applies **one organizational seal** to the executed result, under a
certificate the service — not any signer — holds and controls. The seal establishes that
these bytes have not changed since this service sealed them, and that this service sealed
them. It establishes nothing about who any signer is beyond what the workflow itself
collected, and it is not an eIDAS advanced or qualified electronic signature. Whether the
resulting record is enforceable under ESIGN/UETA is a question about the whole workflow —
consent, authority, access, retention — and is for counsel, not for this software.

---

## 2. Two different acts, deliberately not conflated

| | Who does it | What it is | What it proves |
|---|---|---|---|
| **Electronic signature / assent** | the human recipient | two explicit tick boxes (read the notice; intend to be bound), plus a typed or drawn mark, recorded against a named consent-policy version and a digest of exactly what was displayed | that a person using this session, at this time, stated they had read the notice and intended to be bound by *these* bytes |
| **Service seal** | the service | one PAdES signature over the finished PDF, using the operator's organizational certificate | that the finished bytes have not changed since this service produced them, and that this service produced them |

The seal is **not** a per-signer certificate. No signer owns it, holds its key, or exclusively
controls it. Every signer on every envelope on a given deployment is sealed by the same key.
The completion report bound into each executed agreement says this in as many words on the
page, and is labelled a **report**, not a certificate.

The distinction is not pedantry. A relying party reading "digitally signed by ACME eSign" in a
PDF viewer is being told something true about ACME's service and nothing at all about the
person whose name is printed on the signature line. Anything that person's identity is worth
comes from the workflow evidence described in section 6, not from the cryptography.

---

## 3. What the cryptography is, exactly

### Profile

| Level | Status | What it means here |
|---|---|---|
| **PAdES-BASELINE-B (B-B)** | produced, and independently checked | detached CAdES CMS in the signature dictionary (`/SubFilter /ETSI.CAdES.detached`), SHA-256 (SHA-384/512 configurable), RSA, `/ByteRange` covering the whole file |
| **PAdES-BASELINE-T (B-T)** | produced when an RFC 3161 authority is configured | B-B plus a signature timestamp token from that authority |
| **B-LT / B-LTA** | **not produced** | requires revocation material a self-issued certificate does not publish. Out of scope until the seal certificate is CA-issued, and it is a preservation *operation*, not a flag |

There is no bespoke cryptography in this repository. CMS, ASN.1, and PDF byte-range
construction come from `tecnickcom/tc-lib-pdf-sign`. Byte-level results, versions, and the
validator output are in [`docs/stage0/sealing.md`](stage0/sealing.md).

**A requested level that cannot be reached is an error, never a quiet downgrade.** The sealer
re-reads its own output and refuses to return an artifact that does not meet the level asked
for. An unreachable timestamp authority, a rejected token, an expired certificate, a key that
does not match its certificate, or a digest algorithm outside the allowed set each throw a
typed exception and no envelope completes.

### The default certificate is self-issued, and a viewer will say so

A stock deployment seals with a **self-issued organizational certificate** whose subject
discloses the operator ([ADR 0003](adr/0003-seal-material-and-timestamp-authority.md)). That
is a real cryptographic signature and a real integrity guarantee. It is not trust.

What a reader will actually see:

- **Adobe Acrobat / Reader:** a yellow or red banner — "At least one signature has problems",
  or "The signature is not trusted" / "signer's identity is unknown". The document will *not*
  show a green tick. Acrobat trusts the Adobe Approved Trust List (AATL) and the OS store; a
  self-issued certificate is in neither.
- **Chrome, Firefox, Safari, macOS Preview, most mobile viewers:** either nothing at all —
  they do not validate PDF signatures — or an unverified-signature notice.
- **A validator given the operator's root explicitly:** valid. That is exactly how this
  repository's own CI checks it.

Making a viewer show a trusted seal is **an operator decision, not a code change**: install a
CA-issued document-signing certificate (AATL for Acrobat) and rotate to it. The application
treats that as an ordinary key rotation; see
[`docs/operations/seal-key-management.md`](operations/seal-key-management.md). Nothing in this
product can confer trust on a certificate the reader's software has never heard of, and no
configuration flag pretends otherwise.

### What "TSA trusted" requires

A timestamp token proves an authority asserted that these signature bytes existed by a stated
time. That assertion is worth exactly what the authority is worth to the reader, and the
reader has to be able to build a path from the TSA certificate to a root it already trusts,
with that root asserting the timestamping purpose. In practice, one of:

1. a TSA whose root is already in the relying party's store — the only case needing no
   configuration anywhere;
2. an explicit trust list distributed to every validator and viewer that must accept these
   artifacts — operationally the same problem as distributing a private seal root;
3. a qualified trust service under an EU trusted list, which brings contractual and
   supervisory arrangements an operator cannot self-assert.

This was measured rather than assumed. Two public authorities were tried against the same
pipeline: one produced a token an off-the-shelf validator accepted, the other produced a token
the same validator called *untrusted* on TSA trust alone. Both artifacts are committed as
fixtures, and the second is required by the manifest to **fail**.

Consequences worth stating plainly:

- **A self-hosted timestamp service is not an independent witness** merely because it speaks
  RFC 3161. If the operator runs the TSA, the operator has attested to the operator's own
  timing.
- **A signer's acceptance time and a TSA time are different claims.** The acceptance time is
  this server's clock at the moment assent was recorded. The TSA time is a third party's claim
  about when the finished seal existed. The evidence record keeps them in separate fields and
  never merges them; see section 6.
- The application validates a token and **fails closed** on a bad one. It does not, and
  cannot, vouch for the authority.

---

## 4. What is *not* claimed

Each of these is a deliberate refusal, and the reason follows it.

### No eIDAS advanced or qualified electronic signature

Email possession plus an organizational seal does not make an advanced (AdES) or qualified
(QES) *human* signature under eIDAS. An advanced signature must be uniquely linked to and
under the sole control of the signatory; the seal here is under the service's control and is
shared across every signer on the deployment. A qualified signature additionally requires a
qualified certificate, a qualified signature creation device, and a qualified trust service
provider's identity process. None of that is present, and adding a configuration flag named
after it would not add it. Qualified support is an explicit **future integration boundary**.

Optional mailbox OTP does not change this. It demonstrates *continued* access to the same
mailbox the invitation already went to: a second check on one factor, not a second factor. It
is recorded as `email_otp` in the evidence and grades nothing.

### No WORM, no storage-enforced legal hold, no tamper-proof storage

The S3-compatible storage this product is built against (Garage) has **no Object Lock and no
object versioning**. So:

- **Legal hold here is an application rule**, enforced by this application's code against this
  application's database. A privileged operator with direct database or bucket access is not
  subject to it.
- Artifact keys are unique and never overwritten, and deletion paths are restricted and
  audited — but that is a discipline, not a storage guarantee.
- **Independently administered off-host copies** are what turn this into deletion and
  tampering resilience. Duplication on the same host under the same credentials is not
  independence. See [`docs/operations/backups.md`](operations/backups.md).

### The hash chain is not an independent witness

Every acceptance is digested together with the previous acceptance's digest, producing an
append-only chain. That detects a *later, partial* edit to the evidence: change one
attestation and every subsequent digest stops matching.

It does not detect an attacker who can rewrite the whole chain, which is exactly what
possession of the database allows. **A hash chain in an administrator-writable database is not
an independent witness.** It is a consistency check, and it is honest about being one. What
would make it a witness is something the operator cannot rewrite: an off-host checkpoint at a
separate administrative boundary, a copy in the signer's own hands, or a timestamp from an
authority the reader independently trusts. The first two are operational recommendations in
[`docs/operations/backups.md`](operations/backups.md); the third is section 3.

### No absolute non-repudiation, no blanket legal enforceability

Neither is a property software can supply. What is supplied is a specific, checkable record;
what it is worth in a dispute depends on the process around it.

---

## 5. Residual threats, stated rather than mitigated away

| Threat | What the product does | What remains |
|---|---|---|
| **Compromised application** | Guest credentials are hashed verifiers, keys are files outside the document root, logs carry no tokens, and the adversarial review in [`docs/security/review-2026-09.md`](security/review-2026-09.md) is the current state of that. | Code executing as the application can read the seal key, mint an acceptance, and rewrite the hash chain. Everything sealed after the compromise is suspect, and so is every evidence record. |
| **Compromised seal key** | Key ids are versioned and recorded on every artifact, and verification resolves the certificate from the id the artifact recorded rather than from the active configuration, so a rotation partitions history by key id and leaves every generation verifiable; a deployment that can no longer resolve some artifact's key id fails readiness rather than passing quietly. Expiry is monitored, `esign:seal:rotate` refuses material that would produce unattributable artifacts, a rotation drill runs in CI (in-process and again under pyHanko), and a compromise runbook exists. Executed agreements are never re-sealed: a re-seal would be a new artifact attesting only that this service held these bytes today, not a restoration of the original execution. | Anyone holding the key can seal an arbitrary PDF that verifies exactly like a genuine one. Only a trusted timestamp taken *before* the compromise distinguishes artifacts sealed before it from artifacts forged after. This is the single strongest argument for configuring B-T with a TSA the relying party already trusts. |
| **Hostile or compromised host administrator** | Nothing. | An administrator can read a live OTP out of the mail outbox, read the seal key, alter the database, and delete artifacts under legal hold. The evidence model does not defend against the host it runs on, and does not claim to. |
| **Shared hosting: no key isolation** | On the Docker profile the seal key is mounted into the worker role only, so a compromised web request cannot read it. | **On a single-account cPanel host that separation does not exist.** Cron queue work and web requests run as the same user with the same PHP; anything that can read the file the cron job reads can read the key. Compromise of the web application *is* compromise of the seal key on that profile. No setting turns this into isolation and none is offered. If that is unacceptable for the agreements being signed, the answer is a deployment where the worker is a separate account — not a flag. |
| **Operator retains web access logs** | Signing pages send `Referrer-Policy: no-referrer` and load nothing from another origin. | The invitation credential is in the URL path, so it appears in the web server's and any reverse proxy's access logs. An operator retaining those retains live credentials for their TTL. A deployment that cares should exclude `/sign/*` from access logging; the application cannot do it. |
| **Forwarded invitation** | Optional mailbox OTP; reissuing revokes the forwarded link. | With OTP off, possession of the link is the bar. That is the stated assurance class, not an oversight. |

---

## 6. What the evidence bundle contains, and what each digest covers

`EvidenceExporter::bundle()` produces a ZIP with: the **original upload** (byte-for-byte, never
re-rendered), the **reviewed revision** the signers actually saw, the **executed PDF**, the
**evidence JSON**, the **completion report**, the **seal certificate chain**, the **validation
report**, and a **`manifest.json`** listing every file with its SHA-256 and a statement of what
that digest covers.

A digest whose subject is unstated proves nothing, so each is named:

| Digest | Covers |
|---|---|
| `document_sha256` | the complete bytes of the reviewed document revision as retained, and as every attestation binds it — **not** the executed PDF |
| `field_schema_sha256` | the canonical JSON of the field schema copied onto the envelope at creation |
| `material_values_sha256` | the canonical encoding of the shared agreement content; signer-specific values are excluded by design |
| `field_value_sha256:<id>` | the canonical JSON encoding of one stored field value |
| `attestation_sha256:<id>` | one acceptance — envelope and recipient ids, the document/schema/material digests, the consent version displayed, the session it was given in, the server acceptance time, the verification method, minimized client evidence, and the previous acceptance's digest |
| `artifact_sha256:executed_pdf` | the complete published bytes of the sealed executed PDF, computed **outside** that PDF |
| `artifact_sha256:completion_report` | the complete published bytes of the standalone report |
| `seal_certificate_sha256` | the DER of the certificate the CMS verified against |

Three points that are easy to get wrong and are pinned by tests:

- **No digest is self-referential.** The evidence document's own digest is not inside it, and
  the executed PDF's digest is not printed on the completion page bound into that PDF.
- **An object store's ETag is never any of these.** An ETag is a transfer checksum whose
  algorithm depends on how the object was uploaded. A test greps the whole of `app/` for the
  word and fails if it appears.
- **The manifest is not signed**, deliberately. Signing an inventory of one's own evidence with
  the same key adds no independent assurance. The seal that matters is inside the executed PDF.

The recorded evidence about a *person* is deliberately narrow: an identity snapshot, the
claimed organizational capacity, the verification method and its result, the consent version
displayed, the server's acceptance timestamp, and a minimized allowlist of network evidence
(`ip`, `user_agent`, `accept_language`, `channel`). **An IP address and a user agent are not
identity proof** and nothing in the pipeline treats them as any.

---

## 7. The validators used, and their stated limits

| Tool | What it is run against | What it establishes |
|---|---|---|
| **pyHanko 0.37.0** (+ `pyhanko-cli` 0.5.0), in the `validation` CI job via `scripts/validate-seal.sh` | every committed synthetic artifact in `tests/Fixtures/validation/`, **resealed first** | the CMS verifies over the byte-ranged content, the signer's certificate chains to a configured anchor, and the signature covers the whole file |
| **European Commission DSS 6.5**, in the `pades-profile` CI job via `scripts/validate-pades-profile.sh` | the same artifacts, **committed bytes, not resealed**, against `tests/Fixtures/validation/pades-profile-manifest.tsv` | which ETSI EN 319 142-1 baseline profile each signature actually satisfies — reported as a `SignatureLevel` — plus DSS's own AdES conclusion and a separate conclusion per timestamp token |
| **`ArtifactValidator`** (in-process, tc-lib-pdf) | the sealer's own output, before it is returned | the produced artifact reaches the level that was requested; below it, `SealFailedException` |

The two external validators share no code, run in separate CI jobs so that neither's
infrastructure can mask the other's regression, and are **not** tuned to agree: one artifact,
`sealed-b-t-untrusted-tsa.pdf`, is required to be INVALID for pyHanko (its timestamp authority
is trusted nowhere) and `TOTAL_PASSED` for DSS (an untrusted timestamp does not sink a B-T
conclusion, it only fails to contribute a trusted proof of existence). Both verdicts are
correct about different questions and both are pinned.

The positive artifacts are checked alongside deliberate negatives — modified content, an
over-claimed `/ByteRange`, a truncated file, an appended incremental revision, a spliced
foreign CMS, an untrusted signer, and an untrusted TSA — each required to fail, and each
matched against pyHanko's **stated verdict** rather than its exit status, because pyHanko exits
non-zero for an unreadable file and an environment error alike.

**pyHanko's documented limitation applies and is not glossed over.** Its ordinary validation is
**not a complete structural PAdES-profile conformance check**. A VALID verdict means the three
things in the table above. It does *not* establish that the artifact satisfies every clause of
ETSI EN 319 142-1 V1.2.1 — which attributes are required, forbidden, or must be absent at each
baseline level. That is precisely why DSS was added as a second validator rather than as
another flag on the first.

**DSS reports `PAdES_BASELINE_B` for the B-B artifacts and `PAdES_BASELINE_T` for the B-T
artifacts.** So the profile claim in section 3 now rests on a conformance verdict from a tool
built to give one, and not only on a cryptographic check plus the library's documented
implementation. The Java runtime stays out of the application: `tools/` is excluded from the
production image and the release bundle, and DSS is fetched from Maven Central by a CI job and
discarded with the runner.

**What that verdict still does not mean.** It is a statement about **format conformance**, not
about eIDAS status: nothing here is a qualified or advanced electronic signature, and the seal
remains an organizational seal under a self-issued certificate. Both runs are anchored on a
throwaway fixture root, so neither says anything about trust in the real world; revocation is
unchecked in both, because a self-issued chain publishes no responder and no distribution
point; and DSS's stock policy has one constraint (`UndefinedChanges`) set below the level this
service enforces, which the pinned policy raises and
[`docs/stage0/pades-profile.md`](stage0/pades-profile.md) records in full alongside every
warning DSS raised on artifacts it passed.

Two further limits on validation as practised here:

- The artifacts checked are **synthetic**. Confidential agreements are never uploaded to a
  public demo validator.
- Browser and manual Acrobat checks **supplement** the automated ones. They do not replace
  them, and an Acrobat green tick on an operator's own machine usually means that machine
  trusts the operator's own root.

---

## 8. ESIGN / UETA: a workflow objective, not a feature

Under the US ESIGN Act and UETA, an electronic signature is not made valid by a cryptographic
primitive. It is made valid by the record of a person's intent to sign, associated with the
record, in a process the parties agreed to and that can be reproduced later.

This application is built to *support* that process. It does not deliver it, and **support for
ESIGN/UETA is an end-to-end workflow and recordkeeping objective requiring counsel's review**,
not something a library certificate can confer. Before a first production use, counsel should
approve at least:

1. **The consent text and its versioning.** The notice lives at
   `resources/views/signing/consent.md` — a repository file, reviewed and diffed like code,
   not a database row an administrator could rewrite between somebody reading it and the
   attestation recording that they read it. The envelope snapshots which version applied;
   a mismatch at acceptance is a refusal, never a silent acceptance of current text. Counsel
   approves the wording and the version discipline, including any consumer-disclosure
   requirements that apply to the transaction.
2. **Signer authority.** The product records a *claimed* organizational capacity. It does not
   verify that the person signing may bind the entity named. If that matters for the agreement
   type, the control is in the sender's process.
3. **Access and the ability to obtain a copy.** Every party must be able to retain and
   reproduce the record. The evidence bundle in section 6 is the technical means; who is
   entitled to it, on request or automatically, and for how long, is policy.
4. **Retention and deletion.** Executed documents are **never deleted automatically** until an
   operator configures a reviewed policy, and legal holds override deletion within the
   application. The retention periods, the erasure workflow, and the hold procedure need legal
   sign-off. See [`docs/operations/retention.md`](operations/retention.md).

Do not market blanket legal enforceability on the strength of this software.

---

## 9. Related

- [`docs/security/review-2026-09.md`](security/review-2026-09.md) — the adversarial security
  review, its findings, and the accepted residuals.
- [`docs/security/release-gates.md`](security/release-gates.md) — every release gate mapped to
  the test or CI job that proves it, or an explicit "not yet proven".
- [`docs/stage0/sealing.md`](stage0/sealing.md) — what the sealing path was measured to do.
- [`docs/stage0/pades-profile.md`](stage0/pades-profile.md) — what profile DSS says the
  artifacts actually reach, every warning it raises, and what neither validator proves.
- [`docs/evidence/finalization.md`](evidence/finalization.md) — how an artifact is produced,
  validated, stored, and published, and what each digest covers.
- [`docs/operations/seal-key-management.md`](operations/seal-key-management.md) — key custody,
  rotation, compromise response, and the shared-hosting isolation gap.
- [`docs/signing/guest-access.md`](signing/guest-access.md) — the signer-facing threat model.
- [`docs/operations/retention.md`](operations/retention.md) — retention, legal hold, and
  privacy erasure.
- [`docs/HANDOFF.md`](HANDOFF.md) sections 8 and 9 — the binding specification these claims
  answer to.
