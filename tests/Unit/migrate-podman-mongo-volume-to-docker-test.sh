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
    printf 'fake-tar-stream'
    exit 0
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

if [[ "$1" == 'compose' ]]; then
    exit 0
fi

if [[ "$1" == 'volume' && "$2" == 'rm' ]]; then
    [[ "$3" == '-f' && "$4" == 'wprint3d-core_mongo' ]]
    exit $?
fi

if [[ "$1" == 'volume' && "$2" == 'create' ]]; then
    [[ "${*: -1}" == 'wprint3d-core_mongo' ]]
    exit $?
fi

if [[ "$1" == 'run' ]]; then
    cat > /dev/null
    exit 0
fi

exit 1
INNER

chmod +x "$FAKE_BIN/sudo" "$FAKE_BIN/podman" "$FAKE_BIN/docker"

PATH="$FAKE_BIN:$PATH" TEST_LOG_FILE="$LOG_FILE" \
    "$ROOT_DIR/internal/migrate-podman-mongo-volume-to-docker.sh" production

assert_contains() {
    local needle="$1"

    if ! grep -Fq -- "$needle" "$LOG_FILE"; then
        echo "Expected log to contain: $needle" >&2
        echo '--- log ---' >&2
        cat "$LOG_FILE" >&2
        exit 1
    fi
}

assert_contains 'sudo podman volume inspect wprint3d-core_mongo'
assert_contains 'podman volume inspect wprint3d-core_mongo'
assert_contains 'docker volume inspect wprint3d-core_mongo'
assert_contains 'docker compose -f'
assert_contains 'docker volume rm -f wprint3d-core_mongo'
assert_contains 'docker volume create --label wprint3d.migrated-from-podman=true --label wprint3d.podman-source-volume=wprint3d-core_mongo wprint3d-core_mongo'
assert_contains 'sudo podman run --rm -v wprint3d-core_mongo:/src:ro docker.io/library/busybox:1.36 tar -cC /src .'
assert_contains 'podman run --rm -v wprint3d-core_mongo:/src:ro docker.io/library/busybox:1.36 tar -cC /src .'
assert_contains 'docker run --rm -i -v wprint3d-core_mongo:/dst docker.io/library/busybox:1.36 tar -xC /dst'

echo 'podman mongo to docker volume migration checks passed'
