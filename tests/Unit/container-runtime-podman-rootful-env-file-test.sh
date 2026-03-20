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

env_file_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/podman-compose" <<'EOF'
#!/bin/bash

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

if [[ "$1" == "env" ]]; then
    echo "sudo: a password is required" >&2
    exit 1
fi

if [[ "$1" == --preserve-env=* ]]; then
    echo "sudo: sorry, you are not allowed to set the following environment variables: PWD" >&2
    exit 1
fi

if [[ "$1" == "podman-compose" ]] && [[ "$2" == "--env-file" ]]; then
    printf 'env_file_contents_begin\n'
    cat "$3"
    printf 'env_file_contents_end\n'
fi

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
        export SCRIPT_PATH="$ROOT_DIR"

        run_host_compose ps

        [[ -f "$COMMAND_LOG" ]] && /bin/cat "$COMMAND_LOG"
    ' 2>&1
)"

assert_contains "$env_file_output" "provider_command=--env-file"
assert_contains "$env_file_output" "provider_socket=/run/podman/podman.sock"
assert_contains "$env_file_output" "provider_pwd=/home/facuarmo/wprint3d-core"
assert_contains "$env_file_output" "env_file_contents_begin"
assert_contains "$env_file_output" "PWD=/home/facuarmo/wprint3d-core"
assert_contains "$env_file_output" "CONTAINER_SOCKET_PATH=/run/podman/podman.sock"
assert_contains "$env_file_output" "CONTAINER_LOG_DRIVER=k8s-file"
assert_contains "$env_file_output" "IN_CONTAINER_CLI=docker"
assert_contains "$env_file_output" "IN_CONTAINER_COMPOSE_COMMAND=docker-compose"
assert_contains "$env_file_output" "podman-compose --env-file"
assert_not_contains "$env_file_output" "--preserve-env="
assert_not_contains "$env_file_output" " env "

echo "container-runtime podman rootful env-file checks passed"
