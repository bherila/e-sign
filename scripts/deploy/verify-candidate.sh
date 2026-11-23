#!/usr/bin/env bash
#
# Candidate validation run by the shared cPanel deployment action on the host, after the
# candidate's migrations have run and before it is selected (`post-deploy-script`). A failure
# here leaves the previous release selected.
#
#   verify-candidate.sh <candidate-path> <php> <stable-path>
#
# Provider identity cutover (docs/operations/provider-identity.md). Once the cutover is
# recorded, enforcement stays on whatever BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED says. A
# candidate whose configuration disagrees (the setting off, or the identity provider client
# incomplete) would answer every protected request and every sign-in with a 503, so it is
# stopped before selection instead. Passes while no cutover is recorded, which is every
# installation until an operator runs `record`.
set -euo pipefail

if [ "$#" -ne 3 ]; then
    echo "usage: verify-candidate.sh <candidate-path> <php> <stable-path>" >&2
    exit 2
fi

candidate_path=$1
php=$2
stable_path=$3

# The arguments are part of the shared action's host-hook contract (see quiesce.sh).
case $candidate_path in
    .deployments/e-sign-laravel/releases/*) ;;
    *) echo "::error::Unexpected eSign candidate path: $candidate_path" >&2; exit 2 ;;
esac
test -x "$php"
test "$stable_path" = e-sign-laravel

candidate_root=$(readlink -f "$HOME/$candidate_path")
test -n "$candidate_root" -a -f "$candidate_root/artisan"

cd "$candidate_root"
"$php" -d memory_limit=1G artisan bherila-auth:provider-identity-cutover check --no-ansi || {
    echo "::error::The candidate's configuration disagrees with the recorded provider identity cutover (docs/operations/provider-identity.md)." >&2
    exit 1
}
echo "Provider identity cutover check passed."
