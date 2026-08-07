#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

while IFS= read -r reference; do
    value="${reference#*=}"

    if [[ "$value" != docker.io/* ]]; then
        echo "Expected a fully qualified Docker image reference, found: ${value}" >&2
        exit 1
    fi
done < <(rg --no-line-number '^ARG [A-Z0-9_]+_IMAGE=' "$ROOT_DIR/Dockerfile" "$ROOT_DIR/Dockerfile.proxy" "$ROOT_DIR/frontend/Dockerfile")

if rg -n '^FROM (php|nginx|node|alpine|composer|docker):' \
    "$ROOT_DIR/Dockerfile" \
    "$ROOT_DIR/Dockerfile.proxy" \
    "$ROOT_DIR/frontend/Dockerfile" > /dev/null; then
    echo 'Found an unqualified literal Docker image reference.' >&2
    exit 1
fi

echo 'Dockerfile image reference checks passed'
