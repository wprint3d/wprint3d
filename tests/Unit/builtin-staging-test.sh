#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT_DIR/scripts/stage-builtin-plugin.sh"

[[ -x "$SCRIPT" ]] || {
    echo "Built-in staging script must be executable: $SCRIPT" >&2
    exit 1
}
bash -n "$SCRIPT"

script_contents="$(<"$SCRIPT")"
for required in \
    '--expected-plugin-id' \
    '--expected-version' \
    '--expected-sha256' \
    'plugin:verify' \
    '--require-trusted' \
    '--compatibility-record' \
    'schemaVersion' \
    'minimumWPrintCoreVersion' \
    'linux/amd64' \
    'linux/arm64' \
    'sha256sum' \
    'sha256sum --check --status' \
    'committed=0' \
    'rollback()' \
    'STAGE_INVENTORY_PART' \
    'mv -f "$inventory_part" "$inventory_path"'; do
    if [[ "$script_contents" != *"$required"* ]]; then
        echo "Built-in staging script is missing required gate: $required" >&2
        exit 1
    fi
done

if [[ "$script_contents" == *'latest'* ]]; then
    echo 'Built-in staging must not use a mutable latest reference.' >&2
    exit 1
fi

echo 'built-in staging checks passed'
