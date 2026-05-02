#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TEMP_DIR"' EXIT

FAKE_BIN="$TEMP_DIR/bin"
LOG_FILE="$TEMP_DIR/commands.log"
SOCKET_PATH="$TEMP_DIR/docker.sock"
RUNNER_DIR="$TEMP_DIR/runner"

mkdir -p "$FAKE_BIN"
mkdir -p "$RUNNER_DIR"
ln -s /run/podman/podman.sock "$SOCKET_PATH"
cp "$ROOT_DIR/run.sh" "$RUNNER_DIR/run.sh"
cat > "$RUNNER_DIR/docker-compose.yml" <<'INNER'
services: {}
INNER

cat > "$FAKE_BIN/docker" <<'INNER'
#!/bin/bash
set -euo pipefail
printf 'docker %s\n' "$*" >> "$TEST_LOG_FILE"

if [[ "$1" == '--version' ]]; then
    printf 'Docker version 29.4.2, build test\n'
    exit 0
fi

if [[ "$1" == 'info' ]]; then
    if [[ -e "$TEST_SOCKET_PATH" || -L "$TEST_SOCKET_PATH" ]]; then
        printf 'still pointing at stale socket\n' >&2
        exit 1
    fi

    exit 0
fi

if [[ "$1" == 'compose' && "$2" == 'version' ]]; then
    exit 0
fi

exit 0
INNER

cat > "$FAKE_BIN/sudo" <<'INNER'
#!/bin/bash
set -euo pipefail
printf 'sudo %s\n' "$*" >> "$TEST_LOG_FILE"

if [[ "$1" == '-n' && "$2" == 'true' ]]; then
    exit 0
fi

exec "$@"
INNER

cat > "$FAKE_BIN/systemctl" <<'INNER'
#!/bin/bash
set -euo pipefail
printf 'systemctl %s\n' "$*" >> "$TEST_LOG_FILE"

if [[ "$1" == 'cat' ]]; then
    exit 1
fi

exit 0
INNER

chmod +x "$FAKE_BIN/docker" "$FAKE_BIN/sudo" "$FAKE_BIN/systemctl"

output="$(PATH="$FAKE_BIN:$PATH" \
    TEST_LOG_FILE="$LOG_FILE" \
    TEST_SOCKET_PATH="$SOCKET_PATH" \
    WPRINT3D_DOCKER_SOCKET_PATHS="$SOCKET_PATH" \
    "$RUNNER_DIR/run.sh" --help dev 2>&1)"

if [[ -e "$SOCKET_PATH" || -L "$SOCKET_PATH" ]]; then
    echo 'Expected run.sh to remove the stale Podman-backed Docker socket symlink.' >&2
    exit 1
fi

if [[ "$output" != *"Removing stale Podman-backed Docker socket symlink"* ]]; then
    echo 'Expected run.sh output to mention stale socket cleanup.' >&2
    echo '--- output ---' >&2
    printf '%s\n' "$output" >&2
    exit 1
fi

if ! grep -Fq "sudo rm -f $SOCKET_PATH" "$LOG_FILE"; then
    echo 'Expected run.sh to remove the stale socket through sudo.' >&2
    cat "$LOG_FILE" >&2
    exit 1
fi

if ! grep -Fq 'systemctl restart docker' "$LOG_FILE"; then
    echo 'Expected run.sh to restart Docker after stale socket cleanup.' >&2
    cat "$LOG_FILE" >&2
    exit 1
fi

echo 'run.sh stale Podman Docker socket cleanup checks passed'
