# BWH eSign application image.
#
# One image, three roles, selected by the container command (see .docker/scripts/entrypoint.sh):
#   web        nginx + php-fpm serving HTTP on :8080 (default)
#   worker     php artisan queue:work (database queue; sealing happens here)
#   scheduler  php artisan schedule:run every 60s
#
# Targets:
#   dev         PHP runtime + Node/pnpm; source is bind-mounted by docker compose.
#   assets      compiles the Vite bundle (public/build).
#   production  immutable: code + vendor (--no-dev) + built assets, runs as www-data.
#
#   docker build --target production -f .docker/Dockerfile -t bwh-esign:$(git rev-parse --short HEAD) .
#
# Runtime policy (docs/HANDOFF.md §13): PHP only. No Node, Python, Java, Chromium, or Redis in the
# production image. Signing key material is never copied into a layer; mount it into the worker.

# ---------- base: PHP 8.5 FPM + nginx + extensions ----------
FROM php:8.5-fpm AS base
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx curl unzip \
        libpng-dev libonig-dev libxml2-dev libzip-dev libicu-dev libjpeg-dev libfreetype6-dev \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*
# opcache is compiled into php:8.5-fpm already; it is tuned in .docker/php/php.ini.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd zip intl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY .docker/php/php.ini            /usr/local/etc/php/conf.d/custom.ini
COPY .docker/php/fpm-pool.conf      /usr/local/etc/php-fpm.d/zz-esign.conf
COPY .docker/nginx/nginx.conf       /etc/nginx/nginx.conf
COPY .docker/scripts/entrypoint.sh  /usr/local/bin/esign-entrypoint
RUN chmod +x /usr/local/bin/esign-entrypoint \
    && rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p /tmp/nginx /var/www/html \
    && chown -R www-data:www-data /tmp/nginx /var/www/html
EXPOSE 8080

# ---------- dev: + Node/pnpm; code bind-mounted by docker compose ----------
FROM base AS dev
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && corepack enable && corepack prepare pnpm@11.5.0 --activate \
    && rm -rf /var/lib/apt/lists/*
ENV APP_ENV=local
# Runs as root so bind-mounted source owned by the host user stays writable; php-fpm workers still
# drop to www-data (pool default) and nginx workers are switched to www-data by the entrypoint.
ENTRYPOINT ["esign-entrypoint"]
CMD ["web"]

# ---------- vendor: Composer dependencies, resolved once on the build host ----------
# vendor/ is pure PHP, so it is built once on the native platform (no QEMU) and copied into every
# target platform. This halves the dist downloads per multi-arch build and keeps flaky upstream
# zipball fetches out of the slow emulated leg.
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
COPY composer.json composer.lock ./
# Optional BuildKit secret `composer_auth` (a COMPOSER_AUTH JSON document) lets CI fetch dists
# with an authenticated GitHub token; local builds work without it.
RUN --mount=type=cache,target=/tmp/cache \
    --mount=type=secret,id=composer_auth,required=false \
    export COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null || echo '{}')" \
    && (composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs \
        || (sleep 15 && composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs))

# ---------- assets: compile the frontend bundle ----------
FROM --platform=$BUILDPLATFORM node:24-bookworm-slim AS assets
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@11.5.0 --activate
# pnpm-workspace.yaml carries install policy (overrides, allowBuilds); it must be present before install.
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY scripts/spotlight-noindex.mjs ./scripts/
RUN pnpm install --frozen-lockfile
COPY . .
RUN pnpm run build

# ---------- production: immutable, self-contained, non-root ----------
FROM base AS production
ENV APP_ENV=production
COPY .docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini
COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/vendor
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -rf /root/.composer \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
                storage/app/private storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["esign-entrypoint"]
CMD ["web"]
