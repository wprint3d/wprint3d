#!/bin/bash

set -euo pipefail

cd /app

manifest_hash="$({
    sha256sum package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc
} | sha256sum | cut -d ' ' -f 1)"
manifest_marker='/app/node_modules/.wprint3d-manifest-hash'

pnpm config set store-dir /pnpm/store

if [[ ! -d '/app/node_modules/.pnpm' ]] \
    || [[ ! -f "$manifest_marker" ]] \
    || [[ "$(cat "$manifest_marker")" != "$manifest_hash" ]]; then
    echo '=> Installing frontend dependencies...'
    pnpm install --prefer-offline
    printf '%s' "$manifest_hash" > "$manifest_marker"
else
    echo '=> Frontend dependencies are unchanged; reusing node_modules.'
fi

echo '=> Starting the server in developer mode...'

export EXPO_UNSTABLE_ATLAS=true

exec pnpm exec expo start --clear
