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

test_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"

    cat > "$TEMP_BIN_DIR/podman-compose" <<'EOF'
#!/usr/bin/env python3

import os

print(f"podman-compose-pwd={os.environ.get('PWD', '')}")
print(f"podman-compose-socket={os.environ.get('CONTAINER_SOCKET_PATH', '')}")
print(f"podman-compose-driver={os.environ.get('CONTAINER_LOG_DRIVER', '')}")
print(f"podman-compose-runtime={os.environ.get('HOST_CONTAINER_RUNTIME', '')}")
print(f"podman-compose-command={os.environ.get('HOST_COMPOSE_COMMAND', '')}")
print(f"podman-compose-rootful={os.environ.get('HOST_PODMAN_ROOTFUL', '')}")
EOF

    cat > "$TEMP_BIN_DIR/sudo" <<'EOF'
#!/bin/bash

if [[ "${1:-}" == "-n" ]]; then
    shift
fi

unset \
    PWD \
    CONTAINER_SOCKET_PATH \
    CONTAINER_LOG_DRIVER \
    IN_CONTAINER_CLI \
    IN_CONTAINER_COMPOSE_COMMAND \
    HOST_CONTAINER_RUNTIME \
    HOST_COMPOSE_COMMAND \
    HOST_PODMAN_ROOTFUL

exec "$@"
EOF

    chmod +x "$TEMP_BIN_DIR/podman-compose" "$TEMP_BIN_DIR/sudo"

    PATH="$TEMP_BIN_DIR:/usr/bin:/bin" \
    ROOT_DIR="$ROOT_DIR" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        SCRIPT_PATH="$ROOT_DIR"
        export PWD="/tmp/wprint3d-compose-dir"
        export CONTAINER_SOCKET_PATH="/run/podman/podman.sock"
        export CONTAINER_LOG_DRIVER="k8s-file"
        export IN_CONTAINER_CLI="docker"
        export IN_CONTAINER_COMPOSE_COMMAND="docker-compose"
        export HOST_CONTAINER_RUNTIME="podman"
        export HOST_COMPOSE_COMMAND="podman-compose"
        export HOST_PODMAN_ROOTFUL=1

        run_podman_rootful_command compose ps
    ' 2>&1
)"

assert_contains "$test_output" "podman-compose-pwd=/tmp/wprint3d-compose-dir"
assert_contains "$test_output" "podman-compose-socket=/run/podman/podman.sock"
assert_contains "$test_output" "podman-compose-driver=k8s-file"
assert_contains "$test_output" "podman-compose-runtime=podman"
assert_contains "$test_output" "podman-compose-command=podman-compose"
assert_contains "$test_output" "podman-compose-rootful=1"

echo "container-runtime rootful podman-compose env checks passed"
