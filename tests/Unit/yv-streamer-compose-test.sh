#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

assert_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected output to contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

production_compose="$(cat "$ROOT_DIR"/docker-compose.yml)"
development_compose="$(cat "$ROOT_DIR"/docker-compose-development.yml)"

assert_contains "$production_compose" 'yv-streamer-software:'
assert_contains "$production_compose" 'image: docker.io/wprint3d/yv-streamer-software:latest'
assert_contains "$development_compose" 'yv-streamer-software:'
assert_contains "$development_compose" 'image: docker.io/wprint3d/yv-streamer-software:latest'

if rg -n 'dockerfile:.*yv-streamer-software' \
    "$ROOT_DIR/docker-compose.yml" \
    "$ROOT_DIR/docker-compose-development.yml" > /dev/null; then
    echo 'yv-streamer-software is externally published and must not be built from this repository.' >&2
    exit 1
fi

echo "yv-streamer compose checks passed"
