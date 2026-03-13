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

rootful_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/podman" <<'EOF'
#!/bin/bash

if [[ "$1" == "compose" ]] && [[ "$2" == "version" ]]; then
    printf '>>>> Executing external compose provider "/usr/libexec/docker/cli-plugins/docker-compose". Please refer to the documentation for details. <<<<\n'
    printf 'Docker Compose version v5.0.1\n'
    exit 0
fi

if [[ "$1" == "info" ]] && [[ "$2" == "--format" ]] && [[ "$3" == "{{.Host.Security.Rootless}}" ]]; then
    printf 'false\n'
    exit 0
fi

if [[ "$1" == "info" ]] && [[ "$2" == "--format" ]] && [[ "$3" == "{{.Host.RemoteSocket.Path}}" ]]; then
    printf '/run/podman/podman.sock\n'
    exit 0
fi

exit 0
EOF

    cat > "$TEMP_BIN_DIR/podman-compose" <<'EOF'
#!/bin/bash

exit 0
EOF

    cat > "$TEMP_BIN_DIR/sudo" <<'EOF'
#!/bin/bash

if [[ "$1" == "-n" ]] && [[ "$2" == "true" ]]; then
    exit 0
fi

echo "$*" >> "$COMMAND_LOG"
exec "$@"
EOF

    cat > "$TEMP_BIN_DIR/systemctl" <<'EOF'
#!/bin/bash

echo "systemctl $*" >> "$COMMAND_LOG"
exit 0
EOF

    cat > "$TEMP_BIN_DIR/env" <<'EOF'
#!/bin/bash

exec /usr/bin/env "$@"
EOF

    chmod +x \
        "$TEMP_BIN_DIR/env" \
        "$TEMP_BIN_DIR/podman" \
        "$TEMP_BIN_DIR/podman-compose" \
        "$TEMP_BIN_DIR/sudo" \
        "$TEMP_BIN_DIR/systemctl"

    PATH="$TEMP_BIN_DIR" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        init_container_runtime
        run_host_container_cli ps
        run_host_compose ps

        printf "rootful=%s\n" "$HOST_PODMAN_ROOTFUL"
        printf "compose=%s\n" "$HOST_COMPOSE_COMMAND"
        printf "socket=%s\n" "$CONTAINER_SOCKET_PATH"
        [[ -f "$COMMAND_LOG" ]] && /bin/cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$rootful_output" "rootful=1"
assert_contains "$rootful_output" "compose=podman-compose"
assert_contains "$rootful_output" "socket=/run/podman/podman.sock"
assert_contains "$rootful_output" "podman info --format {{.Host.Security.Rootless}}"
assert_contains "$rootful_output" "podman info --format {{.Host.RemoteSocket.Path}}"
assert_contains "$rootful_output" "CONTAINER_SOCKET_PATH=/run/podman/podman.sock"
assert_contains "$rootful_output" "CONTAINER_LOG_DRIVER=k8s-file"
assert_contains "$rootful_output" "IN_CONTAINER_CLI=docker"
assert_contains "$rootful_output" "IN_CONTAINER_COMPOSE_COMMAND=docker-compose"
assert_contains "$rootful_output" "PWD="
assert_contains "$rootful_output" "podman ps"
assert_contains "$rootful_output" "podman-compose ps"

echo "container-runtime podman rootful checks passed"
