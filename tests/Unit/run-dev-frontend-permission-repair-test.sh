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

run_case() {
    local reown_setting="$1"
    local temp_dir command_log output

    temp_dir="$(mktemp -d)"
    command_log="$temp_dir/commands.log"

    mkdir -p "$temp_dir/internal" "$temp_dir/frontend/node_modules"
    cp "$ROOT_DIR/run.sh" "$temp_dir/run.sh"
    chmod +x "$temp_dir/run.sh"
    touch "$temp_dir/docker-compose-development.yml"

    cat > "$temp_dir/internal/container-runtime.sh" <<'EOF'
#!/bin/bash

init_container_runtime() {
    HOST_CONTAINER_RUNTIME='podman'
    HOST_COMPOSE_COMMAND='podman-compose'
}

frontend_node_modules_needs_permission_repair() {
    return 0
}

repair_frontend_node_modules_permissions() {
    printf 'repair %s\n' "$1" >> "$COMMAND_LOG"
}

run_host_compose() {
    printf 'compose %s\n' "$*" >> "$COMMAND_LOG"
}

run_host_container_cli() {
    return 0
}
EOF

    output="$(
        COMMAND_LOG="$command_log" \
        WPRINT3D_REOWN_FRONTEND_NODE_MODULES="$reown_setting" \
        bash "$temp_dir/run.sh" -e dev 2>&1
    )"

    printf '%s\n' "$output"

    if [[ -f "$command_log" ]]; then
        cat "$command_log"
    fi
}

repair_output="$(run_case 1)"
assert_contains "$repair_output" "repair frontend/node_modules"
assert_contains "$repair_output" "compose -f docker-compose-development.yml pull"

skip_output="$(run_case 0)"
assert_not_contains "$skip_output" "repair frontend/node_modules"
assert_contains "$skip_output" "Skipping frontend/node_modules ownership repair."

non_interactive_output="$(
    temp_dir="$(mktemp -d)"
    command_log="$temp_dir/commands.log"

    mkdir -p "$temp_dir/internal" "$temp_dir/frontend/node_modules"
    cp "$ROOT_DIR/run.sh" "$temp_dir/run.sh"
    chmod +x "$temp_dir/run.sh"
    touch "$temp_dir/docker-compose-development.yml"

    cat > "$temp_dir/internal/container-runtime.sh" <<'EOF'
#!/bin/bash

init_container_runtime() {
    HOST_CONTAINER_RUNTIME='podman'
    HOST_COMPOSE_COMMAND='podman-compose'
}

frontend_node_modules_needs_permission_repair() {
    return 0
}

repair_frontend_node_modules_permissions() {
    printf 'repair %s\n' "$1" >> "$COMMAND_LOG"
}

run_host_compose() {
    printf 'compose %s\n' "$*" >> "$COMMAND_LOG"
}

run_host_container_cli() {
    return 0
}
EOF

    COMMAND_LOG="$command_log" \
    bash "$temp_dir/run.sh" -e dev < /dev/null 2>&1
)"

assert_contains "$non_interactive_output" "Non-interactive session detected. Re-run with WPRINT3D_REOWN_FRONTEND_NODE_MODULES=1 to repair ownership automatically."

echo "run.sh frontend permission repair checks passed"
