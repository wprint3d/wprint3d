#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
STREAM_PROXY_CONF="$ROOT_DIR/proxy/nginxconfig.io/stream-proxy.conf"
RUN_SCRIPT="$ROOT_DIR/internal/run.sh"
CAMERA_CONTROLLER="$ROOT_DIR/app/Http/Controllers/CameraController.php"

assert_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected content to contain: $needle" >&2
        exit 1
    fi
}

if [[ ! -f "$STREAM_PROXY_CONF" ]]; then
    echo "Expected stream proxy include at $STREAM_PROXY_CONF" >&2
    exit 1
fi

stream_proxy_contents="$(cat "$STREAM_PROXY_CONF")"
run_contents="$(cat "$RUN_SCRIPT")"
controller_contents="$(cat "$CAMERA_CONTROLLER")"

assert_contains "$stream_proxy_contents" 'proxy_buffering                    off;'
assert_contains "$stream_proxy_contents" 'proxy_ignore_headers               X-Accel-Buffering;'
assert_contains "$stream_proxy_contents" 'proxy_connect_timeout              30s;'
assert_contains "$stream_proxy_contents" 'proxy_read_timeout                 1h;'
assert_contains "$stream_proxy_contents" 'proxy_send_timeout                 1h;'
assert_contains "$stream_proxy_contents" 'MJPEG streams can pause'

stream_include_count="$(grep -c 'nginxconfig.io/stream-proxy.conf' "$RUN_SCRIPT")"
if [[ "$stream_include_count" -lt 2 ]]; then
    echo "Expected generated camera routes in internal/run.sh to use stream-proxy.conf" >&2
    exit 1
fi

assert_contains "$controller_contents" 'nginxconfig.io/stream-proxy.conf'

if grep -n 'include[[:space:]]*nginxconfig.io/proxy.conf;' "$RUN_SCRIPT" | grep -q '/video/'; then
    echo "Video routes must not use the fail-fast proxy.conf include" >&2
    exit 1
fi

echo "camera stream proxy timeout checks passed"
