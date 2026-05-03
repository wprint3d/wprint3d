#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
RAMDISK_SCRIPT="$(cat "$ROOT_DIR/internal/ramdisk-setup.sh")"

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" != *"$needle"* ]]; then
        echo "Expected ${description} to contain: ${needle}" >&2
        exit 1
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local description="$3"

    if [[ "$haystack" == *"$needle"* ]]; then
        echo "Expected ${description} not to contain: ${needle}" >&2
        exit 1
    fi
}

assert_contains "$RAMDISK_SCRIPT" 'du -sB1' 'ramdisk size measurement'
assert_not_contains "$RAMDISK_SCRIPT" 'du -sb' 'ramdisk size measurement'
assert_contains "$RAMDISK_SCRIPT" 'WPRINT3D_RAMDISK_HEADROOM_PERCENT:-50' 'ramdisk headroom default'
assert_contains "$RAMDISK_SCRIPT" 'data_size_bytes * headroom_percent / 100' 'ramdisk headroom calculation'
assert_contains "$RAMDISK_SCRIPT" 'rsync -a "${exclude_args[@]}" "$APP_ROOT/" "$RAMDISK_MOUNT/" || return 1' 'rsync failure handling'
assert_contains "$RAMDISK_SCRIPT" 'tar -C "$APP_ROOT" "${tar_excludes[@]}" -cf - . | tar -C "$RAMDISK_MOUNT" -xf - || return 1' 'tar failure handling'

echo 'ramdisk sizing checks passed'
