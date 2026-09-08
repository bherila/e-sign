#!/usr/bin/env bash
#
# Build the cPanel/shared-hosting release bundle: dist/bwh-esign-<version>.tar.gz plus a
# SHA-256 sums file, built from a clean `git archive` (never the working tree, so untracked
# files, .env, and local build cruft can never leak in) with production Composer dependencies
# and a real Vite build baked in.
#
# Contents mirror exactly what .github/workflows/deploy.yml rsyncs to a live cPanel account
# (app, bootstrap, config, database, public, resources, routes, storage, vendor, artisan,
# composer.json, composer.lock, LICENSE, THIRD_PARTY_NOTICES.md), plus the three things a
# rsync-based *update* does not need to carry every time but a *first install from nothing*
# does: the built public/build assets (already inside `public/`, called out here because a
# git archive alone would not have them — see the build step below), htaccess-append.txt (the
# ea-phpNN handler block, appended in CI on every deploy but only ever *shipped* here), and
# INSTALL.md.
#
# Usage:
#   scripts/build-release.sh [version]
#
# [version] defaults to `git describe --tags --always --dirty`. Used only for the output
# filename; nothing in the bundle's contents is templated with it.
#
# Requires: git, composer, pnpm, tar, and sha256sum or shasum. Runs `composer install --no-dev`
# and `pnpm install && pnpm run build` inside a scratch checkout, never in this working tree,
# so it never mutates the developer's own vendor/ or node_modules/.
#
# Exit codes: 0 built, 1 usage/tooling error, 2 a build step failed.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

version="${1:-$(git describe --tags --always --dirty 2>/dev/null || echo 0.0.0-dev)}"

for tool in git composer pnpm tar; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "error: '$tool' is required and was not found on PATH." >&2
        exit 1
    fi
done

if command -v sha256sum >/dev/null 2>&1; then
    sha256() { sha256sum "$1"; }
elif command -v shasum >/dev/null 2>&1; then
    sha256() { shasum -a 256 "$1"; }
else
    echo "error: neither sha256sum nor shasum is on PATH." >&2
    exit 1
fi

dist_dir="$repo_root/dist"
mkdir -p "$dist_dir"

work_dir="$(mktemp -d "${TMPDIR:-/tmp}/bwh-esign-release.XXXXXX")"
trap 'rm -rf "$work_dir"' EXIT

archive_dir="$work_dir/bwh-esign"
mkdir -p "$archive_dir"

echo "==> Archiving the committed tree at $(git rev-parse --short HEAD)"
git archive HEAD | tar -x -C "$archive_dir"

echo "==> Installing production Composer dependencies"
(cd "$archive_dir" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader)

echo "==> Building frontend assets"
(cd "$archive_dir" && pnpm install --frozen-lockfile && pnpm run build)

bundle_root="$work_dir/bundle"
mkdir -p "$bundle_root"

echo "==> Assembling the bundle"
for entry in app bootstrap config database public resources routes storage vendor \
    artisan composer.json composer.lock LICENSE THIRD_PARTY_NOTICES.md htaccess-append.txt; do
    cp -R "$archive_dir/$entry" "$bundle_root/$entry"
done

cp "$repo_root/scripts/release/INSTALL.md" "$bundle_root/INSTALL.md"

archive_name="bwh-esign-${version}.tar.gz"
archive_path="$dist_dir/$archive_name"

echo "==> Writing $archive_path"
tar -czf "$archive_path" -C "$bundle_root" .

(cd "$dist_dir" && sha256 "$archive_name" > "${archive_name}.sha256")

echo "==> Done"
echo "    $archive_path"
echo "    $archive_path.sha256"
