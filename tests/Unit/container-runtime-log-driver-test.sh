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

podman_output="$(
    ROOT_DIR="$ROOT_DIR" WPRINT3D_PODMAN_ROOTFUL=0 /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        detect_host_container_runtime() {
            DETECTED_HOST_CONTAINER_RUNTIME="podman"
        }

        detect_host_compose_command() {
            DETECTED_HOST_COMPOSE_COMMAND="podman-compose"
        }

        ensure_podman_socket() {
            return 0
        }

        init_container_runtime

        printf "log_driver=%s\n" "$CONTAINER_LOG_DRIVER"
    '
)"

docker_output="$(
    ROOT_DIR="$ROOT_DIR" /bin/bash -c '
        source "$ROOT_DIR/internal/container-runtime.sh"

        detect_host_container_runtime() {
            DETECTED_HOST_CONTAINER_RUNTIME="docker"
        }

        detect_host_compose_command() {
            DETECTED_HOST_COMPOSE_COMMAND="docker compose"
        }

        init_container_runtime

        printf "log_driver=%s\n" "$CONTAINER_LOG_DRIVER"
    '
)"

assert_contains "$podman_output" "log_driver=k8s-file"
assert_contains "$docker_output" "log_driver=local"

echo "container-runtime log-driver checks passed"
