#!/usr/bin/env bash
#
# Independently validate the Stage 0 sealing artifacts with pyHanko.
#
# pyHanko is a third-party PDF signature validator with no shared code with the
# PHP signing path, so it is the authoritative check on what the sealer actually
# produced. The in-process checks in tests/Feature/Evidence are a self-check;
# this script is the evidence.
#
# What it does NOT do: pyHanko's documentation is explicit that ordinary
# validation is not a complete structural PAdES-profile conformance check. A
# VALID verdict here means the signature is cryptographically sound and its
# certificate chains to a configured trust anchor. It does not establish that
# the artifact satisfies every clause of ETSI EN 319 142-1. A profile-level
# check needs the European Commission DSS tool, which is out of scope for this
# script (it is a Java runtime, and the repository's runtime is PHP only).
#
# Usage:
#   scripts/validate-seal.sh                     # validate committed artifacts
#   scripts/validate-seal.sh --regenerate        # reseal them first, then validate
#   PYHANKO_VENV=/tmp/pyhanko-venv scripts/validate-seal.sh
#
# Exit status: 0 when every artifact reached the outcome the manifest requires.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

validation_dir='tests/Fixtures/validation'
manifest="$validation_dir/manifest.tsv"
trust_root='tests/Fixtures/crypto/root.test.crt'
output_file="$validation_dir/pyhanko-output.txt"

venv="${PYHANKO_VENV:-/tmp/pyhanko-venv}"
pyhanko="$venv/bin/pyhanko"

regenerate=0
if [ "${1:-}" = '--regenerate' ]; then
    regenerate=1
fi

# --- pyHanko ----------------------------------------------------------------
# The CLI lives in its own distribution: `pip install pyhanko` alone installs
# the library and no `pyhanko` entry point.
if [ ! -x "$pyhanko" ]; then
    echo "Creating a pyHanko virtualenv at $venv"
    python3 -m venv "$venv"
    "$venv/bin/pip" install --quiet --upgrade pip
    "$venv/bin/pip" install --quiet pyhanko pyhanko-cli
fi

pyhanko_version="$("$venv/bin/pip" show pyHanko | awk '/^Version:/ {print $2}')"
pyhanko_cli_version="$("$venv/bin/pip" show pyhanko-cli | awk '/^Version:/ {print $2}')"

if [ "$regenerate" -eq 1 ]; then
    echo 'Regenerating the sealed artifacts'
    ESIGN_WRITE_VALIDATION_FIXTURES=1 php artisan test --filter=ValidationFixturesTest
fi

if [ ! -f "$manifest" ]; then
    echo "No manifest at $manifest. Run with --regenerate." >&2
    exit 1
fi

# --- validate ---------------------------------------------------------------
: > "$output_file"

log() {
    printf '%s\n' "$1" | tee -a "$output_file"
}

log "pyHanko $pyhanko_version (CLI $pyhanko_cli_version)"
log "openssl $(openssl version | cut -d' ' -f1-2)"
log "trust anchor: $trust_root"
log "validated at: $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
log ''

# A B-T artifact absent because no TSA answered is reported loudly, never
# passed over: a green run with no B-T evidence must not look like a B-T pass.
if [ -s "$validation_dir/b-t-skipped.txt" ]; then
    echo '::warning title=PAdES B-T not validated::No timestamp authority answered, so no B-T artifact was produced or checked on this run.'
    log 'WARNING  PAdES B-T artifacts were skipped on the run that produced these fixtures:'
    while IFS= read -r line; do
        log "  $line"
    done < "$validation_dir/b-t-skipped.txt"
    log ''

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            echo '### PAdES B-T not validated'
            echo
            echo 'No RFC 3161 timestamp authority answered, so the B-T artifacts were not'
            echo 'produced and not checked. The B-B result below stands on its own.'
        } >> "$GITHUB_STEP_SUMMARY"
    fi
fi

failures=0
checked=0

while IFS=$'\t' read -r file expectation trust note; do
    case "$file" in
        ''|'#'*) continue ;;
    esac

    path="$validation_dir/$file"
    if [ ! -f "$path" ]; then
        log "MISSING  $file — listed in the manifest but not present"
        failures=$((failures + 1))
        continue
    fi

    case "$trust" in
        fixture-only)
            # Only the fixture root is trusted. This is the strict mode: an
            # artifact whose signer or TSA chains anywhere else fails.
            trust_args=(--trust-replace --trust "$trust_root")
            ;;
        fixture-plus-system)
            # The fixture root is added to the OS trust store, which is what
            # anchors a public timestamp authority's certificate.
            trust_args=(--trust "$trust_root")
            ;;
        *)
            log "MISSING  $file — unknown trust mode '$trust'"
            failures=$((failures + 1))
            continue
            ;;
    esac

    set +e
    detail="$("$pyhanko" --no-plugins sign validate \
        --pretty-print \
        --no-revocation-check \
        "${trust_args[@]}" \
        "$path" 2>&1)"
    status=$?
    set -e

    checked=$((checked + 1))

    if [ "$expectation" = 'valid' ]; then
        expected_status=0
    else
        expected_status=1
    fi

    if [ "$status" -eq "$expected_status" ] \
        || { [ "$expectation" = 'invalid' ] && [ "$status" -ne 0 ]; }; then
        verdict='OK      '
    else
        verdict='FAIL    '
        failures=$((failures + 1))
    fi

    log "$verdict $file — expected $expectation, pyHanko exit $status ($trust)"
    log "         $note"
    log '--- pyHanko output ---'
    printf '%s\n' "$detail" | sed 's/^/         /' >> "$output_file"
    log ''
done < "$manifest"

log "Checked $checked artifact(s); $failures did not reach the required outcome."

if [ "$failures" -ne 0 ]; then
    echo "Validation failed. Full output in $output_file" >&2
    exit 1
fi

echo "Validation passed. Full output in $output_file"
