#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
TMP_RUNTIME="$(mktemp -d)"

cleanup() {
    rm -rf "$TMP_RUNTIME"
    rm -f /tmp/supervisor/cron.conf \
          /tmp/supervisor/poll-serial-connections.conf \
          /tmp/supervisor/refresh-printer-workers.conf \
          /tmp/supervisor/server.conf \
          /tmp/supervisor/reverb.conf
}

trap cleanup EXIT

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

WPRINT3D_RUNTIME_LOG_DIR="$TMP_RUNTIME" \
    bash "$ROOT_DIR/internal/generate-supervisor-configs.sh" \
        scheduler concurrency-scheduler server ws-server >/dev/null

for conf in /tmp/supervisor/cron.conf \
            /tmp/supervisor/poll-serial-connections.conf \
            /tmp/supervisor/refresh-printer-workers.conf \
            /tmp/supervisor/server.conf \
            /tmp/supervisor/reverb.conf; do
    contents="$(cat "$conf")"

    assert_contains "$contents" "stdout_logfile=$TMP_RUNTIME/supervisor/" "$conf runtime log path"
    assert_contains "$contents" 'stdout_logfile_maxbytes=512KB' "$conf max log size"
    assert_contains "$contents" 'stdout_logfile_backups=1' "$conf log backups"
    assert_contains "$contents" 'redirect_stderr=true' "$conf stderr redirection"
    assert_not_contains "$contents" '/tmp/supervisor/logs/' "$conf old supervisor log path"
done

poll_contents="$(cat /tmp/supervisor/poll-serial-connections.conf)"
refresh_contents="$(cat /tmp/supervisor/refresh-printer-workers.conf)"

assert_contains "$poll_contents" 'command=php artisan concurrent:run-indefinitely --services=PollSerialConnections' 'poll serial supervisor command'
assert_contains "$refresh_contents" 'command=php artisan concurrent:run-indefinitely --services=RefreshPrinterWorkers' 'refresh workers supervisor command'
assert_contains "$poll_contents" 'stdout_logfile='"$TMP_RUNTIME"'/supervisor/poll-serial-connections.log' 'poll serial supervisor log'
assert_contains "$refresh_contents" 'stdout_logfile='"$TMP_RUNTIME"'/supervisor/refresh-printer-workers.log' 'refresh workers supervisor log'

refresh_source="$(cat "$ROOT_DIR/app/Console/Services/Concurrent/RefreshPrinterWorkers.php")"

assert_contains "$refresh_source" "env('WPRINT3D_RUNTIME_LOG_DIR'" 'dynamic worker runtime log env'
assert_contains "$refresh_source" 'stdout_logfile_maxbytes=512KB' 'dynamic worker max log size'
assert_contains "$refresh_source" 'stdout_logfile_backups=1' 'dynamic worker log backups'
assert_not_contains "$refresh_source" '/var/www/storage/logs/' 'dynamic worker persistent log path'

run_source="$(cat "$ROOT_DIR/internal/run.sh")"

assert_contains "$run_source" 'setupRuntimeLogs' 'runtime log setup'
assert_contains "$run_source" 'find "$runtime_log_dir" -type f -size +512k' 'runtime log truncator'

echo 'supervisor runtime logging checks passed'
