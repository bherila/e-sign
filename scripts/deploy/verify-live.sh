#!/usr/bin/env bash
#
# Runner-side verification run by the shared cPanel deployment action after it has selected the
# release, brought the application up, installed the cron lines and proven the site healthy.
# It replaces the two post-deploy workflow steps of the in-place deploy.
#
#   1. esign:doctor, the install check (docs/operations/cpanel.md). Its scheduler check reads a
#      heartbeat the scheduler writes every minute, which a brand-new account has not written yet,
#      so a failure is retried for two minutes after the cron lines are installed. Output is shown
#      only on failure: this log is public, and a passing run has nothing to act on.
#   2. document_isolation as served by the web handler. Uploads are read in web requests, so
#      process isolation has to be confirmed where they run: /health/ready is served by the web
#      handler (docs/adr/0006). It is asked from the server, with the token read from the cached
#      configuration, so the token never leaves the host. Only the document_isolation probe
#      decides here; an unrelated backlog warning must not fail a deploy.
set -euo pipefail

: "${DEPLOY_SSH_TARGET:?}"
: "${DEPLOY_PHP_BINARY:?}"
: "${DEPLOY_STABLE_DIR:?}"
: "${DEPLOY_SITE_URL:?}"
: "${DEPLOY_SOURCE_COMMIT:?}"
: "${DEPLOY_LIVE_COMMIT:?}"
: "${DEPLOY_LIVE_STATE:?}"

test "${DEPLOYMENT_MODE:-}" = atomic
test "$DEPLOY_STABLE_DIR" = e-sign-laravel
# The selected release must be the one this run built, and it must be serving.
test "$DEPLOY_LIVE_COMMIT" = "$DEPLOY_SOURCE_COMMIT"
test "$DEPLOY_LIVE_STATE" = serving

doctor_out=$(mktemp)
trap 'rm -f "$doctor_out"' EXIT
for attempt in 1 2 3; do
    # shellcheck disable=SC2029 # Validated action outputs expand on the runner by design.
    if ssh "$DEPLOY_SSH_TARGET" \
        "cd \"\$HOME/$DEPLOY_STABLE_DIR\" && $DEPLOY_PHP_BINARY -d memory_limit=1G artisan esign:doctor" \
        >"$doctor_out" 2>&1; then
        echo "esign:doctor: all checks passed."
        break
    fi
    if [ "$attempt" -eq 3 ]; then
        cat "$doctor_out"
        exit 1
    fi
    sleep 60
done

# shellcheck disable=SC2029 # printf quotes validated action inputs for the remote shell.
ssh "$DEPLOY_SSH_TARGET" \
    "bash -s -- $(printf '%q ' "$DEPLOY_STABLE_DIR" "$DEPLOY_SITE_URL" "$DEPLOY_PHP_BINARY")" <<'REMOTE'
set -euo pipefail
php=$3
cd "$HOME/$1"
# The cached configuration refers to application classes, so it needs the autoloader.
token=$("$php" -d display_errors=stderr -r 'require "vendor/autoload.php"; $config = require "bootstrap/cache/config.php"; echo $config["esign"]["health_token"] ?? "";')
if [ -z "$token" ]; then
    echo "::error::ESIGN_HEALTH_TOKEN is not set, so /health/ready cannot report its probes." >&2
    exit 1
fi
printf 'header = "Authorization: Bearer %s"\n' "$token" \
    | curl --silent --show-error --max-time 60 --config - "$2/health/ready" \
    | "$php" -r '
        $probe = json_decode((string) stream_get_contents(STDIN), true)["probes"]["document_isolation"] ?? null;
        if (! is_array($probe)) {
            fwrite(STDERR, "::error::/health/ready did not report a document_isolation probe.\n");
            exit(1);
        }
        echo "document_isolation: ", $probe["status"], ": ", $probe["message"], "\n";
        exit($probe["status"] === "ok" ? 0 : 1);
      '
REMOTE
