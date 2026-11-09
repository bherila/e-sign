#!/usr/bin/env bash
#
# The Compose end-to-end smoke test (issue #37): one agreement, signed end to end against the
# production image as it runs in production, with a worker killed mid-finalization.
#
#   web + worker roles  ·  MySQL 8.4  ·  Garage (S3)  ·  the synthetic seal fixtures
#
# 1. Start MySQL and Garage; give Garage a layout, a bucket and a key; write the env file.
# 2. Run the release migration against the disposable database (the `esign-migrate` step a
#    deployment runs), start the web and worker roles, create an owner and an API credential.
# 3. scripts/e2e/drive.py creates and sends a signing request through the Firma facade, follows
#    the invitation the worker queued, and signs as the guest over HTTP — CSRF, cookies and all.
# 4. With the worker stopped, the signature leaves the envelope `finalizing`. A held row lock on
#    the envelope freezes the restarted worker inside the finalization job; the worker is then
#    killed with SIGKILL. A fresh worker and `esign:finalization:resume` — the product's own
#    recovery for a crashed finalization — must complete it exactly once.
# 5. The sealed PDF downloaded through the facade must hash to the published artifact's digest,
#    and `esign:artifacts:verify` must re-read every artifact from Garage and validate its seal.
#
# Needs: docker compose, python3, an image tagged $ESIGN_IMAGE (default bwh-esign:ci).
# Usage: scripts/e2e/compose-smoke.sh
set -euo pipefail

cd "$(dirname "$0")/../../tests/EndToEnd/compose"

export ESIGN_IMAGE="${ESIGN_IMAGE:-bwh-esign:ci}"
COMPOSE=(docker compose -f docker-compose.yml)
DB_ROOT=(-uroot -pesign-e2e-synthetic-root esign)

log() { printf '\n==> %s\n' "$*"; }

dump() {
    log "Failure: container logs"
    "${COMPOSE[@]}" ps -a || true
    "${COMPOSE[@]}" logs --no-color --tail=200 esign esign-worker garage 2>&1 || true
}
trap 'status=$?; [ "$status" -ne 0 ] && dump; "${COMPOSE[@]}" --profile release down -v --remove-orphans >/dev/null 2>&1 || true; rm -f e2e.env; exit "$status"' EXIT

sql() {
    "${COMPOSE[@]}" exec -T mysql mysql -N -B "${DB_ROOT[@]}" -e "$1" 2>/dev/null
}

# --- 1. Storage and database --------------------------------------------------------------
: > e2e.env
log "Starting MySQL and Garage"
"${COMPOSE[@]}" up -d --wait mysql garage

garage() { "${COMPOSE[@]}" exec -T garage /garage "$@" 2>/dev/null; }

for _ in $(seq 1 30); do
    node=$(garage status | awk '/HEALTHY NODES/{getline; getline; print $1}')
    [ -n "${node:-}" ] && break
    sleep 1
done
[ -n "${node:-}" ] || { echo "Garage never reported a node"; exit 1; }

garage layout assign -z dc1 -c 1G "$node" >/dev/null
garage layout apply --version 1 >/dev/null
garage bucket create esign-e2e >/dev/null
keys=$(garage key create esign-e2e)
access_key=$(awk -F': *' '/Key ID/{print $2}' <<<"$keys" | tr -d '[:space:]')
secret_key=$(awk -F': *' '/Secret key/{print $2}' <<<"$keys" | tr -d '[:space:]')
garage bucket allow --read --write --owner esign-e2e --key esign-e2e >/dev/null
[ -n "$access_key" ] && [ -n "$secret_key" ] || { echo "Garage issued no key"; exit 1; }

cat > e2e.env <<ENV
APP_NAME="BWH eSign E2E"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=http://localhost:8080
LOG_CHANNEL=stderr
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=esign
DB_USERNAME=esign
DB_PASSWORD=esign-e2e-synthetic
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=30
MAIL_MAILER=log
ESIGN_DOCUMENTS_DRIVER=s3
ESIGN_DOCUMENTS_AWS_ACCESS_KEY_ID=${access_key}
ESIGN_DOCUMENTS_AWS_SECRET_ACCESS_KEY=${secret_key}
ESIGN_DOCUMENTS_AWS_DEFAULT_REGION=garage
ESIGN_DOCUMENTS_AWS_BUCKET=esign-e2e
ESIGN_DOCUMENTS_AWS_ENDPOINT=http://garage:3900
ESIGN_DOCUMENTS_AWS_USE_PATH_STYLE_ENDPOINT=true
ESIGN_SEAL_KEY_ID=fixture-seal-2026-a
ESIGN_SEAL_CERTIFICATE_PATH=/keys/seal.test.crt
ESIGN_SEAL_PRIVATE_KEY_PATH=/keys/seal.test.pkey
ESIGN_SEAL_CHAIN_PATH=/keys/root.test.crt
ESIGN_FINALIZATION_RESUME_AFTER_MINUTES=1
ENV

# --- 2. Release, roles, identities ---------------------------------------------------------
log "Running the release migration"
"${COMPOSE[@]}" --profile release run --rm esign-migrate

log "Starting the web and worker roles"
"${COMPOSE[@]}" up -d esign esign-worker
for i in $(seq 1 60); do
    curl -fsS http://localhost:8080/up >/dev/null 2>&1 && { echo "web up after ${i}s"; break; }
    sleep 1
done
curl -fsS http://localhost:8080/up >/dev/null

artisan() { "${COMPOSE[@]}" exec -T esign php artisan "$@"; }
artisan esign:create-user --name='E2E Owner' --email=owner@e2e.example.test --password='synthetic-e2e-password' >/dev/null
artisan esign:bootstrap-owner --user=owner@e2e.example.test --workspace=e2e --name='E2E Workspace' >/dev/null
api_key=$(artisan esign:credential:issue --workspace=e2e --label='compose e2e' \
    --scope=compat:firma-v1 --scope=envelopes:read --scope=envelopes:write \
    | grep -oE 'esk_[A-Za-z0-9]+_[A-Za-z0-9]+' | head -1)
[ -n "$api_key" ] || { echo "No credential was issued"; exit 1; }

# --- 3. Create, send, sign -------------------------------------------------------------------
log "Creating and sending through the facade"
signer=signer@e2e.example.test
envelope=$(python3 ../../../scripts/e2e/drive.py send \
    --base http://localhost:8080 --key "$api_key" \
    --pdf ../../Fixtures/pdf/single-page-letter.pdf --email "$signer")
echo "envelope ${envelope}"

# The worker schedules the invitation; its URL is read from the queued message, as a mail
# client reads it from the message it receives (MAIL_MAILER=log sends nothing anywhere).
invitation=""
for _ in $(seq 1 60); do
    invitation=$(sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(context, '\$.action_url')) FROM outbound_mails WHERE kind='invitation' AND to_email='${signer}' ORDER BY id DESC LIMIT 1")
    [ -n "$invitation" ] && [ "$invitation" != NULL ] && break
    sleep 1
done
[ -n "$invitation" ] && [ "$invitation" != NULL ] || { echo "No invitation was queued"; exit 1; }

# Stopped before the signature, so the finalization the signature queues waits for step 4.
"${COMPOSE[@]}" stop esign-worker >/dev/null

log "Signing as the guest, over HTTP"
python3 ../../../scripts/e2e/drive.py sign --base http://localhost:8080 --url "$invitation"

state=$(sql "SELECT state FROM envelopes WHERE public_id='${envelope}'")
[ "$state" = finalizing ] || { echo "Expected finalizing after the last signature, got '${state}'"; exit 1; }
[ "$(sql "SELECT COUNT(*) FROM artifacts a JOIN envelopes e ON e.id = a.envelope_id WHERE e.public_id='${envelope}'")" = 0 ] \
    || { echo "An artifact exists before the worker ran"; exit 1; }

# --- 4. Kill the worker mid-finalization ----------------------------------------------------
log "Holding the envelope lock and starting the worker"
"${COMPOSE[@]}" exec -T mysql mysql "${DB_ROOT[@]}" -e \
    "BEGIN; SELECT id FROM envelopes WHERE public_id='${envelope}' FOR UPDATE; SELECT SLEEP(40); COMMIT;" >/dev/null 2>&1 &
holder=$!
sleep 2
"${COMPOSE[@]}" start esign-worker >/dev/null

blocked=""
for _ in $(seq 1 60); do
    waits=$(sql "SELECT COUNT(*) FROM performance_schema.data_lock_waits")
    reserved=$(sql "SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL AND payload LIKE '%FinalizeEnvelope%'")
    if [ "${waits:-0}" -gt 0 ] && [ "${reserved:-0}" -gt 0 ]; then blocked=1; break; fi
    sleep 0.5
done
[ -n "$blocked" ] || { echo "The worker never blocked inside the finalization job"; exit 1; }

log "Killing the worker with SIGKILL while it is inside the job"
"${COMPOSE[@]}" kill -s SIGKILL esign-worker
wait "$holder" || true

state=$(sql "SELECT state FROM envelopes WHERE public_id='${envelope}'")
[ "$state" = finalizing ] || { echo "Expected the envelope still finalizing after the kill, got '${state}'"; exit 1; }

# --- Recovery -----------------------------------------------------------------------------------
log "Recovering: a fresh worker, then the resume command once the envelope counts as stalled"
"${COMPOSE[@]}" start esign-worker >/dev/null
sleep 65
artisan esign:finalization:resume

finished=""
for _ in $(seq 1 90); do
    [ "$(sql "SELECT state FROM envelopes WHERE public_id='${envelope}'")" = completed ] && { finished=1; break; }
    sleep 1
done
[ -n "$finished" ] || { echo "The envelope never completed after recovery"; exit 1; }

executed=$(sql "SELECT COUNT(*) FROM artifacts a JOIN envelopes e ON e.id = a.envelope_id WHERE e.public_id='${envelope}' AND a.kind='executed_pdf'")
[ "$executed" = 1 ] || { echo "Expected exactly one executed PDF, found ${executed}"; exit 1; }

# --- 5. The sealed PDF, from Garage, through the facade ---------------------------------------
log "Downloading and verifying the sealed PDF"
digest=$(sql "SELECT a.sha256 FROM artifacts a JOIN envelopes e ON e.id = a.envelope_id WHERE e.public_id='${envelope}' AND a.kind='executed_pdf'")
python3 ../../../scripts/e2e/drive.py download --base http://localhost:8080 --key "$api_key" \
    --envelope "$envelope" --sha256 "$digest"

"${COMPOSE[@]}" exec -T esign-worker php artisan esign:artifacts:verify

log "Compose end-to-end smoke test passed"
