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

pkexec_output="$(
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

if [[ "$1" == */env ]]; then
    shift
    if [[ "$1" == PATH=* ]]; then
        export PATH="${1#PATH=}"
        shift
    fi
fi

exec "$@"
EOF

    cat > "$TEMP_BIN_DIR/apt-get" <<'EOF'
#!/bin/bash

echo "apt-get $*" >> "$COMMAND_LOG"

if [[ "$1" == "install" ]]; then
    /bin/cat > "$TEMP_BIN_DIR/podman-compose" <<'INNER'
#!/bin/bash

exit 0
INNER

    /bin/chmod +x "$TEMP_BIN_DIR/podman-compose"
fi
EOF

    chmod +x \
        "$TEMP_BIN_DIR/podman" \
        "$TEMP_BIN_DIR/sudo" \
        "$TEMP_BIN_DIR/pkexec" \
        "$TEMP_BIN_DIR/apt-get"

    PATH="$TEMP_BIN_DIR" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    TEMP_BIN_DIR="$TEMP_BIN_DIR" \
    DISPLAY=:1 \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        DETECTED_HOST_COMPOSE_COMMAND=""
        detect_host_compose_command podman

        printf "provider=%s\n" "$DETECTED_HOST_COMPOSE_COMMAND"
        [[ -f "$COMMAND_LOG" ]] && /bin/cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$pkexec_output" "Requesting administrator privileges through pkexec"
assert_contains "$pkexec_output" "provider=podman-compose"
assert_contains "$pkexec_output" "pkexec "
assert_contains "$pkexec_output" "apt-get install -y podman-compose"

echo "container-runtime pkexec fallback checks passed"
