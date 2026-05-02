#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TEMP_DIR"' EXIT

FAKE_BIN="$TEMP_DIR/bin"
LOG_FILE="$TEMP_DIR/commands.log"
mkdir -p "$FAKE_BIN"

cat > "$FAKE_BIN/sudo" <<'INNER'
#!/bin/bash
set -euo pipefail
printf 'sudo %s\n' "$*" >> "$TEST_LOG_FILE"

if [[ "$1" == '-n' && "$2" == 'true' ]]; then
    exit 0
fi

exec "$@"
INNER

cat > "$FAKE_BIN/podman" <<'INNER'
#!/bin/bash
set -euo pipefail
printf 'podman %s\n' "$*" >> "$TEST_LOG_FILE"

if [[ "$1" == 'volume' && "$2" == 'inspect' ]]; then
    [[ "$3" == 'wprint3d-core_mongo' ]]
    exit $?
fi

if [[ "$1" == 'run' ]]; then
    if [[ "$*" == *'/data:ro'* ]]; then
        printf '100\n'
        exit 0
    fi

    printf 'unexpected podman copy run\n' >&2
    exit 1
fi

exit 1
INNER

cat > "$FAKE_BIN/docker" <<'INNER'
#!/bin/bash
set -euo pipefail
printf 'docker %s\n' "$*" >> "$TEST_LOG_FILE"

if [[ "$1" == 'volume' && "$2" == 'inspect' ]]; then
    if [[ "$3" == '--format' ]]; then
        printf '\n'
        exit 0
    fi

    [[ "$3" == 'wprint3d-core_mongo' ]]
    exit $?
fi

if [[ "$1" == 'run' ]]; then
    if [[ "$*" == *'/data:ro'* ]]; then
        printf '200\n'
        exit 0
    fi

    printf 'unexpected docker copy run\n' >&2
    exit 1
fi

if [[ "$1" == 'volume' && "$2" =~ ^(rm|create)$ ]]; then
    printf 'docker volume mutation should not happen\n' >&2
    exit 1
fi

exit 0
INNER

chmod +x "$FAKE_BIN/sudo" "$FAKE_BIN/podman" "$FAKE_BIN/docker"

output="$(PATH="$FAKE_BIN:$PATH" TEST_LOG_FILE="$LOG_FILE" \
    "$ROOT_DIR/internal/migrate-podman-mongo-volume-to-docker.sh" production)"

if [[ "$output" != *"has newer data than Podman volume"* ]]; then
    echo 'Expected newer Docker volume message.' >&2
    echo '--- output ---' >&2
    printf '%s\n' "$output" >&2
    echo '--- log ---' >&2
    cat "$LOG_FILE" >&2
    exit 1
fi

if grep -Fq 'docker volume rm' "$LOG_FILE" || grep -Fq 'docker volume create' "$LOG_FILE"; then
    echo 'Docker volume should not be replaced when it is newer than Podman.' >&2
    cat "$LOG_FILE" >&2
    exit 1
fi

echo 'podman mongo migration freshness checks passed'
