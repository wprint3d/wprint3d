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

no_fallback_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"

    cat > "$TEMP_BIN_DIR/docker" <<'EOF'
#!/bin/bash

exit 0
EOF

    cat > "$TEMP_BIN_DIR/sudo" <<'EOF'
#!/bin/bash

if [[ "$1" == "-n" ]]; then
    exit 1
fi

exit 1
EOF

    cat > "$TEMP_BIN_DIR/apt-get" <<'EOF'
#!/bin/bash

exit 0
EOF

    chmod +x "$TEMP_BIN_DIR/docker" "$TEMP_BIN_DIR/sudo" "$TEMP_BIN_DIR/apt-get"

    PATH="$TEMP_BIN_DIR" \
    ROOT_DIR="$ROOT_DIR" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        detect_host_container_runtime
    ' 2>&1 || true
)"

assert_contains "$no_fallback_output" "Podman is not installed. Attempting automatic installation..."
assert_contains "$no_fallback_output" "Automatic Podman setup needs sudo access, but no interactive terminal is available for a password prompt."
assert_contains "$no_fallback_output" "Podman is unavailable and the automatic installation attempt did not succeed."
assert_not_contains "$no_fallback_output" "docker"

echo "container-runtime no-docker-fallback checks passed"
