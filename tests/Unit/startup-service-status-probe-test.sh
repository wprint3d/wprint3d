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
        echo "Expected output to not contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

web_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/docker" <<'EOF'
#!/bin/bash

echo "docker $*" >> "$COMMAND_LOG"

if [[ "$1" == "top" ]]; then
    printf 'PID CMD\n'
    printf '1 inotifywait -m /dev /var/www/internal -e create -e delete -e delete_self\n'
fi

exit 0
EOF

    cat > "$TEMP_BIN_DIR/curl" <<'EOF'
#!/bin/bash

echo "curl $*" >> "$COMMAND_LOG"
exit 0
EOF

    chmod +x "$TEMP_BIN_DIR/docker" "$TEMP_BIN_DIR/curl"

    PATH="$TEMP_BIN_DIR:$PATH" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/service-status.sh"

        if service_status_for_container "web-cid" "wprint3d-web-1"; then
            echo "ready"
        else
            echo "not-ready"
        fi

        cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$web_output" "ready"
assert_contains "$web_output" "curl --silent --show-error --fail --max-time 2 http://web:8081"
assert_not_contains "$web_output" "docker exec web-cid"

yv_streamer_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/docker" <<'EOF'
#!/bin/bash

echo "docker $*" >> "$COMMAND_LOG"

if [[ "$1" == "top" ]]; then
    printf 'PID CMD\n'
    printf '1 inotifywait -m /dev /var/www/internal -e create -e delete -e delete_self\n'
fi

exit 0
EOF

    cat > "$TEMP_BIN_DIR/curl" <<'EOF'
#!/bin/bash

echo "curl $*" >> "$COMMAND_LOG"
exit 0
EOF

    chmod +x "$TEMP_BIN_DIR/docker" "$TEMP_BIN_DIR/curl"

    PATH="$TEMP_BIN_DIR:$PATH" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/service-status.sh"

        if service_status_for_container "yv-cid" "wprint3d-yv-streamer-software-1"; then
            echo "ready"
        else
            echo "not-ready"
        fi

        cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$yv_streamer_output" "ready"
assert_contains "$yv_streamer_output" "curl --silent --show-error --fail --max-time 2 http://yv-streamer-software:8080/api/v1/health"
assert_not_contains "$yv_streamer_output" "inotifywait"

streamer_output="$(
    TEMP_BIN_DIR="$(mktemp -d)"
    COMMAND_LOG="$TEMP_BIN_DIR/commands.log"

    cat > "$TEMP_BIN_DIR/docker" <<'EOF'
#!/bin/bash

echo "docker $*" >> "$COMMAND_LOG"

if [[ "$1" == "top" ]]; then
    printf 'PID CMD\n'
    printf '1 inotifywait -m /dev /var/www/internal -e create -e delete -e delete_self\n'
fi

exit 0
EOF

    cat > "$TEMP_BIN_DIR/curl" <<'EOF'
#!/bin/bash

echo "curl $*" >> "$COMMAND_LOG"
exit 0
EOF

    chmod +x "$TEMP_BIN_DIR/docker" "$TEMP_BIN_DIR/curl"

    PATH="$TEMP_BIN_DIR:$PATH" \
    ROOT_DIR="$ROOT_DIR" \
    COMMAND_LOG="$COMMAND_LOG" \
    /bin/bash -c '
        source "$ROOT_DIR/internal/service-status.sh"

        if service_status_for_container "streamer-cid" "wprint3d-streamer-1"; then
            echo "ready"
        else
            echo "not-ready"
        fi

        cat "$COMMAND_LOG"
    ' 2>&1 || true
)"

assert_contains "$streamer_output" "ready"
assert_contains "$streamer_output" "docker top streamer-cid"
assert_not_contains "$streamer_output" "docker exec streamer-cid"
assert_not_contains "$streamer_output" "http://yv-streamer-software:8080/api/v1/health"

echo "startup service status probe checks passed"
