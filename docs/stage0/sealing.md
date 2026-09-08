# Stage 0 finding: PAdES sealing in PHP

**Status:** feasibility established, 2026-09-08. Issues [#7](https://github.com/bherila/e-sign/issues/7)
and [#8](https://github.com/bherila/e-sign/issues/8).

**Verdict: go.** `tecnickcom/tc-lib-pdf` produces PAdES-BASELINE-B and PAdES-BASELINE-T
signatures that an independent validator accepts, on PHP alone, with no bespoke
CMS/ASN.1/byte-range code in this repository. The gate in
[`docs/adr/0004-pdf-engine-candidate.md`](../adr/0004-pdf-engine-candidate.md) is met; no
alternative engine needs evaluating.

Read this alongside the honest-language rule in
[`AGENTS.md`](../../AGENTS.md): what follows is what was *measured*. Where something is
proposed rather than verified, it says so.

## 1. Versions

Measured on the run that produced the committed artifacts.

| Component | Version | Licence |
|---|---|---|
| `tecnickcom/tc-lib-pdf` | 8.73.6 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-sign` | 2.0.3 | LGPL-3.0-or-later |
| `tecnickcom/tc-lib-pdf-parser` | 3.15.2 | LGPL-3.0-or-later |
| PHP | 8.5.10 (CI floor 8.4) | — |
| PHP OpenSSL | OpenSSL 3.6.3 | — |
| pyHanko | 0.37.0 | MIT |
| `pyhanko-cli` | 0.5.0 | MIT |
| `openssl` (fixture generation) | 3.6.4 | — |

The LGPL packages are unmodified Composer dependencies; obligations are recorded in
[`THIRD_PARTY_NOTICES.md`](../../THIRD_PARTY_NOTICES.md). Nothing was vendored or patched.

## 2. What level was reached, and how that was established

| Level | Reached | How |
|---|---|---|
| PAdES-BASELINE-B (B-B) | **yes** | `/SubFilter /ETSI.CAdES.detached`, detached CAdES CMS in the signature dictionary, SHA-256, RSA-3072 PKCS#1 v1.5, `/ByteRange` covering the whole file. pyHanko: *"The signature is cryptographically sound … The signature covers the entire file … judged VALID"* with the fixture root as the only trust anchor. |
| PAdES-BASELINE-T (B-T) | **yes** | B-B plus an RFC 3161 `id-aa-signatureTimeStampToken` obtained live from a public authority. pyHanko: *"This timestamp is backed by a time stamping authority. The timestamp token is cryptographically sound."* |
| B-LT / B-LTA | **not attempted** | The library supports both, but a self-issued certificate publishes no OCSP responder and no CRL distribution point, so a DSS would carry only the certificate bytes and a validator would still report B-T. Out of scope until the seal certificate is CA-issued. |

The claim rests on the produced bytes, not on the option that was requested. Two mechanisms
enforce that:

- `TcLibPdfSealer::seal()` re-reads its own output through `ArtifactValidator` and throws
  `SealFailedException` unless the artifact's own properties reach the requested level.
  A sealer cannot return bytes at a level nothing established.
- `scripts/validate-seal.sh` runs pyHanko, which shares no code with the PHP path, against
  every committed artifact and requires the exact outcome named in
  `tests/Fixtures/validation/manifest.tsv`.

### The two B-T artifacts, and why there are two

Timestamp trust and timestamp soundness are different claims, and the two public
authorities that were tried each demonstrate one of them:

| Endpoint | Transport | TSA certificate trust | pyHanko verdict |
|---|---|---|---|
| `http://timestamp.digicert.com` | plaintext HTTP only — it has no HTTPS listener | chains to DigiCert Trusted Root G4, in the ordinary trust store | **VALID** (`sealed-b-t.pdf`) |
| `https://freetsa.org/tsr` | HTTPS | *"No path to trust anchor found. The TSA certificate is untrusted."* | **INVALID**, on TSA trust alone (`sealed-b-t-untrusted-tsa.pdf`) |

Both artifacts are committed, and the second is deliberately listed in the manifest as
having to fail. It is the concrete form of the rule in `docs/HANDOFF.md` section 9: a
self-hosted or obscure service is not an independent witness merely because it speaks
RFC 3161.

**What "TSA trusted" would require.** A relying party has to be able to build a path from
the TSA certificate in the token to a root it already trusts, with that root asserting the
timestamping purpose. In practice that means one of:

1. a TSA whose root is already in the relying party's store — the DigiCert case above, and
   the only one that needs no configuration anywhere;
2. an explicit trust list, distributing the TSA root to every validator and viewer that
   must accept these artifacts — operationally the same problem as distributing a private
   seal root;
3. a qualified trust service under an EU trusted list, which brings its own contractual and
   supervisory arrangements and is not something an operator can self-assert.

None of these is a code change. The application validates the token and fails closed on a
bad one; it does not and cannot vouch for the authority.

`freetsa.org` additionally needs `ESIGN_TSA_ALLOW_SHA1_TOKEN=true`, because it still names
its certificate with the RFC 2634 `signing-certificate` (v1) attribute, which is SHA-1 by
definition. The relaxation applies to the token check only; the document signature is
SHA-256 regardless. DigiCert emits `signing-certificate-v2` and needs no relaxation.

## 3. Independent validation

`scripts/validate-seal.sh` (committed, and run by the `validation` CI job) validates every
artifact in `tests/Fixtures/validation/`. The recorded output is
`tests/Fixtures/validation/pyhanko-output.txt`.

Two trust modes, named per artifact in the manifest so nothing is inferred from a filename:

- `fixture-only` — `--trust-replace --trust tests/Fixtures/crypto/root.test.crt`. The OS
  trust store is discarded, so only the fixture root counts. This is the strict mode.
- `fixture-plus-system` — `--trust …root.test.crt` with the OS store kept, which is what
  anchors a public timestamp authority's certificate.

Result of the recorded run, all eight as required:

| Artifact | Required | pyHanko |
|---|---|---|
| `sealed-b-b.pdf` | valid | VALID |
| `sealed-b-t.pdf` | valid | VALID |
| `sealed-b-t-untrusted-tsa.pdf` | invalid | INVALID (TSA untrusted) |
| `negative-modified-content.pdf` | invalid | INVALID |
| `negative-truncated.pdf` | invalid | error: EOF marker not found |
| `negative-incremental-update.pdf` | invalid | INVALID |
| `negative-forged-cms.pdf` | invalid | INVALID |
| `negative-untrusted-signer.pdf` | invalid | INVALID (no path to trust anchor) |

### pyHanko's stated limitation

pyHanko's own documentation warns that its ordinary validation **is not a complete
structural PAdES-profile conformance check**. A VALID verdict above means: the CMS verifies
over the byte-ranged content, the signer's certificate chains to a configured anchor, and
the signature covers the file. It does *not* establish that the artifact satisfies every
clause of ETSI EN 319 142-1 V1.2.1 — for instance which attributes are required, forbidden,
or must be absent at each baseline level.

So the level claimed in section 2 is supported by a cryptographic and trust check plus the
library's documented profile implementation, **not** by a profile conformance verdict. That
gap is open; see section 6.

### DSS is out of scope here

Issue #7 also asks for European Commission DSS, which is the tool that would give a
profile-level verdict. It is deliberately **not** part of this change:

- DSS is a Java application. This machine has no container runtime and the repository's
  runtime rule is PHP-only, so adding a JVM to the `validation` job is a separate decision
  with its own maintenance cost.
- The artifacts are committed, synthetic, and small, so a DSS pass can be added later
  against exactly these bytes without resealing anything.

Until that lands, no claim in this document depends on a profile-level check.

## 4. Fail-closed behaviour, and the test for each

Every case throws a typed subclass of
`App\Domain\Evidence\Sealing\Exceptions\SealingException`. No case returns a lower level, an
unsigned passthrough, or partially sealed bytes.

| Condition | Exception | Test |
|---|---|---|
| No certificate or key configured | `SealMaterialUnavailableException` | `SealMaterialTest::test_it_rejects_an_unconfigured_deployment` |
| Configured path missing or unreadable | `SealMaterialUnavailableException` | `SealMaterialTest::test_it_rejects_a_configured_path_that_does_not_exist` |
| No key id configured | `SealMaterialUnavailableException` | `SealMaterialTest::test_it_rejects_material_without_a_key_id` |
| Certificate not readable PEM X.509 | `SealMaterialInvalidException` | `SealMaterialTest::test_it_rejects_an_unreadable_certificate` |
| Certificate expired (or not yet valid) | `SealMaterialInvalidException` | `SealMaterialTest::test_it_rejects_an_expired_certificate` |
| Key does not match the certificate | `SealMaterialInvalidException` | `SealMaterialTest::test_it_rejects_a_private_key_that_does_not_match_the_certificate` |
| Wrong private-key passphrase | `SealMaterialInvalidException` | `SealMaterialTest::test_it_rejects_a_wrong_passphrase` |
| Digest algorithm outside sha256/384/512 | `SealMaterialInvalidException` | `SealMaterialTest::test_it_rejects_an_unsupported_digest_algorithm` |
| B-T requested, no TSA configured | `TimestampAuthorityNotConfiguredException` | `TcLibPdfSealerTest::test_it_refuses_b_t_when_no_timestamp_authority_is_configured` |
| B-T requested, TSA URL fails the destination policy | `TimestampAuthorityDestinationException` | `TcLibPdfSealerTest::test_it_refuses_b_t_when_the_configured_authority_fails_the_destination_policy`, `HttpTimestampAuthorityTest` (10 cases) |
| TSA unreachable or answering with a non-DER body | `TimestampAuthorityUnreachableException` | `PadesTimestampTest::test_an_unreachable_authority_fails_closed_rather_than_downgrading` |
| TSA token refused (imprint, nonce, policy, genTime, TSA signature, EKU) | `TimestampTokenRejectedException` | mapped in `TcLibPdfSealer::translate()`; the checks themselves are tc-lib-pdf-sign's and are covered by its own suite |
| Empty or non-PDF input | `SealFailedException` | `TcLibPdfSealerTest::test_it_refuses_an_empty_input`, `…_an_input_that_is_not_a_pdf` |
| Artifact read back below the requested level | `SealFailedException` | `TcLibPdfSealerTest::test_it_refuses_to_return_an_artifact_that_does_not_reach_the_requested_level` |

### Tamper detection on the produced artifact

`SealedArtifactTamperingTest` starts from a genuinely sealed PDF and breaks exactly one
thing per case, so a pass cannot come from the file being unreadable for an unrelated
reason. The same artifacts go to pyHanko.

| Case | Detected by |
|---|---|
| Page geometry changed inside the signed range, same file length | CMS message digest |
| Tail truncated | `/ByteRange` over-claims the file, and the digest |
| Unexpected incremental revision appended | `/ByteRange` no longer covers the file |
| CMS of another sealed document spliced into `/Contents` | CMS message digest |
| Sealed with a key outside the trusted root | trust path, not integrity — the CMS verifies |

### SSRF policy on the TSA request

The TSA URL is operator configuration that a queue worker dereferences, so without a policy
it is an SSRF primitive. `HttpTimestampAuthority` refuses, before any packet leaves the
process: a scheme other than HTTP(S); plaintext HTTP unless `ESIGN_TSA_ALLOW_PLAINTEXT_HTTP`
is set; credentials in the URL; and any host where *any* A or AAAA answer is loopback,
RFC 1918, link-local, or otherwise reserved. It then pins the connection to the addresses it
validated (`CURLOPT_RESOLVE`), so a second DNS answer cannot move the target, and never
follows a redirect, because only the first hop was checked.

PHP's own `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` is not sufficient on its
own, which was worth measuring rather than assuming. It covers RFC 1918, loopback,
link-local, IPv6 unique-local, and IPv4-mapped IPv6, but it treats RFC 6598 carrier-grade
NAT (`100.64.0.0/10`) as public — the address space a shared host's internal network
typically sits in — along with RFC 6890 IETF protocol assignments (`192.0.0.0/24`) and
benchmarking space (`198.18.0.0/15`). Those three are refused explicitly, and
`HttpTimestampAuthorityTest` pins each, including its IPv4-mapped IPv6 form.

tc-lib-pdf's own transport validates the URL's *shape* only. The policy is applied by
overriding the library's documented `postTimestampRequest()` transport seam
(`SealingDocument`), so the destination check cannot be bypassed by using the library
directly.

## 5. Notable findings from the prototype

**tc-lib-pdf signs a document it lays out; there is no incremental-update signing of foreign
bytes.** The sealer therefore imports the input's pages as Form XObjects into a new document
and signs that. Rendered appearance is preserved; the low-level byte representation is not.
Consequences, both consistent with the retention rule in `AGENTS.md`:

- the retained original is never rewritten — the sealed artifact is a new object under a new
  key, and `TcLibPdfSealerTest::test_it_leaves_the_input_bytes_untouched` pins that;
- an already-signed input cannot be counter-sealed while preserving its existing signature.
  The library's own import documentation states that signatures in a source file are not
  preserved as valid signatures in the output. Imported executed PDFs must therefore stay
  byte-preserved and unsealed, which is what the migration rule already requires.

**The engine's file allowlist is empty by default.** `tc-lib-file` rejects every local path
until one is allowlisted, so passing `file:///path/to/key.pem` to the signature
configuration fails with *"Unable to read the extra certificates file"*. Key material is
therefore read by `SealMaterial` and passed in as PEM strings. This is the better
arrangement anyway: reading, validating, and decrypting the key stays in application code,
the passphrase never travels further into the pipeline, and the engine gets no filesystem
access.

**Core-font metrics are not shipped.** tc-lib-pdf resolves even the standard-14 fonts
through a generated `<font>.json` metrics file, and the Composer dist package contains none
— they are a `make fonts` build artifact requiring the library's own `util/` toolchain.
Sealing does not depend on page content, so the synthetic fixtures are vector graphics with
no text. **This blocks nothing in Stage 0 but must be resolved before any text is rendered
into a PDF** (assembly, signature appearance, completion report). It is a packaging question
for the Preparation module, not a sealing one.

**A validator's document-modification analysis is not a substitute for the byte-range rule.**
An earlier version of the incremental-update fixture appended a revision containing one
unreferenced object. pyHanko classed it as *"All modifications relate to signature
maintenance … compatible with the current document modification policy"* and judged the
signature **VALID**, even while reporting that the signature did not cover the entire file.
Only after the fixture was changed to redefine the page object did pyHanko judge it invalid.
The application's own rule — an artifact whose `/ByteRange` does not cover the whole file is
refused, full stop — is therefore stricter than the validator's default judgment, and is
what the publication gate uses.

**A trailing zero byte in a signature is not padding.** The reserved `/Contents` field is
fixed-size and zero-padded, and trimming trailing `0` characters to find the CMS corrupts
any signature whose last byte is `0x00`. That produced an intermittent failure in roughly
one seal in a few hundred. `TcLibPdfArtifactValidator` now takes the CMS by its own DER
length instead. Recorded because the mistake is easy to repeat and its symptom looks like
flaky crypto.

## 6. Open gaps

1. **No profile-level conformance check.** pyHanko establishes soundness and trust, not
   ETSI EN 319 142-1 conformance. A DSS (Java) pass over the committed artifacts, plus an
   explicit normative requirement matrix, is still needed before the B-B/B-T claim is
   backed by a profile verdict. Nothing in this document depends on such a verdict today.
2. **B-LT and B-LTA are unbuilt.** The library supports them, but they need a CA-issued seal
   certificate with a reachable OCSP responder or CRL distribution point, plus ongoing
   preservation operations (periodic archive timestamps, revocation refresh). They are not
   booleans to flip, and must not be presented as such.
3. **Key custody is unaddressed.** Stage 0 uses a committed throwaway fixture key. Real
   operation needs: material outside the repository, image layers, and document storage;
   mounted only into the worker role in Docker; expiry monitoring against
   `SealMaterial::$notAfter`; a rotation procedure that preserves verification of old
   artifacts by key id; and a compromise response. A single-account shared host cannot
   isolate the key from application PHP, and the deployment documentation has to say so.
4. **No CA-issued certificate.** Per
   [`ADR 0003`](../adr/0003-seal-material-and-timestamp-authority.md) the first release
   seals with a self-issued certificate. Artifacts are cryptographically verifiable, and
   viewers will not show a trusted seal until an operator installs a CA-issued one. This is
   a documented product limitation, not a defect.
5. **TSA choice is an unresolved trade-off.** No public authority tried offers both HTTPS
   and a widely trusted root. The current default is HTTPS-only, which means an operator
   wanting a trusted timestamp must opt into plaintext HTTP for the timestamp request. The
   token is signed and nonce-matched either way; what leaks over plaintext is the document
   digest.
6. **Signing time is not evidence of time.** The `/M` entry and the CMS signing time are the
   sealing host's clock. Only the RFC 3161 token attests a time independently, and only as
   far as the authority is trusted. Signer acceptance time, sealing time, and timestamp time
   remain three distinct facts in the evidence model.
7. **No revocation checking in validation.** `scripts/validate-seal.sh` passes
   `--no-revocation-check`, because a self-issued fixture chain publishes no responder. Once
   the chain is CA-issued, that flag must go.

## 7. Reproducing this

```bash
# Regenerate the synthetic key material (throwaway; see tests/Fixtures/crypto/README.md)
tests/Fixtures/crypto/generate.sh

# Backend gate, including every fail-closed and tamper case
./vendor/bin/pint --test
composer test

# Reseal the artifacts and validate them independently
scripts/validate-seal.sh --regenerate
```

The `validation` CI job runs the last command on every change under the backend or docker
filters. When no timestamp authority answers, the B-T artifacts are skipped, the run emits a
`::warning::` and a job-summary note, and the B-B result stands alone — a green run with no
B-T evidence never looks like a B-T pass.
