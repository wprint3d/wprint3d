#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TEMP_DIR"' EXIT

FAKE_BIN="$TEMP_DIR/bin"
mkdir -p "$FAKE_BIN"

cat > "$FAKE_BIN/docker" <<'INNER'
#!/bin/bash
set -euo pipefail
if [[ "$1" == '--version' ]]; then
    printf 'podman version 5.0.0\n'
    exit 0
fi
if [[ "$1" == 'compose' && "$2" == 'version' ]]; then
    exit 1
fi
INNER
chmod +x "$FAKE_BIN/docker"

set +e
output="$(PATH="$FAKE_BIN:$PATH" "$ROOT_DIR/run.sh" 2>&1)"
status=$?
set -e

if [[ "$status" -eq 0 ]]; then
    echo 'Expected run.sh to reject a podman-backed docker wrapper.' >&2
    exit 1
fi

if [[ "$output" != *"Podman compatibility wrapper"* ]]; then
    echo 'Expected run.sh output to mention the Podman compatibility wrapper.' >&2
    echo '--- output ---' >&2
    printf '%s\n' "$output" >&2
    exit 1
fi

echo 'run.sh podman-backed docker rejection checks passed'
