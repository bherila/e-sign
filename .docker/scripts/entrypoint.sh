#!/bin/sh
# Role dispatcher for the BWH eSign image. The same image runs as web, worker, or scheduler.
#
# Laravel's caches are built here at container start, not at image build, so they capture the
# environment injected at runtime. Migrations are deliberately NOT run here: run
# `php artisan migrate --force` once per release from an operator step or a one-shot job so
# concurrent replicas never race.
set -eu

ROLE="${1:-web}"
[ $# -gt 0 ] && shift

# .docker/scripts/healthcheck.sh reads this to decide what "healthy" means for the role running
# in this container (nginx is only present for `web`; worker/scheduler are checked by process
# liveness instead). Written before exec, so it is in place for the very first HEALTHCHECK tick.
echo "$ROLE" > /tmp/esign-role

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

case "$ROLE" in
    web)
        # php-fpm on a unix socket in the background; nginx in the foreground as PID 1's child
        # so SIGTERM stops it cleanly. Both run as the unprivileged container user.
        php-fpm -D
        # In the dev target the master runs as root (bind mounts); make nginx workers www-data so
        # they can open the php-fpm socket. As a non-root user (production) the directive is invalid.
        if [ "$(id -u)" = "0" ]; then
            exec nginx -c /etc/nginx/nginx.conf -g 'daemon off; user www-data;'
        fi
        exec nginx -c /etc/nginx/nginx.conf -g 'daemon off;'
        ;;
    worker)
        # Bounded worker: exits after --max-time so the orchestrator restarts it with fresh code
        # and memory. Sealing jobs run here, so this is the only role that needs key material.
        exec php artisan queue:work --tries=3 --timeout=600 --sleep=3 --max-time=3600 "$@"
        ;;
    scheduler)
        exec sh -c 'while true; do php artisan schedule:run --no-interaction; sleep 60; done'
        ;;
    artisan)
        exec php artisan "$@"
        ;;
    *)
        exec "$ROLE" "$@"
        ;;
esac
