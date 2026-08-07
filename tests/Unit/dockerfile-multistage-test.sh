#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
DOCKERFILE="$ROOT_DIR/Dockerfile"
FRONTEND_DOCKERFILE="$ROOT_DIR/frontend/Dockerfile"
DOCKERIGNORE="$ROOT_DIR/.dockerignore"

for target in backend mapper streamer development production; do
    if ! rg -n "^FROM .* AS ${target}$" "$DOCKERFILE" > /dev/null; then
        echo "Backend Dockerfile is missing target: ${target}" >&2
        exit 1
    fi
done

for target in build development production; do
    if ! rg -n "^FROM .* AS ${target}$" "$FRONTEND_DOCKERFILE" > /dev/null; then
        echo "Frontend Dockerfile is missing target: ${target}" >&2
        exit 1
    fi
done

if [[ -e "$ROOT_DIR/Dockerfile.dev" || -e "$ROOT_DIR/frontend/Dockerfile.dev" ]]; then
    echo 'Legacy development Dockerfiles should not exist after target consolidation.' >&2
    exit 1
fi

if rg -n 'dockerfile-plus|INCLUDE\+' "$DOCKERFILE" "$FRONTEND_DOCKERFILE" > /dev/null; then
    echo 'Dockerfile-plus directives remain in a consolidated Dockerfile.' >&2
    exit 1
fi

if rg -n '(^|[^[:alnum:]_])uv([^[:alnum:]_]|$)|mjpg-streamer' "$DOCKERFILE" > /dev/null; then
    echo 'Unused uv or mjpg-streamer build dependencies remain in the backend Dockerfile.' >&2
    exit 1
fi

for pinned_dependency in \
    'MONGODB_VERSION=1.21.0' \
    'REDIS_VERSION=6.3.0' \
    'DIO_VERSION=0.3.0' \
    'OPENSWOOLE_VERSION=26.2.0' \
    'MEMCACHED_VERSION=3.4.0' \
    'YAML_VERSION=2.3.0' \
    'LIBCAMERA_COMMIT=d83ff0a4ae4503bc56b7ed48cd142c3dd423ad3b' \
    'CAMERA_STREAMER_COMMIT=dbdba86ea8ef7faf7d8900c32629510bc3e4c693' \
    'USTREAMER_COMMIT=b5e12a841270fedea75aa03b05de8fda15a3745e'; do
    if ! rg -F "$pinned_dependency" "$DOCKERFILE" > /dev/null; then
        echo "Dockerfile is missing pinned dependency: ${pinned_dependency}" >&2
        exit 1
    fi
done

if ! rg -n '^\*\*/\.git$' "$DOCKERIGNORE" > /dev/null \
    || rg -n '^!.*\.git' "$DOCKERIGNORE" > /dev/null \
    || ! rg -n '^\.env$' "$DOCKERIGNORE" > /dev/null; then
    echo 'The production build context must exclude Git metadata and local environment secrets.' >&2
    exit 1
fi

if ! rg -n 'composer install' "$DOCKERFILE" > /dev/null \
    || ! rg -n -- '--no-dev' "$DOCKERFILE" > /dev/null \
    || ! rg -n 'pnpm fetch --frozen-lockfile' "$DOCKERFILE" "$FRONTEND_DOCKERFILE" > /dev/null; then
    echo 'Locked production dependency stages are missing.' >&2
    exit 1
fi

echo 'Docker multistage checks passed'
