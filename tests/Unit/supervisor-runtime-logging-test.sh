#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"
TMP_RUNTIME="$(mktemp -d)"

cleanup() {
    rm -rf "$TMP_RUNTIME"
    rm -f /tmp/supervisor/cron.conf \
          /tmp/supervisor/poll-serial-connections.conf \
          /tmp/supervisor/reconcile-active-prints.conf \
          /tmp/supervisor/efficient-queues.conf \
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
            /tmp/supervisor/reconcile-active-prints.conf \
            /tmp/supervisor/efficient-queues.conf \
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
reconcile_contents="$(cat /tmp/supervisor/reconcile-active-prints.conf)"
queue_contents="$(cat /tmp/supervisor/efficient-queues.conf)"

assert_contains "$poll_contents" 'command=php artisan concurrent:run-indefinitely --services=PollSerialConnections' 'poll serial supervisor command'
assert_contains "$reconcile_contents" 'command=php artisan concurrent:run-indefinitely --services=ReconcileActivePrints' 'active print reconciler supervisor command'
assert_contains "$queue_contents" 'command=php /var/www/artisan queue:cow-work redis --sleep=5 --timeout=0' 'efficient queue supervisor command'
assert_contains "$queue_contents" 'numprocs=1' 'efficient queue process count'
assert_contains "$queue_contents" 'stopsignal=TERM' 'efficient queue stop signal'
assert_contains "$queue_contents" 'stopasgroup=true' 'efficient queue stop group'
assert_contains "$queue_contents" 'killasgroup=true' 'efficient queue kill group'
assert_contains "$queue_contents" 'stopwaitsecs=2147483647' 'efficient queue unlimited drain wait'
assert_contains "$poll_contents" 'stdout_logfile='"$TMP_RUNTIME"'/supervisor/poll-serial-connections.log' 'poll serial supervisor log'
assert_contains "$reconcile_contents" 'stdout_logfile='"$TMP_RUNTIME"'/supervisor/reconcile-active-prints.log' 'active print reconciler supervisor log'
assert_contains "$queue_contents" 'stdout_logfile='"$TMP_RUNTIME"'/supervisor/efficient-queues.log' 'efficient queue supervisor log'

run_source="$(cat "$ROOT_DIR/internal/run.sh")"

assert_contains "$run_source" 'setupRuntimeLogs' 'runtime log setup'
assert_contains "$run_source" 'find "$runtime_log_dir" -type f -size +512k' 'runtime log truncator'
assert_contains "$run_source" 'exec supervisord -c /var/www/internal/supervisor/supervisord.conf' 'supervisor exec handoff'

echo 'supervisor runtime logging checks passed'
