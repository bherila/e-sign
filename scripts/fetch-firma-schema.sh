#!/usr/bin/env bash
#
# Re-download the pinned Firma OpenAPI document and verify it against the digest
# recorded in tests/Fixtures/firma/SCHEMA.md.
#
# The schema is NOT vendored: no redistribution licence is published for it
# (see tests/Fixtures/firma/SCHEMA.md, "Licence and redistribution"). This script
# is the reproducible substitute — a fresh clone runs it to obtain the exact bytes
# that the compatibility matrix was written against.
#
# Usage:
#   scripts/fetch-firma-schema.sh                 # fetch to the default output path and verify
#   scripts/fetch-firma-schema.sh out.json        # fetch to out.json and verify
#   FIRMA_SCHEMA_VERSION=v01.34.00 scripts/fetch-firma-schema.sh   # a different pinned version
#
# Exit codes: 0 verified, 1 usage/tooling error, 2 download failed, 3 digest mismatch.

set -euo pipefail

FIRMA_SCHEMA_VERSION="${FIRMA_SCHEMA_VERSION:-v01.35.00}"
FIRMA_SCHEMA_URL="${FIRMA_SCHEMA_URL:-https://docs.firma.dev/api-reference/${FIRMA_SCHEMA_VERSION}/openapi-${FIRMA_SCHEMA_VERSION}.json}"

# SHA-256 of the bytes served for v01.35.00, retrieved 2026-09-08T06:52:14Z.
# Keep this in sync with tests/Fixtures/firma/SCHEMA.md.
FIRMA_SCHEMA_SHA256="${FIRMA_SCHEMA_SHA256:-91c7128a35ae52ac3937715041e41c1bee119cfb69b3dcf6fdc8135a4e3c98b9}"

output="${1:-${TMPDIR:-/tmp}/firma-openapi-${FIRMA_SCHEMA_VERSION}.json}"

command -v curl >/dev/null 2>&1 || { echo "fetch-firma-schema: curl is required" >&2; exit 1; }

if command -v sha256sum >/dev/null 2>&1; then
    digest_of() { sha256sum "$1" | cut -d' ' -f1; }
elif command -v shasum >/dev/null 2>&1; then
    digest_of() { shasum -a 256 "$1" | cut -d' ' -f1; }
else
    echo "fetch-firma-schema: neither sha256sum nor shasum is available" >&2
    exit 1
fi

echo "fetch-firma-schema: GET ${FIRMA_SCHEMA_URL}"
if ! curl --fail --silent --show-error --location --max-time 120 "${FIRMA_SCHEMA_URL}" --output "${output}"; then
    echo "fetch-firma-schema: download failed" >&2
    exit 2
fi

actual="$(digest_of "${output}")"
if [ "${actual}" != "${FIRMA_SCHEMA_SHA256}" ]; then
    cat >&2 <<MSG
fetch-firma-schema: DIGEST MISMATCH
  url      ${FIRMA_SCHEMA_URL}
  file     ${output}
  expected ${FIRMA_SCHEMA_SHA256}
  actual   ${actual}

Upstream re-published this version, or the pin is stale. Do not silently update the
digest: diff the new document against the capability matrix in
docs/compatibility/firma-capability-matrix.md first, then update both together.
MSG
    exit 3
fi

echo "fetch-firma-schema: verified ${output} (sha256 ${actual})"
