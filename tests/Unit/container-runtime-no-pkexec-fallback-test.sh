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

no_pkexec_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/podman" <<'EOF'
#!/bin/bash

if [[ "$1" == "compose" ]] && [[ "$2" == "version" ]]; then
    printf '>>>> Executing external compose provider "/usr/libexec/docker/cli-plugins/docker-compose". Please refer to the documentation for details. <<<<\n'
    printf 'Docker Compose version v5.0.1\n'
    exit 0
fi

exit 0
EOF

    cat > "$TEMP_BIN_DIR/sudo" <<'EOF'
#!/bin/bash

if [[ "$1" == "-n" ]] && [[ "$2" == "true" ]]; then
    exit 1
fi

exit 1
EOF

    cat > "$TEMP_BIN_DIR/pkexec" <<'EOF'
#!/bin/bash

echo "pkexec $*" >> "$COMMAND_LOG"
exit 0
EOF

    cat > "$TEMP_BIN_DIR/apt-get" <<'EOF'
#!/bin/bash

exit 0
EOF

    chmod +x \
        "$TEMP_BIN_DIR/podman" \
        "$TEMP_BIN_DIR/sudo" \
        "$TEMP_BIN_DIR/pkexec" \
        "$TEMP_BIN_DIR/apt-get"

    PATH="$TEMP_BIN_DIR" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    DISPLAY=:1 \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        DETECTED_HOST_COMPOSE_COMMAND=""
        detect_host_compose_command podman || true

        [[ -f "$COMMAND_LOG" ]] && /bin/cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$no_pkexec_output" "Detected a Docker-backed external compose provider"
assert_contains "$no_pkexec_output" "Automatic Podman setup needs sudo access, but no interactive terminal is available for a password prompt."
assert_not_contains "$no_pkexec_output" "pkexec "

echo "container-runtime no-pkexec fallback checks passed"
