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

runtime_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/docker" <<'EOF'
#!/bin/bash

if [[ "$1" == "compose" ]] && [[ "$2" == "version" ]]; then
    exit 0
fi

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

    cat > "$TEMP_BIN_DIR/apt-get" <<'EOF'
#!/bin/bash

echo "apt-get $*" >> "$COMMAND_LOG"

if [[ "$1" == "install" ]]; then
/bin/cat > "$TEMP_BIN_DIR/podman" <<'INNER'
#!/bin/bash

if [[ "$1" == "compose" ]] && [[ "$2" == "version" ]]; then
    exit 0
fi

exit 0
INNER

/bin/cat > "$TEMP_BIN_DIR/podman-compose" <<'INNER'
#!/bin/bash

exit 0
INNER

/bin/chmod +x "$TEMP_BIN_DIR/podman" "$TEMP_BIN_DIR/podman-compose"
fi
EOF

    cat > "$TEMP_BIN_DIR/systemctl" <<'EOF'
#!/bin/bash

echo "systemctl $*" >> "$COMMAND_LOG"
exit 0
EOF

    cat > "$TEMP_BIN_DIR/loginctl" <<'EOF'
#!/bin/bash

echo "loginctl $*" >> "$COMMAND_LOG"
exit 0
EOF

    chmod +x \
        "$TEMP_BIN_DIR/docker" \
        "$TEMP_BIN_DIR/sudo" \
        "$TEMP_BIN_DIR/apt-get" \
        "$TEMP_BIN_DIR/systemctl" \
        "$TEMP_BIN_DIR/loginctl"

    PATH="$TEMP_BIN_DIR" \
    HOME="${HOME}" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    TEMP_BIN_DIR="$TEMP_BIN_DIR" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        ensure_podman_socket() {
            return 0
        }

        init_container_runtime

        printf "runtime=%s\n" "$HOST_CONTAINER_RUNTIME"
        printf "compose=%s\n" "$HOST_COMPOSE_COMMAND"
        if [[ -f "$COMMAND_LOG" ]]; then
            while IFS= read -r line; do
                printf "%s\n" "$line"
            done < "$COMMAND_LOG"
        fi
    ' 2>&1 || true
)"

assert_contains "$runtime_output" "runtime=podman"
assert_contains "$runtime_output" "compose=podman-compose"
assert_contains "$runtime_output" "apt-get update"
assert_contains "$runtime_output" "apt-get install -y podman podman-compose"
assert_contains "$runtime_output" "systemctl --user enable --now podman.socket"

echo "container-runtime install checks passed"
