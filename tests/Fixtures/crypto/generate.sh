#!/usr/bin/env bash
#
# Regenerate the synthetic sealing key material used by the Stage 0 sealing tests.
#
# Everything this script writes is a throwaway test fixture and is committed to the
# repository on purpose (see README.md in this directory). Nothing here is, or ever
# becomes, operational key material: every subject says so, and the private keys are
# public the moment they are committed.
#
# Usage: tests/Fixtures/crypto/generate.sh
#
# The extensions are ".test.crt" / ".test.pkey" rather than ".pem" / ".key" so the
# repository-wide secret-hygiene ignore rules (*.pem, *.key, *.p12, *.pfx in
# .gitignore) keep working unchanged and no force-add is needed. A stray copy of a
# real key still cannot be staged.
set -euo pipefail

cd "$(dirname "$0")"

# Committed so the fixtures are reproducible; it protects nothing.
TEST_PASSPHRASE='esign-fixture-passphrase-not-a-secret'

SUBJECT_ROOT='/O=BWH eSign TEST FIXTURES/CN=BWH eSign TEST SEAL ROOT - NOT FOR USE'
SUBJECT_SEAL='/O=BWH eSign TEST FIXTURES/CN=BWH eSign TEST SEAL - NOT FOR USE'
SUBJECT_EXPIRED='/O=BWH eSign TEST FIXTURES/CN=BWH eSign TEST SEAL EXPIRED - NOT FOR USE'
SUBJECT_UNTRUSTED='/O=BWH eSign TEST FIXTURES/CN=BWH eSign TEST SEAL UNTRUSTED - NOT FOR USE'

rm -rf ca
rm -f ./*.test.crt ./*.test.pkey ./*.test.csr ./*.srl seal.ext

# --- Trust anchor -----------------------------------------------------------
# pyHanko is pointed at this certificate with --trust so a chain can be built
# without touching the OS trust store.
openssl req -x509 -new -nodes -sha256 -days 7300 \
  -newkey rsa:3072 \
  -keyout root.test.pkey \
  -out root.test.crt \
  -subj "$SUBJECT_ROOT" \
  -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -addext 'subjectKeyIdentifier=hash'

# --- Sealing certificate (the positive fixture) -----------------------------
# keyUsage carries nonRepudiation/digitalSignature and the EKU is document
# signing, which is what a PAdES validator expects of a signing certificate.
openssl req -new -nodes -sha256 \
  -newkey rsa:3072 \
  -keyout seal.test.pkey \
  -out seal.test.csr \
  -subj "$SUBJECT_SEAL"

cat > seal.ext <<'EXT'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,nonRepudiation
extendedKeyUsage=emailProtection,1.2.840.113583.1.1.5
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
EXT

openssl x509 -req -in seal.test.csr -CA root.test.crt -CAkey root.test.pkey \
  -CAcreateserial -days 7300 -sha256 -extfile seal.ext -out seal.test.crt

# The same key, PKCS#8 encrypted, to exercise ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE.
openssl pkcs8 -topk8 -in seal.test.pkey -out seal-encrypted.test.pkey \
  -passout "pass:$TEST_PASSPHRASE"

# --- Expired sealing certificate (fail-closed fixture) ----------------------
# Backdated so it is already expired at any plausible run time. `openssl ca` is
# used rather than `openssl x509 -req -not_before/-not_after`, because those two
# flags need OpenSSL 3.4 and the CI runner ships 3.0.
openssl req -new -nodes -sha256 \
  -newkey rsa:2048 \
  -keyout seal-expired.test.pkey \
  -out seal-expired.test.csr \
  -subj "$SUBJECT_EXPIRED"

mkdir -p ca/newcerts
: > ca/index.txt
echo 1000 > ca/serial

cat > ca/ca.cnf <<'CNF'
[ca]
default_ca = fixture_ca

[fixture_ca]
dir               = ./ca
database          = $dir/index.txt
new_certs_dir     = $dir/newcerts
serial            = $dir/serial
certificate       = ./root.test.crt
private_key       = ./root.test.pkey
default_md        = sha256
policy            = fixture_policy
email_in_dn       = no
rand_serial       = no
unique_subject    = no
copy_extensions   = none
x509_extensions   = fixture_leaf

[fixture_policy]
organizationName  = optional
commonName        = supplied

[fixture_leaf]
basicConstraints       = critical,CA:FALSE
keyUsage               = critical,digitalSignature,nonRepudiation
extendedKeyUsage       = emailProtection,1.2.840.113583.1.1.5
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid,issuer
CNF

openssl ca -batch -config ca/ca.cnf -notext \
  -startdate 20200101000000Z -enddate 20200102000000Z \
  -in seal-expired.test.csr -out seal-expired.test.crt

rm -rf ca

# --- Untrusted sealing certificate (wrong-key artifact fixture) -------------
# Self-signed and outside the fixture root, so an artifact sealed with it is
# cryptographically sound but chains to nothing a validator was told to trust.
openssl req -x509 -new -nodes -sha256 -days 7300 \
  -newkey rsa:3072 \
  -keyout untrusted.test.pkey \
  -out untrusted.test.crt \
  -subj "$SUBJECT_UNTRUSTED" \
  -addext 'basicConstraints=critical,CA:FALSE' \
  -addext 'keyUsage=critical,digitalSignature,nonRepudiation' \
  -addext 'extendedKeyUsage=emailProtection,1.2.840.113583.1.1.5' \
  -addext 'subjectKeyIdentifier=hash'

# --- Unrelated key (wrong-key fixture) --------------------------------------
# A key that does not belong to seal.test.crt, for the mismatched-material test.
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out wrong.test.pkey

rm -f ./*.test.csr ./*.srl seal.ext
rm -rf ca

echo 'Regenerated synthetic sealing fixtures:'
ls -1 ./*.test.crt ./*.test.pkey
