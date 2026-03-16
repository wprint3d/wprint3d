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

provider_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/podman" <<'EOF'
#!/bin/bash

if [[ "$1" == "compose" ]] && [[ "$2" == "version" ]]; then
    printf '>>>> Executing external compose provider "/usr/libexec/docker/cli-plugins/docker-compose". Please refer to the documentation for details. <<<<\n'
    printf 'Docker Compose version v5.0.1\n'
    exit 0
fi

if [[ "$1" == "info" ]]; then
    printf '/run/user/1000/podman/podman.sock\n'
    exit 0
fi

exit 0
EOF

    cat > "$TEMP_BIN_DIR/apt-get" <<'EOF'
#!/bin/bash

echo "apt-get $*" >> "$COMMAND_LOG"

if [[ "$1" == "install" ]]; then
    /bin/cat > "$TEMP_BIN_DIR/podman-compose" <<'INNER'
#!/bin/bash

if [[ "$1" == "version" ]]; then
    printf 'podman-compose version 1.0.6\n'
fi

exit 0
INNER

    /bin/chmod +x "$TEMP_BIN_DIR/podman-compose"
fi
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

exit 0
EOF

    chmod +x \
        "$TEMP_BIN_DIR/podman" \
        "$TEMP_BIN_DIR/apt-get" \
        "$TEMP_BIN_DIR/sudo" \
        "$TEMP_BIN_DIR/systemctl"

    PATH="$TEMP_BIN_DIR" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    TEMP_BIN_DIR="$TEMP_BIN_DIR" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        DETECTED_HOST_COMPOSE_COMMAND=''
        detect_host_compose_command podman

        printf "provider=%s\n" "$DETECTED_HOST_COMPOSE_COMMAND"
        [[ -f "$COMMAND_LOG" ]] && /bin/cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$provider_output" "provider=podman-compose"
assert_contains "$provider_output" "apt-get install -y podman-compose"

echo "container-runtime compose-provider checks passed"
