#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
run_source="$(cat "$ROOT_DIR/run.sh")"

assert_contains() {
    local needle="$1"
    local description="$2"

    if [[ "$run_source" != *"$needle"* ]]; then
        echo "Expected run.sh to contain ${description}: ${needle}" >&2
        exit 1
    fi
}

assert_contains 'drain_existing_queue_master' 'the queue drain helper'
assert_contains 'supervisorctl stop efficient-queues' 'a blocking Supervisor stop'
assert_contains 'Legacy queue workers detected.' 'the first-deployment safety path'
assert_contains 'supervisorctl stop refresh-printer-workers' 'legacy worker regeneration shutdown'
assert_contains 'supervisorctl stop "$legacy_worker"' 'legacy worker shutdown after active prints drain'
assert_contains 'refusing to recreate it without a verified drain' 'fail-closed Supervisor inspection'

echo 'efficient queue deployment drain checks passed'
