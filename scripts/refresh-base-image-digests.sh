#!/usr/bin/env bash
#
# Print the current multi-arch index digest of every base image tag in .docker/Dockerfile, so a
# deliberate refresh can replace the pinned `tag@sha256:…` values (issue #37). Changes nothing.
#
# Usage: scripts/refresh-base-image-digests.sh
set -euo pipefail

cd "$(dirname "$0")/.."

grep -oE '^FROM( --platform=[^ ]+)? [^ ]+' .docker/Dockerfile | awk '{print $NF}' | grep -v '^base$' | sort -u |
while read -r ref; do
    image="${ref%@*}"
    name="${image%:*}"
    tag="${image##*:}"
    case "$name" in */*) repo="$name" ;; *) repo="library/$name" ;; esac

    token=$(curl -fsS "https://auth.docker.io/token?service=registry.docker.io&scope=repository:${repo}:pull" |
        sed -E 's/.*"token":"([^"]+)".*/\1/')
    digest=$(curl -fsSI \
        -H "Authorization: Bearer ${token}" \
        -H 'Accept: application/vnd.oci.image.index.v1+json,application/vnd.docker.distribution.manifest.list.v2+json' \
        "https://registry-1.docker.io/v2/${repo}/manifests/${tag}" |
        tr -d '\r' | awk -F': ' 'tolower($1) == "docker-content-digest" {print $2}')

    current="${ref#*@}"
    [ "$current" = "$ref" ] && current="(unpinned)"
    printf '%-28s pinned %s\n%-28s latest %s\n' "$image" "$current" "" "$digest"
done
