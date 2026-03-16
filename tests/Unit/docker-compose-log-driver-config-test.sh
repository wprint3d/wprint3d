#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.yml"
DEVELOPMENT_COMPOSE_FILE="${ROOT_DIR}/docker-compose-development.yml"
EXPECTED_DRIVER='driver: ${CONTAINER_LOG_DRIVER:-local}'

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

production_compose_contents="$(cat "$COMPOSE_FILE")"
development_compose_contents="$(cat "$DEVELOPMENT_COMPOSE_FILE")"

assert_contains "$production_compose_contents" "$EXPECTED_DRIVER"
assert_contains "$development_compose_contents" "$EXPECTED_DRIVER"
assert_not_contains "$production_compose_contents" 'driver: local'

echo "docker-compose log-driver config checks passed"
