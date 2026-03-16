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
    local rootless="$1"
    local threshold="$2"
    local rootful_setting="${3:-1}"
    local temp_dir command_log output

    temp_dir="$(mktemp -d)"
    command_log="$temp_dir/commands.log"

    mkdir -p "$temp_dir/internal" "$temp_dir/frontend" "$temp_dir/proc/sys/net/ipv4"
    cp "$ROOT_DIR/run.sh" "$temp_dir/run.sh"
    chmod +x "$temp_dir/run.sh"
    touch "$temp_dir/docker-compose-development.yml"
    printf '%s\n' "$threshold" > "$temp_dir/proc/sys/net/ipv4/ip_unprivileged_port_start"

    cat > "$temp_dir/internal/container-runtime.sh" <<'EOF'
#!/bin/bash

init_container_runtime() {
    HOST_CONTAINER_RUNTIME='podman'
    HOST_COMPOSE_COMMAND='podman-compose'
    HOST_PODMAN_ROOTFUL="${MOCK_HOST_PODMAN_ROOTFUL:-0}"
}

frontend_node_modules_needs_permission_repair() {
    return 1
}

run_host_container_cli() {
    if [[ "$1" == "info" ]] && [[ "$2" == "--format" ]]; then
        printf '%s\n' "$MOCK_ROOTLESS"
        return 0
    fi

    return 0
}

run_host_compose() {
    printf 'compose %s\n' "$*" >> "$COMMAND_LOG"
}
EOF

    mkdir -p "$temp_dir/bin"

    cat > "$temp_dir/bin/cat" <<'EOF'
#!/bin/bash

if [[ "$1" == "/proc/sys/net/ipv4/ip_unprivileged_port_start" ]]; then
    exec /bin/cat "$MOCK_UNPRIVILEGED_PORT_FILE"
fi

exec /bin/cat "$@"
EOF

    chmod +x "$temp_dir/bin/cat"

    output="$(
        PATH="$temp_dir/bin:$PATH" \
        COMMAND_LOG="$command_log" \
        MOCK_ROOTLESS="$rootless" \
        MOCK_HOST_PODMAN_ROOTFUL="$rootful_setting" \
        MOCK_UNPRIVILEGED_PORT_FILE="$temp_dir/proc/sys/net/ipv4/ip_unprivileged_port_start" \
        WPRINT3D_PODMAN_ROOTFUL="$rootful_setting" \
        bash "$temp_dir/run.sh" -e dev 2>&1 || true
    )"

    printf '%s\n' "$output"

    if [[ -f "$command_log" ]]; then
        cat "$command_log"
    fi
}

blocked_output="$(run_case true 1024 0)"
assert_contains "$blocked_output" "Rootless Podman cannot bind the development stack to ports 80/443 on this host."
assert_not_contains "$blocked_output" "compose -f docker-compose-development.yml pull"

allowed_output="$(run_case true 80 0)"
assert_contains "$allowed_output" "compose -f docker-compose-development.yml pull"

rootful_output="$(run_case false 1024 1)"
assert_contains "$rootful_output" "compose -f docker-compose-development.yml pull"

echo "run.sh rootless podman port checks passed"
