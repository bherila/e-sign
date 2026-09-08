# Synthetic sealing fixtures

**Everything in this directory is a throwaway test fixture. None of it is, or may ever
become, operational key material.** The private keys are committed on purpose and are
public from the moment they land in this repository. Every subject says so:

```
O=BWH eSign TEST FIXTURES, CN=BWH eSign TEST SEAL - NOT FOR USE
```

Regenerate with [`generate.sh`](generate.sh), which needs nothing but `openssl`:

```bash
tests/Fixtures/crypto/generate.sh
```

## Why `.test.crt` / `.test.pkey` and not `.pem` / `.key`

`.gitignore` ignores `*.pem`, `*.key`, `*.p12`, and `*.pfx` so that a stray copy of a
real key cannot be staged. Naming the fixtures `*.test.crt` / `*.test.pkey` keeps that
rule intact and needs no `git add --force`, which would have to be repeated — and could
be repeated on the wrong file — every time a fixture is regenerated. The hygiene rule
stays absolute; the fixtures simply live outside its namespace.

## Contents

| File | Role |
|---|---|
| `root.test.crt` / `root.test.pkey` | Fixture trust anchor. Independent validators are pointed at the certificate with `--trust`. |
| `seal.test.crt` / `seal.test.pkey` | The positive sealing certificate, issued by the fixture root. RSA 3072, `digitalSignature` + `nonRepudiation`, document-signing EKU. |
| `seal-encrypted.test.pkey` | `seal.test.pkey` as PKCS#8 encrypted with the passphrase in `generate.sh`, to exercise `ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE`. |
| `seal-expired.test.crt` / `seal-expired.test.pkey` | Valid 2020-01-01 to 2020-01-02. Proves sealing refuses expired material. |
| `untrusted.test.crt` / `untrusted.test.pkey` | Self-signed, outside the fixture root. An artifact sealed with it is cryptographically sound but chains to no configured trust anchor. |
| `wrong.test.pkey` | An unrelated key, for the "private key does not match the certificate" case. |
| `root-b.test.crt` / `root-b.test.pkey` | A second, unrelated trust anchor: the anchor a deployment moves to when it rotates. |
| `seal-b.test.crt` / `seal-b.test.pkey` | Key B, the rotation target, issued by `root-b.test.crt`. `seal.test.crt` is key A, the material that gets retired. |

### Why key B has its own root

A rotation drill has to show that each artifact verifies against *its own* certificate.
If key A and key B shared `root.test.crt`, an independent validator would judge artifacts
sealed under either key valid against the same `--trust` file, and the drill would prove
only that both keys chain somewhere — not that the retired artifact is verified by the
retired material. Two anchors make the check discriminating: `scripts/validate-seal.sh`
validates the retired artifact under `root.test.crt` alone and the active one under
`root-b.test.crt` alone, and requires each to be judged INVALID under the other.

It also matches the deployment being described. The first release seals under a
self-issued certificate (ADR 0003), where a rotation necessarily changes the anchor.

## What these fixtures are not

They are not a demonstration that the seal is trusted. A self-issued certificate can
carry cryptographic integrity without a relying party trusting it; see
[`docs/adr/0003-seal-material-and-timestamp-authority.md`](../../../docs/adr/0003-seal-material-and-timestamp-authority.md)
and [`docs/stage0/sealing.md`](../../../docs/stage0/sealing.md).
