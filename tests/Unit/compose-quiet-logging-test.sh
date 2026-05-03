#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
COMPOSE_FILE="$ROOT_DIR/docker-compose.yml"

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected ${description} to contain: ${needle}" >&2
        exit 1
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Expected ${description} not to contain: ${needle}" >&2
        exit 1
    fi
}

service_block() {
    local service="$1"

    awk -v service="$service" '
        $0 == "  " service ":" {
            in_block = 1
            print
            next
        }
        in_block && /^  [A-Za-z0-9_-]+:/ {
            exit
        }
        in_block {
            print
        }
    ' "$COMPOSE_FILE"
}

compose_contents="$(cat "$COMPOSE_FILE")"

assert_not_contains "$compose_contents" 'driver: local' 'production compose logging drivers'
assert_not_contains "$compose_contents" 'mode: non-blocking' 'production compose logging options'

for service in proxy backend web mapper streamer yv-streamer-software redis mongo memcached; do
    block="$(service_block "$service")"

    assert_contains "$block" 'logging:' "$service logging configuration"
    assert_contains "$block" 'driver: none' "$service logging driver"
done

for service in proxy backend mapper streamer; do
    block="$(service_block "$service")"

    assert_contains "$block" '/var/log/wprint3d:size=20m,mode=1777' "$service runtime log tmpfs"
done

for service in backend mapper streamer; do
    block="$(service_block "$service")"

    assert_contains "$block" 'WPRINT3D_RUNTIME_LOG_DIR=/var/log/wprint3d' "$service runtime log environment"
    assert_contains "$block" 'WPRINT3D_LOG_DIR=/var/log/wprint3d/app' "$service app log environment"
    assert_contains "$block" 'LOG_LEVEL=${WPRINT3D_LOG_LEVEL:-warning}' "$service default log level"
done

assert_contains "$(service_block yv-streamer-software)" 'YV_STREAMER_SOFTWARE_LOG_LEVEL=warn' 'yv-streamer log level'
assert_contains "$(service_block redis)" 'redis-server --save "" --appendonly no --loglevel warning' 'redis log level'

echo 'compose quiet logging checks passed'
