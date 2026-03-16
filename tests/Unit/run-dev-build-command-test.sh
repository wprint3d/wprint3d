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
    local compose_command="$1"
    local temp_dir command_log output

    temp_dir="$(mktemp -d)"
    command_log="$temp_dir/commands.log"

    mkdir -p "$temp_dir/internal" "$temp_dir/frontend"
    cp "$ROOT_DIR/run.sh" "$temp_dir/run.sh"
    chmod +x "$temp_dir/run.sh"
    touch "$temp_dir/docker-compose-development.yml"

    cat > "$temp_dir/internal/container-runtime.sh" <<'EOF'
#!/bin/bash

init_container_runtime() {
    HOST_CONTAINER_RUNTIME='podman'
    HOST_COMPOSE_COMMAND="${MOCK_HOST_COMPOSE_COMMAND}"
}

run_host_compose() {
    printf '%s\n' "$*" >> "$COMMAND_LOG"
}

run_host_container_cli() {
    return 0
}
EOF

    output="$(
        COMMAND_LOG="$command_log" \
        MOCK_HOST_COMPOSE_COMMAND="$compose_command" \
        bash "$temp_dir/run.sh" -e dev 2>&1
    )"

    printf '%s\n' "$output"
    cat "$command_log"
}

podman_output="$(run_case 'podman-compose')"
assert_contains "$podman_output" "-f docker-compose-development.yml build"
assert_not_contains "$podman_output" "-f docker-compose-development.yml build --progress plain"

docker_output="$(run_case 'docker compose')"
assert_contains "$docker_output" "-f docker-compose-development.yml build --progress plain"

echo "run.sh dev build command checks passed"
