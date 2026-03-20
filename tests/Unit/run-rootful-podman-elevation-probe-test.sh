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

probe_output="$(
    TEMP_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_DIR/commands.log"
    TEMP_BIN_DIR="$TEMP_DIR/bin"

    mkdir -p "$TEMP_DIR/internal" "$TEMP_BIN_DIR"
    cp "$ROOT_DIR/run.sh" "$TEMP_DIR/run.sh"
    chmod +x "$TEMP_DIR/run.sh"

    cat > "$TEMP_DIR/internal/container-runtime.sh" <<'EOF'
#!/bin/bash

podman_rootful_enabled() {
    return 0
}

prime_elevated_access() {
    printf 'prime %s\n' "$*" >> "$COMMAND_LOG"
}
EOF

    cat > "$TEMP_BIN_DIR/podman" <<'EOF'
#!/bin/bash
exit 0
EOF

    chmod +x "$TEMP_BIN_DIR/podman"

    PATH="$TEMP_BIN_DIR:$PATH" \
    COMMAND_LOG="$COMMAND_LOG" \
    WPRINT3D_RUN_SH_SOURCE_ONLY=1 \
    bash -c '
        source "$1"
        prepare_startup_elevation
        cat "$2"
    ' _ "$TEMP_DIR/run.sh" "$COMMAND_LOG"
)"

assert_contains "$probe_output" "prime podman info --format {{.Host.Security.Rootless}}"

echo "run.sh rootful podman elevation probe checks passed"
