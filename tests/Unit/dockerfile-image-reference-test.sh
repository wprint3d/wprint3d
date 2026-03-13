#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

assert_not_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Did not expect output to contain: $needle" >&2
        echo "Actual output:" >&2
        echo "$haystack" >&2

        exit 1
    fi
}

dockerfile_refs="$(rg -n "^(FROM|COPY --from=)" "$ROOT_DIR"/Dockerfile.dev "$ROOT_DIR"/Dockerfile.proxy)"

assert_not_contains "$dockerfile_refs" 'FROM php:'
assert_not_contains "$dockerfile_refs" 'FROM nginx:'
assert_not_contains "$dockerfile_refs" 'COPY --from=docker:'
assert_not_contains "$dockerfile_refs" 'COPY --from=composer:'

echo "dockerfile image reference checks passed"
