#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

if [[ -e "$ROOT_DIR/yv-streamer-software/Dockerfile" ]]; then
    echo 'yv-streamer-software is an external image and must not have a local Dockerfile.' >&2
    exit 1
fi

if rg -n 'yv-streamer-software' \
    "$ROOT_DIR/docker-bake.hcl" \
    "$ROOT_DIR/.github/workflows/docker-image.yml" > /dev/null; then
    echo 'The core image workflow must not publish the externally managed yv-streamer image.' >&2
    exit 1
fi

echo 'yv-streamer external workflow ownership checks passed'
