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
        echo "Expected output not to contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

cleanup_output="$(
    ROOT_DIR="$ROOT_DIR" /bin/bash -c '
        set -euo pipefail

        source "$ROOT_DIR/internal/container-runtime.sh"

        HOST_CONTAINER_RUNTIME="podman"
        COMPOSE_PROJECT_NAME="wprint3d-core"
        COMMAND_LOG="$(mktemp)"

        sleep() {
            :
        }

        run_host_container_cli() {
            echo "$*" >> "$COMMAND_LOG"

            if [[ "$1" == "container" ]] && [[ "$2" == "prune" ]] && [[ "$3" == "-f" ]]; then
                return 0
            fi

            if [[ "$1" == "kill" ]]; then
                return 0
            fi

            if [[ "$1" == "rm" ]] && [[ "$2" == "-f" ]]; then
                return 0
            fi

            if [[ "$1" == "ps" ]] && [[ "$2" == "-a" ]]; then
                case "$*" in
                    *"label=com.docker.compose.project=wprint3d-core"* )
                        if [[ "$*" == *"{{ .ID }}|{{ .Names }}"* ]]; then
                            printf "abc123|api\nxyz789|worker\n"
                            return 0
                        fi

                        if [[ "$*" == *"{{ .ID }}"* ]]; then
                            printf "abc123\nxyz789\n"
                            return 0
                        fi
                        ;;
                    *"--format {{ .ID }}"* )
                        printf "abc123\nxyz789\norphan456\n"
                        return 0
                        ;;
                esac
            fi

            return 0
        }

        force_cleanup_stuck_containers

        cat "$COMMAND_LOG"
    ' 2>&1
)"

assert_contains "$cleanup_output" "Attempting nuclear cleanup (removing all containers)..."
assert_contains "$cleanup_output" "rm -f orphan456"
assert_not_contains "$cleanup_output" "rm -f --all"

echo "container-runtime nuclear cleanup checks passed"
