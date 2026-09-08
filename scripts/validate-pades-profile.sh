#!/usr/bin/env bash
#
# Profile-level PAdES conformance check with European Commission DSS.
#
# This is the second, independent half of the seal evidence, and it exists because pyHanko's
# own documentation states that its ordinary validation is NOT a complete structural
# PAdES-profile conformance check. scripts/validate-seal.sh (pyHanko) establishes that the CMS
# verifies over the byte-ranged content, that the signer chains to a configured anchor, and
# that the signature covers the file. It does not establish which ETSI EN 319 142-1 baseline
# the artifact satisfies. DSS derives exactly that from the signature's structure and reports
# it as a SignatureLevel, so a signature that were merely a valid PKCS#7 rather than a
# conformant baseline signature would read PKCS7_B or PDF_NOT_ETSI instead of PAdES_BASELINE_B.
#
# Deliberately kept separate from scripts/validate-seal.sh and from the `validation` CI job:
# a DSS or JVM infrastructure problem must not be able to mask a pyHanko regression, or the
# reverse.
#
# What it does NOT do:
#   * It never reseals. It reads the committed bytes under tests/Fixtures/validation/ exactly
#     as they are, which is what docs/stage0/sealing.md promised a later DSS pass would do.
#     Regeneration stays with the pyHanko job, which owns the sealer's freshness.
#   * It trusts only the anchor the manifest names for that row, and nothing else — no OS
#     store, no EU trusted list, no online CRL or OCSP fetch. The run is fully offline after
#     the Maven dependencies are resolved, so its verdicts do not depend on the runner's
#     egress. An artifact may appear under more than one trust mode; that is how the seal-key
#     rotation rows show the two anchors actually discriminate.
#   * It says nothing about timestamp-authority trust; that is pyHanko's half of the evidence.
#
# Nothing here is part of the application. The repository's runtime is PHP only; this Java tool
# is CI-only, is excluded from the production image by .dockerignore, and is not in the release
# bundle's file list in scripts/build-release.sh. See the "CI-only tooling" section of
# THIRD_PARTY_NOTICES.md for its licences.
#
# Usage:
#   scripts/validate-pades-profile.sh              # build the tool if needed, then validate
#   scripts/validate-pades-profile.sh --rebuild    # force a clean rebuild of the tool first
#
# Environment:
#   MAVEN_ARGS   extra arguments for the Maven build (CI passes --batch-mode -o where useful)
#
# Exit status: 0 when every artifact reported exactly the level, conclusion and timestamp
# outcome the manifest requires.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

validation_dir='tests/Fixtures/validation'
manifest="$validation_dir/pades-profile-manifest.tsv"
tool_dir='tools/dss'
policy="$tool_dir/validation-policy.xml"
jar="$tool_dir/target/pades-profile-check.jar"
report_dir="$tool_dir/reports"
json_output="$validation_dir/dss-report.json"
output_file="$validation_dir/dss-output.txt"

rebuild=0
if [ "${1:-}" = '--rebuild' ]; then
    rebuild=1
fi

# Trust modes, named per row in the manifest so nothing is inferred from a filename. The names
# match scripts/validate-seal.sh's, minus fixture-plus-system: this check is never given an OS
# trust store, so it makes no claim about a public timestamp authority.
trust_fixture_only='tests/Fixtures/crypto/root.test.crt'
trust_rotation_target_only='tests/Fixtures/crypto/root-b.test.crt'

trust_anchor_for() {
    case "$1" in
        fixture-only) printf '%s' "$trust_fixture_only" ;;
        rotation-target-only) printf '%s' "$trust_rotation_target_only" ;;
        *) return 1 ;;
    esac
}

for required in "$manifest" "$trust_fixture_only" "$trust_rotation_target_only" "$policy" "$tool_dir/pom.xml"; do
    if [ ! -f "$required" ]; then
        echo "error: $required is missing." >&2
        exit 1
    fi
done

# --- resolve the tool --------------------------------------------------------
# Java and Maven are provided by the CI job (actions/setup-java, which also caches ~/.m2).
# Locally, install a JDK 17+ and Maven; there is no vendored wrapper, because a checked-in
# wrapper jar is one more binary in a repository whose whole point is auditable artifacts.
if ! command -v java >/dev/null 2>&1; then
    echo 'error: no `java` on PATH. This check needs a JDK 17 or newer.' >&2
    echo '       CI provides one via actions/setup-java; locally install any JDK 17+.' >&2
    exit 1
fi

if ! command -v mvn >/dev/null 2>&1; then
    echo 'error: no `mvn` on PATH. The DSS jars are resolved from Maven Central.' >&2
    exit 1
fi

if [ "$rebuild" -eq 1 ] || [ ! -f "$jar" ]; then
    echo "Building $jar"
    # shellcheck disable=SC2086 # MAVEN_ARGS is a deliberate word-split argument list.
    (cd "$tool_dir" && mvn ${MAVEN_ARGS:-} -q package)
fi

if [ ! -f "$jar" ]; then
    echo "error: the Maven build did not produce $jar." >&2
    exit 1
fi

# --- run DSS ----------------------------------------------------------------
rm -rf "$report_dir"
mkdir -p "$report_dir"

# The artifact list comes from the manifest, not from a glob, so a fixture that exists on disk
# but is not accounted for cannot ride along unchecked, and one that is listed but absent is a
# hard failure rather than a silently shorter run. Rows are bucketed by trust mode, because DSS
# takes one trust configuration per run and an artifact may be listed under two of them.
modes=()
while IFS=$'\t' read -r file trust level conclusion timestamps note; do
    case "$file" in
        ''|'#'*) continue ;;
    esac
    if [ -z "$trust" ] || [ -z "$level" ] || [ -z "$conclusion" ] || [ -z "$timestamps" ]; then
        echo "error: malformed manifest row for '$file' (expected 6 tab-separated columns)." >&2
        exit 1
    fi
    if ! trust_anchor_for "$trust" >/dev/null; then
        echo "error: unknown trust mode '$trust' for '$file' in $manifest." >&2
        exit 1
    fi
    if [ ! -f "$validation_dir/$file" ]; then
        echo "error: $validation_dir/$file is listed in $manifest but not present." >&2
        echo '       Regenerate the fixtures with the pyHanko job, or fix the manifest.' >&2
        exit 1
    fi
    case " ${modes[*]-} " in
        *" $trust "*) ;;
        *) modes+=("$trust") ;;
    esac
done < "$manifest"

if [ "${#modes[@]}" -eq 0 ]; then
    echo "error: $manifest lists no artifacts." >&2
    exit 1
fi

summary_dir="$(mktemp -d)"
trap 'rm -rf "$summary_dir"' EXIT

# One DSS run per trust mode. Reports go to a per-mode subdirectory because the same artifact
# is validated twice under different anchors and the two XML reports must not overwrite
# each other.
for mode in "${modes[@]}"; do
    mode_artifacts=()
    while IFS=$'\t' read -r file trust level conclusion timestamps note; do
        case "$file" in
            ''|'#'*) continue ;;
        esac
        [ "$trust" = "$mode" ] || continue
        case " ${mode_artifacts[*]-} " in
            *" $validation_dir/$file "*) ;;
            *) mode_artifacts+=("$validation_dir/$file") ;;
        esac
    done < "$manifest"

    mkdir -p "$report_dir/$mode"
    java -jar "$jar" \
        --trust "$(trust_anchor_for "$mode")" \
        --policy "$policy" \
        --json "$report_dir/$mode.json" \
        --report-dir "$report_dir/$mode" \
        "${mode_artifacts[@]}" > "$summary_dir/$mode.tsv"
done

# One committed JSON report covering every run, so a reader does not have to reassemble it
# from the CI artifact.
{
    printf '{\n  "runs": [\n'
    first=1
    for mode in "${modes[@]}"; do
        [ "$first" -eq 1 ] || printf ',\n'
        first=0
        printf '  { "trustMode": "%s", "report":\n' "$mode"
        cat "$report_dir/$mode.json"
        printf '  }'
    done
    printf '\n  ]\n}\n'
} > "$json_output"

# --- compare against the manifest -------------------------------------------
: > "$output_file"

log() {
    printf '%s\n' "$1" | tee -a "$output_file"
}

dss_version="$(sed -n 's/.*"dssVersion": "\([^"]*\)".*/\1/p' "$json_output" | head -1)"
java_version="$(sed -n 's/.*"java": "\([^"]*\)".*/\1/p' "$json_output" | head -1)"

log "European Commission DSS $dss_version (dss-pades / dss-validation), Java $java_version"
log "policy: $policy (DSS stock policy with two marked edits; see the file header)"
for mode in "${modes[@]}"; do
    log "trust mode $mode: $(trust_anchor_for "$mode") — the only anchor, no OS store and no trusted list"
done
log "artifacts: the committed bytes under $validation_dir, not resealed"
log "validated at: $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
log ''

failures=0
checked=0

while IFS=$'\t' read -r file trust want_level want_conclusion want_timestamps note; do
    case "$file" in
        ''|'#'*) continue ;;
    esac

    line="$(awk -F'\t' -v f="$file" '$1 == f { print; exit }' "$summary_dir/$trust.tsv")"
    if [ -z "$line" ]; then
        log "FAIL     $file ($trust) — DSS produced no result line for this artifact"
        failures=$((failures + 1))
        continue
    fi

    got_level="$(printf '%s' "$line" | cut -f2)"
    got_conclusion="$(printf '%s' "$line" | cut -f3)"
    got_timestamps="$(printf '%s' "$line" | cut -f4)"
    checked=$((checked + 1))

    mismatch=''
    [ "$got_level" = "$want_level" ] || mismatch="$mismatch level(want $want_level, got $got_level)"
    [ "$got_conclusion" = "$want_conclusion" ] || mismatch="$mismatch conclusion(want $want_conclusion, got $got_conclusion)"
    [ "$got_timestamps" = "$want_timestamps" ] || mismatch="$mismatch timestamps(want $want_timestamps, got $got_timestamps)"

    if [ -z "$mismatch" ]; then
        log "OK       $file ($trust) — $got_level, $got_conclusion, timestamps: $got_timestamps"
    else
        log "FAIL     $file ($trust) —$mismatch"
        failures=$((failures + 1))
    fi
    log "         $note"
    log ''
done < "$manifest"

# Every message DSS raised is copied into the recorded output, including on artifacts it
# accepted. A warning on a passing artifact is a finding, not noise, and
# docs/stage0/pades-profile.md lists them; suppressing them here would make that document
# unverifiable. The full structure — PDF revision facts, per-timestamp conclusions — stays in
# the JSON report beside this file.
log '--- messages DSS raised, per artifact (warnings on passing artifacts included) ---'
awk '
    /^  \{ "trustMode": / { sub(/, "report":$/, ""); sub(/^ +\{ "trustMode": /, ""); print ""; print "TRUST MODE " $0; next }
    /^      "file": / { sub(/,$/, ""); sub(/^ +"file": /, ""); print ""; print "  FILE " $0; next }
    /^          "(errors|warnings|info)": / { sub(/,$/, ""); sub(/^ +/, "    "); print; next }
    /^      "error": / { sub(/,$/, ""); sub(/^ +/, "    "); print; next }
' "$json_output" | tee -a "$output_file"
log ''

log "Checked $checked manifest row(s) across ${#modes[@]} trust mode(s); $failures did not report what the manifest requires."

if [ "$failures" -ne 0 ]; then
    echo "PAdES profile check failed. Full output in $output_file, JSON in $json_output," >&2
    echo "DSS's own XML reports in $report_dir." >&2
    exit 1
fi

echo "PAdES profile check passed ($checked rows, ${#modes[@]} trust modes). Output in $output_file, JSON in $json_output."
