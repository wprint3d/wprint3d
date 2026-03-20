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

legacy_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/podman-compose" <<'EOF'
#!/bin/bash

if [[ "$1" == "--help" ]]; then
    cat <<'HELP'
usage: podman-compose [-h] [-v] [-f file] [-p PROJECT_NAME]
                      [--podman-path PODMAN_PATH] [--podman-args args]
                      [--dry-run]
                      {help,version,pull,push,build,up,down,ps,run,exec,start,stop,restart,logs}
                      ...
HELP
    exit 0
fi

printf 'provider_command=%s\n' "$*"
printf 'provider_socket=%s\n' "${CONTAINER_SOCKET_PATH:-unset}"
printf 'provider_log_driver=%s\n' "${CONTAINER_LOG_DRIVER:-unset}"
printf 'provider_cli=%s\n' "${IN_CONTAINER_CLI:-unset}"
printf 'provider_compose=%s\n' "${IN_CONTAINER_COMPOSE_COMMAND:-unset}"
printf 'provider_pwd=%s\n' "${PWD:-unset}"
exit 0
EOF

    cat > "$TEMP_BIN_DIR/sudo" <<'EOF'
#!/bin/bash

if [[ "$1" == "-n" ]]; then
    shift
fi

echo "$*" >> "$COMMAND_LOG"
exec "$@"
EOF

    chmod +x \
        "$TEMP_BIN_DIR/podman-compose" \
        "$TEMP_BIN_DIR/sudo"

    PATH="$TEMP_BIN_DIR:$PATH" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        HOST_CONTAINER_RUNTIME=podman
        HOST_PODMAN_ROOTFUL=1
        HOST_COMPOSE_COMMAND=podman-compose
        HOST_COMPOSE_COMMAND_ARGS=(podman-compose)
        export HOST_CONTAINER_RUNTIME
        export HOST_PODMAN_ROOTFUL
        export HOST_COMPOSE_COMMAND
        export CONTAINER_SOCKET_PATH=/run/podman/podman.sock
        export CONTAINER_LOG_DRIVER=k8s-file
        export IN_CONTAINER_CLI=docker
        export IN_CONTAINER_COMPOSE_COMMAND=docker-compose

        run_host_compose ps

        [[ -f "$COMMAND_LOG" ]] && /bin/cat "$COMMAND_LOG"
    ' 2>&1
)"

assert_contains "$legacy_output" "provider_command=ps"
assert_contains "$legacy_output" "provider_socket=/run/podman/podman.sock"
assert_contains "$legacy_output" "provider_log_driver=k8s-file"
assert_contains "$legacy_output" "provider_cli=docker"
assert_contains "$legacy_output" "provider_compose=docker-compose"
assert_contains "$legacy_output" "provider_pwd=/home/facuarmo/wprint3d-core"
assert_contains "$legacy_output" "podman-compose --help"
assert_contains "$legacy_output" "env PWD=/home/facuarmo/wprint3d-core CONTAINER_SOCKET_PATH=/run/podman/podman.sock CONTAINER_LOG_DRIVER=k8s-file IN_CONTAINER_CLI=docker IN_CONTAINER_COMPOSE_COMMAND=docker-compose podman-compose ps"
assert_not_contains "$legacy_output" "--env-file"

echo "container-runtime podman rootful legacy compose checks passed"
