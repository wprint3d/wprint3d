#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
DEFAULT_CONF="$ROOT_DIR/proxy/conf.d/default.conf"
BACKEND_CONF="$ROOT_DIR/proxy/backend-upstream-http.conf"
PROXY_INCLUDE="$ROOT_DIR/proxy/nginxconfig.io/proxy.conf"

assert_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected content to contain: $needle" >&2
        exit 1
    fi
}

default_contents="$(cat "$DEFAULT_CONF")"
backend_contents="$(cat "$BACKEND_CONF")"
proxy_include_contents="$(cat "$PROXY_INCLUDE")"

assert_contains "$default_contents" 'proxy_read_timeout      10m;'
assert_contains "$default_contents" 'Metro compiles the web bundle on demand'
assert_contains "$backend_contents" 'proxy_read_timeout 15s;'

if [[ "$proxy_include_contents" == *'proxy_read_timeout'* ]]; then
    echo 'The shared proxy include must not override route-specific response timeouts.' >&2
    exit 1
fi

echo "frontend proxy timeout checks passed"
