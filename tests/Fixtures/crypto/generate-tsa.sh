#!/usr/bin/env bash
#
# Regenerate the synthetic RFC 3161 timestamp-authority material used by
# tests/Support/FakeTimestampAuthority (issue #1, "B-T fails closed on a bad response").
#
# Separate from generate.sh on purpose: that script regenerates the sealing fixtures, and
# committed validation artifacts are signed with them. Rotating the TSA fixture must not
# rotate those.
#
# Throwaway test fixtures, committed on purpose (README.md). Every subject says so.
#
# Usage: tests/Fixtures/crypto/generate-tsa.sh
set -euo pipefail

cd "$(dirname "$0")"

SUBJECT_TSA='/O=BWH eSign TEST FIXTURES/CN=BWH eSign TEST TSA - NOT FOR USE'
SUBJECT_TSA_EXPIRED='/O=BWH eSign TEST FIXTURES/CN=BWH eSign TEST TSA EXPIRED - NOT FOR USE'

rm -rf tsa-ca
rm -f tsa.test.crt tsa.test.pkey tsa-expired.test.crt tsa-expired.test.pkey tsa-expired.test.csr

# --- A timestamping certificate (the positive fixture) -----------------------
# RFC 3161 section 2.3: the extended key usage is timeStamping only, and critical.
openssl req -x509 -new -nodes -sha256 -days 7300 \
  -newkey rsa:2048 \
  -keyout tsa.test.pkey \
  -out tsa.test.crt \
  -subj "$SUBJECT_TSA" \
  -addext 'basicConstraints=critical,CA:FALSE' \
  -addext 'keyUsage=critical,digitalSignature' \
  -addext 'extendedKeyUsage=critical,timeStamping' \
  -addext 'subjectKeyIdentifier=hash'

# --- An expired timestamping certificate -------------------------------------
# Valid 2020-01-01 to 2020-01-02, issued by the certificate above, so a token it signs
# today has a genTime its certificate does not cover. `openssl ca` rather than
# `-not_before/-not_after`, which need OpenSSL 3.4 (generate.sh says why).
openssl req -new -nodes -sha256 \
  -newkey rsa:2048 \
  -keyout tsa-expired.test.pkey \
  -out tsa-expired.test.csr \
  -subj "$SUBJECT_TSA_EXPIRED"

mkdir -p tsa-ca/newcerts
: > tsa-ca/index.txt
echo 1000 > tsa-ca/serial

cat > tsa-ca/ca.cnf <<'CNF'
[ca]
default_ca = fixture_tsa_ca

[fixture_tsa_ca]
dir               = ./tsa-ca
database          = $dir/index.txt
new_certs_dir     = $dir/newcerts
serial            = $dir/serial
certificate       = ./tsa.test.crt
private_key       = ./tsa.test.pkey
default_md        = sha256
policy            = fixture_policy
email_in_dn       = no
unique_subject    = no
copy_extensions   = none
x509_extensions   = fixture_tsa

[fixture_policy]
organizationName  = optional
commonName        = supplied

[fixture_tsa]
basicConstraints  = critical,CA:FALSE
keyUsage          = critical,digitalSignature
extendedKeyUsage  = critical,timeStamping
CNF

openssl ca -batch -config tsa-ca/ca.cnf -notext \
  -startdate 20200101000000Z -enddate 20200102000000Z \
  -in tsa-expired.test.csr -out tsa-expired.test.crt

rm -rf tsa-ca tsa-expired.test.csr
