#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
WORKFLOW_PATH="$ROOT_DIR/yv-streamer-software/.github/workflows/yv-streamer-software-image.yml"

if [[ ! -f "$WORKFLOW_PATH" ]]; then
    echo "Expected workflow file to exist at $WORKFLOW_PATH" >&2
    exit 1
fi

workflow_contents="$(cat "$WORKFLOW_PATH")"

case "$workflow_contents" in
    *"./Dockerfile"* ) ;;
    * )
        echo "Expected workflow to build the local Dockerfile" >&2
        exit 1
        ;;
esac

case "$workflow_contents" in
    *"DOCKER_IMAGE_NAME"* ) ;;
    * )
        echo "Expected workflow to derive tags from DOCKER_IMAGE_NAME" >&2
        exit 1
        ;;
esac

case "$workflow_contents" in
    *"vars.DOCKERHUB_USERNAME || 'wprint3d'"* ) ;;
    * )
        echo "Expected workflow to default the Docker Hub username to wprint3d" >&2
        exit 1
        ;;
esac

case "$workflow_contents" in
    *"secrets.DOCKERHUB_TOKEN"* ) ;;
    * )
        echo "Expected workflow to read the Docker Hub token from secrets.DOCKERHUB_TOKEN" >&2
        exit 1
        ;;
esac

case "$workflow_contents" in
    *"linux/amd64,linux/arm64"* ) ;;
    * )
        echo "Expected workflow to build both linux/amd64 and linux/arm64" >&2
        exit 1
        ;;
esac

echo "yv-streamer workflow checks passed"
