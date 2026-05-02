#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

if rg -n '<<[\x27"]?EOF' "$ROOT_DIR/Dockerfile" "$ROOT_DIR/Dockerfile.dev" > /dev/null; then
    echo 'Dockerfile-plus frontend does not support Dockerfile heredocs in included files' >&2
    exit 1
fi

if ! rg -n -F "printf '%s\\n'" "$ROOT_DIR/Dockerfile.dev" > /dev/null; then
    echo 'Expected Dockerfile.dev to write debian.sources using printf for Dockerfile-plus compatibility' >&2
    exit 1
fi

if ! rg -n -F '> /etc/apt/sources.list.d/debian.sources' "$ROOT_DIR/Dockerfile.dev" > /dev/null; then
    echo 'Expected Dockerfile.dev to write /etc/apt/sources.list.d/debian.sources' >&2
    exit 1
fi

echo "dockerfile-plus compatibility checks passed"
