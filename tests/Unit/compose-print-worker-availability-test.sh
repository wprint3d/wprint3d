#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected ${description} to contain: ${needle}" >&2
        exit 1
    fi
}

for compose_file in docker-compose.yml docker-compose-development.yml; do
    contents="$(cat "$ROOT_DIR/$compose_file")"

    assert_contains \
        "$contents" \
        'QUEUES=default:1,recordings:1,broadcasts:2,prints:1,previews,snapshots' \
        "$compose_file backend queue configuration"

    assert_contains \
        "$contents" \
        'aliases:' \
        "$compose_file backend network configuration"

    assert_contains \
        "$contents" \
        '          - ws-server' \
        "$compose_file backend network aliases"
done

echo 'compose print worker availability checks passed'
