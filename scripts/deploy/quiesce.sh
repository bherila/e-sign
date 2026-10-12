#!/usr/bin/env bash
#
# Wait for every already-running eSign Artisan invocation before shared storage
# is moved into the atomic deployment area. The shared action has already
# paused eSign's tagged cron lines and put the selected release into maintenance
# mode. The bounded queue worker (esign:queue:work-bounded, started by cron every
# minute) and any manual command must finish here instead of racing a storage move.
#
#   quiesce.sh <candidate-path> <php> <stable-path>
set -euo pipefail

candidate_path=$1
php=$2
stable_path=$3

# The arguments are part of the shared action's host-hook contract. Validate
# them before inspecting processes so a malformed invocation cannot silently
# claim quiescence.
case $candidate_path in
    .deployments/e-sign-laravel/releases/*) ;;
    *) echo "::error::Unexpected eSign candidate path: $candidate_path" >&2; exit 2 ;;
esac
test -x "$php"
test "$stable_path" = e-sign-laravel

stable_root=$(readlink -f "$HOME/$stable_path")
test -n "$stable_root" -a -d "$stable_root"

uid=$(id -u)
proc_root=${DEPLOY_QUIESCE_PROC_ROOT:-/proc}
expected_uid=${DEPLOY_QUIESCE_UID:-$uid}
timeout=${DEPLOY_QUIESCE_TIMEOUT_SECONDS:-420}
interval=${DEPLOY_QUIESCE_INTERVAL_SECONDS:-5}
[[ $timeout =~ ^[0-9]+$ ]] || { echo "::error::Quiescence timeout must be numeric." >&2; exit 2; }
[[ $interval =~ ^[1-9][0-9]*$ ]] || { echo "::error::Quiescence interval must be positive." >&2; exit 2; }
test -d "$proc_root" || { echo "::error::Process filesystem is unavailable." >&2; exit 1; }
deadline=$((SECONDS + timeout))

while :; do
    running=()

    for process in "$proc_root"/[0-9]*; do
        test -r "$process/status" -a -r "$process/cmdline" || continue
        process_uid=$(awk '/^Uid:/ { print $2; exit }' "$process/status" 2>/dev/null || true)
        test "$process_uid" = "$expected_uid" || continue

        executable=$(readlink -f -- "$process/exe" 2>/dev/null || true)
        case ${executable##*/} in
            php | php-cgi | lsphp | ea-php*) ;;
            *) continue ;;
        esac

        mapfile -d '' -t arguments <"$process/cmdline" || true
        artisan_argument=
        for argument in "${arguments[@]}"; do
            case $argument in
                artisan | */artisan) artisan_argument=$argument; break ;;
            esac
        done
        test -n "$artisan_argument" || continue

        working_directory=$(readlink -f "$process/cwd" 2>/dev/null || true)
        artisan_path=
        case $artisan_argument in
            /*) artisan_path=$(readlink -f -- "$artisan_argument" 2>/dev/null || true) ;;
            *)
                test -n "$working_directory" \
                    && artisan_path=$(readlink -f -- "$working_directory/$artisan_argument" 2>/dev/null || true)
                ;;
        esac
        case "$working_directory/|$artisan_path" in
            "$stable_root/"*\|* | *\|"$stable_root/artisan") running+=("${process##*/}") ;;
        esac
    done

    if [ "${#running[@]}" -eq 0 ]; then
        echo "eSign Artisan processes are quiescent."
        exit 0
    fi

    if [ "$SECONDS" -ge "$deadline" ]; then
        echo "::error::Timed out waiting for eSign Artisan process(es): ${running[*]}" >&2
        exit 1
    fi

    echo "Waiting for ${#running[@]} eSign Artisan process(es) to finish."
    sleep "$interval"
done
