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

run_case() {
    local developer_mode="$1"
    local temp_dir output_file

    temp_dir="$(mktemp -d)"
    output_file="$temp_dir/opcache.ini"

    TEMPLATE_FILE="$ROOT_DIR/internal/php-opcache.ini.template" \
    OUTPUT_FILE="$output_file" \
    ROLE='server,scheduler,concurrency-scheduler,ws-server' \
    DEVELOPER_MODE="$developer_mode" \
    WPRINT3D_OPCACHE_REVALIDATE_FREQ=2 \
    WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    bash "$ROOT_DIR/internal/setup-php-opcache.sh" >/dev/null

    cat "$output_file"
    rm -rf "$temp_dir"
}

development_output="$(run_case true)"
assert_contains "$development_output" 'opcache.enable_cli=0'

production_output="$(run_case false)"
assert_contains "$production_output" 'opcache.enable_cli=1'

echo "php-opcache dev cli config checks passed"
