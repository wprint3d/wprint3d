#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

if rg -n '\$\(\(\s*"\$\(nproc --all\)"' "$ROOT_DIR/Dockerfile" > /dev/null; then
    echo 'Found sh-incompatible quoted nproc arithmetic in the consolidated Dockerfile' >&2

    exit 1
fi

echo "dockerfile shell arithmetic checks passed"
