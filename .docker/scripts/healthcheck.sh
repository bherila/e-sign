#!/bin/sh
# HEALTHCHECK entrypoint for the BWH eSign image. The same image runs three roles (see
# .docker/scripts/entrypoint.sh) and only `web` has nginx to probe over HTTP; `worker` and
# `scheduler` are checked by process liveness instead. The entrypoint writes the active role to
# /tmp/esign-role before exec'ing it, so this script never has to guess.
set -eu

ROLE="unknown"
[ -r /tmp/esign-role ] && ROLE="$(cat /tmp/esign-role)"

case "$ROLE" in
    web)
        exec curl -fsS -o /dev/null http://127.0.0.1:8080/up
        ;;
    worker)
        exec pgrep -f 'queue:work' >/dev/null
        ;;
    scheduler)
        # The scheduler role is a shell loop (`while true; do artisan schedule:run; sleep 60;
        # done`); its own argv contains "schedule:run" for the whole lifetime of the loop, not
        # just while artisan is actually running, so this matches even mid-sleep.
        exec pgrep -f 'schedule:run' >/dev/null
        ;;
    artisan|*)
        # One-shot commands (release steps, ad-hoc `artisan ...` invocations) have no steady
        # state to probe; treat "process is still PID 1" (implied by getting this far) as healthy.
        exit 0
        ;;
esac
