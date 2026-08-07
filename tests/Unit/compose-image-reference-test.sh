#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

assert_not_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Did not expect output to contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

compose_refs="$(rg -n 'image:' "$ROOT_DIR"/docker-compose.yml "$ROOT_DIR"/docker-compose-development.yml)"

assert_not_contains "$compose_refs" 'image: redis:'
assert_not_contains "$compose_refs" 'image: mongo:'
assert_not_contains "$compose_refs" 'image: memcached'

if ! grep -q 'docker.io/wprint3d/wprint3d-mapper:latest' <<< "$compose_refs"; then
    echo 'Production mapper does not use its role-specific image.' >&2
    exit 1
fi

if ! grep -q 'docker.io/wprint3d/wprint3d-streamer:latest' <<< "$compose_refs"; then
    echo 'Production streamer does not use its role-specific image.' >&2
    exit 1
fi

echo "compose image reference checks passed"
