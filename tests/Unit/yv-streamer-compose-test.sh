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
assert_contains "$production_compose" 'image: wprint3d/yv-streamer-software:0.1.0'
assert_contains "$development_compose" 'yv-streamer-software:'
assert_contains "$development_compose" 'dockerfile: yv-streamer-software/Dockerfile'

echo "yv-streamer compose checks passed"
