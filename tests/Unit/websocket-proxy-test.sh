#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

assert_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected content to contain: $needle" >&2
        exit 1
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Expected content not to contain: $needle" >&2
        exit 1
    fi
}

proxy_contents="$(cat "$ROOT_DIR/proxy/conf.d/default.conf")"
production_compose="$(cat "$ROOT_DIR/docker-compose.yml")"
development_compose="$(cat "$ROOT_DIR/docker-compose-development.yml")"

assert_contains "$proxy_contents" 'location ^~ /app/'
assert_contains "$proxy_contents" 'proxy_pass              http://backend:6001;'
assert_contains "$proxy_contents" 'proxy_set_header Upgrade $http_upgrade;'
assert_contains "$proxy_contents" 'proxy_set_header Connection $connection_upgrade;'
assert_contains "$proxy_contents" 'proxy_read_timeout      1h;'
assert_contains "$proxy_contents" 'proxy_send_timeout      1h;'
assert_not_contains "$proxy_contents" 'listen      6001 ssl;'

assert_not_contains "$production_compose" 'EXTERNAL_WEB_SOCKET_PORT'
assert_not_contains "$production_compose" ':6001:6001'
assert_not_contains "$development_compose" '- 6001:6001'
assert_contains "$production_compose" 'http://127.0.0.1:6001'
assert_contains "$development_compose" 'http://127.0.0.1:6001'

echo 'same-origin websocket proxy checks passed'
