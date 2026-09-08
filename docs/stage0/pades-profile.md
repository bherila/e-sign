# Stage 0 finding: PAdES profile conformance, checked with DSS

**Status:** established, 2026-09-08. Issue [#7](https://github.com/bherila/e-sign/issues/7),
closing the last part of it. Companion to [`sealing.md`](sealing.md), which is where the
sealing engine, the fixture chain and the pyHanko evidence are described.

**Verdict: the profile claim holds.** European Commission DSS 6.5 reports
`PAdES_BASELINE_B` for the B-B artifacts and `PAdES_BASELINE_T` for the B-T artifacts. Gate 2
in [`docs/security/release-gates.md`](../security/release-gates.md) moves from *not yet proven*
to *proven*.

This document records what DSS actually said, including everything it warned about on
artifacts it accepted, and what still is not established by either validator.

---

## 1. Why a second validator at all

pyHanko's own documentation is explicit that its ordinary validation is **not a complete
structural PAdES-profile conformance check**. `scripts/validate-seal.sh` therefore establishes
three things and no more: the CMS verifies over the byte-ranged content, the signer's
certificate chains to a configured anchor, and the signature covers the whole file. All three
would be equally true of a signature that was a plain PKCS#7 blob in a PDF and satisfied no
ETSI baseline profile at all.

DSS closes exactly that gap. It derives a signature's **format** from its structure —
which signed attributes are present, which are forbidden, what the signature dictionary
contains, whether a signature timestamp is there — and reports it as a `SignatureLevel`. A
signature that failed the baseline profile would come back as `PKCS7_B`, `PDF_NOT_ETSI` or
similar, not as `PAdES_BASELINE_B`. That single enumerated value is the profile verdict this
gate was missing.

## 2. Versions and how it runs

| Component | Version | Licence |
|---|---|---|
| European Commission DSS (`dss-pades-pdfbox`, `dss-validation`, `dss-cms-object`, `dss-utils-apache-commons`, `dss-crl-parser-x509crl`, `dss-policy-jaxb`) | 6.5 | LGPL-2.1-or-later |
| Apache PDFBox (DSS's PDF reader) | 3.0.8 | Apache-2.0 |
| Bouncy Castle | 1.85 | Bouncy Castle Licence |
| Temurin JDK (CI) | 21 | GPL-2.0 with Classpath Exception |

The full 47-artifact transitive tree and its licences are in the **CI-only tooling** section of
[`THIRD_PARTY_NOTICES.md`](../../THIRD_PARTY_NOTICES.md). None of it is distributed: `tools/`
is excluded by `.dockerignore`, and `scripts/build-release.sh` copies a named file list that
does not include it. The application runtime is PHP only, unchanged.

- **Driver:** [`scripts/validate-pades-profile.sh`](../../scripts/validate-pades-profile.sh)
- **Checker:** `tools/dss/src/main/java/PadesProfileCheck.java` (one class, ~330 lines, no
  bespoke crypto — it configures DSS and prints what DSS returns)
- **Policy:** `tools/dss/validation-policy.xml` (see §5)
- **Expectations:** `tests/Fixtures/validation/pades-profile-manifest.tsv`
- **Recorded output:** `tests/Fixtures/validation/dss-output.txt` and `dss-report.json`
- **CI job:** `pades-profile`, on `ubuntu-24.04-arm`, separate from the pyHanko `validation`
  job so a JVM or Maven Central problem cannot mask a pyHanko regression

Three properties of the run are worth stating because they are what make the result mean
something:

1. **It does not reseal.** It reads the committed artifact bytes under
   `tests/Fixtures/validation/` exactly as they are. `sealing.md` said a DSS pass could be
   added later "against exactly these bytes"; this is that, literally. Regeneration stays with
   the pyHanko job, which owns the sealer's freshness.
2. **It trusts one anchor per row and nothing else.** The manifest names the trust mode for
   each row — `fixture-only` (`root.test.crt`) or `rotation-target-only` (`root-b.test.crt`) —
   and DSS is given that anchor alone. No OS trust store, no EU trusted list. An artifact may
   be listed under both modes; §3.1 is why.
3. **It performs no network I/O during validation.** No CRL source, no OCSP source, and
   `AIASource` explicitly set to `null`. That last one was not free: before it was set, DSS
   dereferenced the Authority Information Access URL in the DigiCert timestamp chain mid-
   validation. A verdict that depends on a third party's uptime is not a reproducible verdict.

## 3. The levels DSS reports, per artifact

Twelve committed artifacts, fourteen manifest rows across two trust modes, every one of them
checked. The `level` column is the profile verdict; the `conclusion` column is DSS's AdES status
under the policy in §5.

| Artifact | Trust | Level | Conclusion | Signature timestamp |
|---|---|---|---|---|
| `sealed-b-b.pdf` | fixture-only | **`PAdES_BASELINE_B`** | `TOTAL_PASSED` | none |
| `sealed-b-t.pdf` | fixture-only | **`PAdES_BASELINE_T`** | `TOTAL_PASSED` | 1, `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` |
| `sealed-b-t-untrusted-tsa.pdf` | fixture-only | **`PAdES_BASELINE_T`** | `TOTAL_PASSED` | 1, `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` |
| `finalized-executed.pdf` | fixture-only | **`PAdES_BASELINE_B`** | `TOTAL_PASSED` | none |
| `rotation-retired-key.pdf` | fixture-only | **`PAdES_BASELINE_B`** | `TOTAL_PASSED` | none |
| `rotation-active-key.pdf` | rotation-target-only | **`PAdES_BASELINE_B`** | `TOTAL_PASSED` | none |
| `rotation-retired-key.pdf` | rotation-target-only | `PAdES_BASELINE_B` | `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` | none |
| `rotation-active-key.pdf` | fixture-only | `PAdES_BASELINE_B` | `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` | none |
| `negative-modified-content.pdf` | fixture-only | `PAdES_BASELINE_B` | `TOTAL_FAILED/HASH_FAILURE` | none |
| `negative-forged-cms.pdf` | fixture-only | `PAdES_BASELINE_B` | `TOTAL_FAILED/HASH_FAILURE` | none |
| `negative-byte-range-overclaim.pdf` | fixture-only | `PAdES_BASELINE_B` | `TOTAL_FAILED/FORMAT_FAILURE` | none |
| `negative-incremental-update.pdf` | fixture-only | `PAdES_BASELINE_B` | `TOTAL_FAILED/FORMAT_FAILURE` | none |
| `negative-untrusted-signer.pdf` | fixture-only | `PAdES_BASELINE_B` | `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` | none |
| `negative-truncated.pdf` | fixture-only | — | *unreadable* — DSS refuses the file | — |

**The level is a statement about form, never a verdict.** Every negative artifact except the
truncated one still reports `PAdES_BASELINE_B`, because breaking a document's integrity does
not stop its signature from being structurally a baseline signature. Reading the level column
as "this artifact is fine" would be exactly the mistake this gate exists to prevent; the two
columns answer different questions and both are matched against the manifest, exactly, on
every run.

### 3.1 The seal-key rotation artifacts

Issue [#29](https://github.com/bherila/e-sign/issues/29) added `rotation-retired-key.pdf` and
`rotation-active-key.pdf`, sealed under two different keys with two different roots. Each is
checked twice here, once under each anchor, and DSS reports the diagonal:

|  | under `root.test.crt` (retired) | under `root-b.test.crt` (active) |
|---|---|---|
| `rotation-retired-key.pdf` | `TOTAL_PASSED` | `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` |
| `rotation-active-key.pdf` | `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` | `TOTAL_PASSED` |

Two separate facts fall out, and only the first is also established by pyHanko:

1. **The anchors discriminate.** A `TOTAL_PASSED` on the diagonal would mean nothing if the
   off-diagonal also passed — that would be a validator that trusts everything, not one that
   trusts the right certificate. DSS reports the same shape as pyHanko does in `manifest.tsv`,
   independently.
2. **Both keys produce the same baseline profile, and the off-diagonal cells still read
   `PAdES_BASELINE_B`.** A rotation changes who will vouch for an artifact; it does not change
   what profile the artifact satisfies. The level is a property of the signature, not of the
   reader's trust store, and this is the clearest place in the fixture set where those two come
   apart.

### The structural facts underneath the level

DSS reads these directly out of the PDF and they are in `dss-report.json` per artifact. For
`sealed-b-b.pdf`:

| Property | Value |
|---|---|
| `/Filter` | `Adobe.PPKLite` |
| `/SubFilter` | `ETSI.CAdES.detached` |
| `/ByteRange` | `[0, 1821, 13565, 4566]`, well formed |
| Signature dictionary consistent | yes |
| Digest / encryption algorithm | SHA-256 / RSA |
| PDF object modifications after the signed revision | none |

This is the part pyHanko does not report in this form, and it is what makes the
`PAdES_BASELINE_B` value auditable rather than something taken on trust.

## 4. Every warning DSS raised, including on artifacts it passed

Nothing here is suppressed, and `scripts/validate-pades-profile.sh` copies all of it into the
recorded output on every run so this table can be checked rather than believed.

**On all six `TOTAL_PASSED` artifacts** — the four originals plus each rotation artifact under
its own anchor:

| Warning | What it means | Why it is expected here |
|---|---|---|
| *"The authority info access is not present!"* | the signing certificate carries no AIA extension | it is self-issued; there is no issuer to fetch |
| *"The revocation info access is not present!"* | no OCSP responder or CRL distribution point | same cause — a self-issued certificate publishes neither |

Both warnings are the same underlying fact — **the Stage 0 seal certificate is self-issued** —
seen from two angles. They are not defects in the sealing path and they will not go away by
changing anything in this repository; they go away when an operator installs a CA-issued seal
certificate, which is open gap 4 in [`sealing.md`](sealing.md).

**On the two off-diagonal rotation rows and `negative-untrusted-signer.pdf`,** the single error
*"The certificate chain for signature is not trusted, it does not contain a trust anchor."* and
no warnings at all — DSS stops before it gets as far as noticing the missing AIA.

**On `negative-incremental-update.pdf`,** alongside its error, DSS additionally warns:
*"Visual difference is detected on page(s) [1]"* and *"Document contains changes restricted by
/DocMDP dictionary!"*. Both are true and both are useful; neither is what fails the artifact
(see §5).

**No artifact produced an `info`-level message.**

## 5. The validation policy, and the two edits to it

`tools/dss/validation-policy.xml` is DSS 6.5's own default policy (`policy/constraint.xml`,
shipped inside `dss-policy-jaxb-6.5.jar`) copied verbatim with **two** kinds of edit, each
marked inline in the file. The file header carries the `diff` command to audit it.

### Edit A — revocation availability: `FAIL` → `IGNORE` (6 places)

`RevocationDataAvailable` and `AcceptableRevocationDataFound`, in each certificate context
where DSS ships them at `FAIL`.

A self-issued certificate publishes no responder and no distribution point, so revocation data
cannot exist for this chain. Left at `FAIL`, **every** artifact — clean, tampered, forged
alike — comes back `INDETERMINATE / CERTIFICATE_CHAIN_GENERAL_FAILURE`, and the run
distinguishes nothing. This is the same relaxation pyHanko is already given by
`--no-revocation-check` in `scripts/validate-seal.sh`, and it carries the same obligation: it
must be reverted once the seal certificate is CA-issued. Tracked as open gap 7 in
[`sealing.md`](sealing.md).

### Edit B — `UndefinedChanges`: `WARN` → `FAIL` (2 places)

This one **tightens**, and it exists because of a finding.

At DSS's stock `WARN` level, `negative-incremental-update.pdf` — a genuinely sealed artifact
with an extra revision appended that redefines the page object — receives *exactly the same
AdES conclusion as a clean artifact*. DSS notices the change (`pdfObjectModificationsDetected`
is `true`, and it warns about the visual difference and the `/DocMDP` restriction) but does not
let it fail the signature.

That is the same shape as a finding already recorded in `sealing.md` §5 about pyHanko: *"a
validator's document-modification analysis is not a substitute for the byte-range rule"*. It
is now confirmed on a second, independent validator. This service refuses any artifact whose
signed revision is not the whole file, so the constraint is raised to the level the product
actually enforces, and the stock behaviour is written down here rather than quietly benefiting
from the tightening.

### What the stock policy reports, for comparison

Measured, with no edits at all:

| Artifact | Stock conclusion | Policy conclusion |
|---|---|---|
| `sealed-b-b.pdf` | `INDETERMINATE/CERTIFICATE_CHAIN_GENERAL_FAILURE` | `TOTAL_PASSED` |
| `sealed-b-t.pdf` | `INDETERMINATE/CERTIFICATE_CHAIN_GENERAL_FAILURE` | `TOTAL_PASSED` |
| `sealed-b-t-untrusted-tsa.pdf` | `INDETERMINATE/CERTIFICATE_CHAIN_GENERAL_FAILURE` | `TOTAL_PASSED` |
| `finalized-executed.pdf` | `INDETERMINATE/CERTIFICATE_CHAIN_GENERAL_FAILURE` | `TOTAL_PASSED` |
| `negative-modified-content.pdf` | `TOTAL_FAILED/HASH_FAILURE` | unchanged |
| `negative-forged-cms.pdf` | `TOTAL_FAILED/HASH_FAILURE` | unchanged |
| `negative-byte-range-overclaim.pdf` | `TOTAL_FAILED/FORMAT_FAILURE` | unchanged |
| `negative-incremental-update.pdf` | **`INDETERMINATE/CERTIFICATE_CHAIN_GENERAL_FAILURE`** | **`TOTAL_FAILED/FORMAT_FAILURE`** |
| `negative-untrusted-signer.pdf` | `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` | unchanged |
| `negative-truncated.pdf` | unreadable | unchanged |

**The reported level is identical under both policies, for every artifact.** `PAdES_BASELINE_B`
and `PAdES_BASELINE_T` are structural facts about the signatures; no policy edit touches them.
The profile verdict that closes gate 2 therefore does not rest on the edited policy at all —
only the pass/fail conclusions do.

## 6. Where DSS and pyHanko disagree, and why neither was tuned to agree

One artifact, `sealed-b-t-untrusted-tsa.pdf`: a B-T seal timestamped by `freetsa.org`, whose
root is trusted nowhere.

| Tool | Verdict | Reasoning |
|---|---|---|
| pyHanko | **INVALID** | the TSA certificate has no path to a trust anchor, and pyHanko refuses the artifact on that ground alone |
| DSS | **`TOTAL_PASSED`** at `PAdES_BASELINE_T`, with the timestamp itself reported separately as `INDETERMINATE/NO_CERTIFICATE_CHAIN_FOUND` | an untrusted signature timestamp does not sink a B-T conclusion; it simply fails to contribute a trusted proof of existence, and the signing time is used instead |

Both are right about different questions. pyHanko is asked "should I accept this document?" and
answers no. DSS is asked "what is this signature, and does it validate as one?" and answers
that it is a conformant B-T signature that validates, while telling you plainly that its
timestamp is worthless. **Neither manifest was adjusted to make the two agree**, and the
divergence is recorded as a required outcome in both — `invalid` in `manifest.tsv`, `TOTAL_PASSED`
plus an untrusted-timestamp row in `pades-profile-manifest.tsv`. A future change that made them
agree would fail one of the two.

Note that the DSS run reports **both** B-T artifacts' timestamps as `NO_CERTIFICATE_CHAIN_FOUND`,
including the DigiCert one, because it is given the fixture root as its only anchor. TSA trust
is pyHanko's half of the evidence (`fixture-plus-system` mode in `manifest.tsv`), not this
check's, and this check does not claim it.

## 7. What DSS checks that pyHanko does not

- **The ETSI baseline profile itself.** The `SignatureLevel` value. This is the whole point.
- **The signature dictionary as a structure**: `/Filter`, `/SubFilter`, `/ByteRange`
  well-formedness and internal consistency, reported as facts rather than inferred from a
  pass/fail.
- **An object-level diff of every revision after the signed one**, classified into extension
  changes, signature/form-fill changes, annotation changes, and *undefined* changes — the last
  of which is what edit B raises to a failure.
- **`/DocMDP`, `/FieldMDP` and signature-field lock dictionaries**, evaluated against what
  actually changed.
- **A per-token conclusion for each timestamp**, separate from the signature's, so "B-T" and
  "the timestamp is any good" never collapse into one answer.
- **A standard, externally defined constraint policy**, in a documented format, that can be
  diffed against the tool vendor's own default. pyHanko's checks are configured by CLI flags;
  DSS's are a file.

## 8. What neither tool proves

1. **Nothing about trust in the real world.** Both runs are anchored on a throwaway fixture
   root. `sealed-b-b.pdf` reaching `TOTAL_PASSED` means "valid under a trust configuration we
   invented for the test", which is the correct thing to test and is not a claim about any
   relying party.
2. **Revocation is unchecked, in both tools.** Not because it passed, but because a self-issued
   chain publishes nothing to check. Gap 7 in `sealing.md`.
3. **B-LT and B-LTA are unbuilt and untested.** DSS would report them if they were there. They
   are not.
4. **Not a qualified or advanced electronic signature.** DSS also computes a
   `SignatureQualification`, and nothing in this repository claims one. The seal is an
   organizational seal under a self-issued certificate; the honest-language rule in `AGENTS.md`
   stands unchanged, and a `PAdES_BASELINE_B` verdict is a statement about *format
   conformance*, not about eIDAS status.
5. **Conformance of the *format* is not correctness of the *content*.** No validator checks
   that the sealed PDF says what the parties agreed to.
6. **Only the committed synthetic artifacts are covered.** A regression in the sealer that
   produced a *different* artifact shape would be caught by the pyHanko job, which reseals —
   this job pins the profile of the bytes that are checked in.
7. **Nothing about rotation as an operation.** §3.1 shows that two artifacts sealed under two
   keys each verify under their own anchor and not the other's. It says nothing about the
   rotation command, key custody, or whether an operator would carry a rotation out correctly;
   that is gate 14 and `docs/operations/seal-key-management.md`.

## 9. Reproducing this

```bash
# Needs a JDK 17+ and Maven on PATH. CI provides both.
scripts/validate-pades-profile.sh

# Force a clean rebuild of the checker first
scripts/validate-pades-profile.sh --rebuild

# What DSS says under its own stock policy, with no edits
cd tools/dss && mvn -q package && cd ../..
java -jar tools/dss/target/pades-profile-check.jar \
    --trust tests/Fixtures/crypto/root.test.crt \
    tests/Fixtures/validation/*.pdf

# Audit the policy against DSS's original
unzip -p ~/.m2/repository/eu/europa/ec/joinup/sd-dss/dss-policy-jaxb/6.5/dss-policy-jaxb-6.5.jar \
    policy/constraint.xml > /tmp/dss-stock-policy.xml
diff /tmp/dss-stock-policy.xml tools/dss/validation-policy.xml
```

DSS's own XML simple, detailed and diagnostic reports are written to
`tools/dss/reports/<trust-mode>/` (gitignored, one subdirectory per trust mode so the two
validations of the same artifact do not overwrite each other) and uploaded by the
`pades-profile` CI job as the `dss-pades-profile` artifact.
