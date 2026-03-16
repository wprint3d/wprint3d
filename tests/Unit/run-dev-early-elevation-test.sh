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

run_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"

    mkdir -p "$TEMP_DIR/internal" "$TEMP_DIR/frontend"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"
    touch "$TEMP_DIR/docker-compose-development.yml"

    cat > "$TEMP_DIR/internal/container-runtime.sh" <<'EOF'
#!/bin/bash

podman_rootful_enabled() {
    return 0
}

prime_elevated_access() {
    printf 'prime\n' >> "$COMMAND_LOG"
}

init_container_runtime() {
    HOST_CONTAINER_RUNTIME='podman'
    HOST_COMPOSE_COMMAND='podman-compose'
}

frontend_node_modules_needs_permission_repair() {
    return 1
}

run_host_compose() {
    printf 'compose %s\n' "$*" >> "$COMMAND_LOG"
}

run_host_container_cli() {
    return 0
}
EOF

    COMMAND_LOG="$COMMAND_LOG" \
    bash "$TEMP_DIR/run.sh" -e dev 2>&1 || true

    cat "$COMMAND_LOG"
)"

assert_contains "$run_output" $'prime\ncompose -f docker-compose-development.yml pull'

echo "run.sh early elevation checks passed"
