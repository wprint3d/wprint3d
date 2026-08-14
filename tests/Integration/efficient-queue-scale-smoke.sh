#!/bin/bash

set -euo pipefail

if [[ "${DB_DATABASE:-}" != wprint3d_codex_* ]] || [[ "${REDIS_DB:-}" != '15' ]]; then
    echo 'This smoke test requires an isolated wprint3d_codex_* MongoDB database and Redis database 15.' >&2
    exit 1
fi

output_path="$(mktemp)"
started_path='/tmp/efficient-queue-scale-started'
release_path='/tmp/efficient-queue-scale-release'
finished_path='/tmp/efficient-queue-scale-finished'
master_pid=''

cleanup() {
    if [[ -n "$master_pid" ]] && kill -0 "$master_pid" 2> /dev/null; then
        kill -TERM "$master_pid" 2> /dev/null || true
        wait "$master_pid" 2> /dev/null || true
    fi

    php artisan tinker --execute='Illuminate\Support\Facades\Redis::connection()->flushdb();' > /dev/null 2>&1 || true
    rm -f "$output_path" "$started_path" "$release_path" "$finished_path"
}

wait_for_file() {
    local path="$1"

    for _ in $(seq 1 160); do
        [[ -s "$path" ]] && return 0
        sleep 0.1
    done

    return 1
}

wait_for_topology() {
    local workers="$1"

    for _ in $(seq 1 80); do
        if grep 'pool_topology_updated' "$output_path" | grep -q "\"workers\":${workers}"; then
            return 0
        fi
        sleep 0.1
    done

    return 1
}

wait_for_exit() {
    local pid="$1"

    for _ in $(seq 1 80); do
        ! kill -0 "$pid" 2> /dev/null && return 0
        sleep 0.1
    done

    return 1
}

trap cleanup EXIT
rm -f "$started_path" "$release_path" "$finished_path"

php artisan tinker --execute='
    Illuminate\Support\Facades\Redis::connection()->flushdb();
    App\Models\Printer::create([
        "node" => "/dev/codex",
        "hasActiveJob" => true,
        "activeFile" => "codex.gcode",
    ]);
    dispatch((new Tests\Fixtures\BlockingQueueJob(
        "/tmp/efficient-queue-scale-started",
        "/tmp/efficient-queue-scale-release",
        "/tmp/efficient-queue-scale-finished",
    ))->onQueue("codex-scale"));
' > /dev/null

php artisan queue:cow-work redis --sleep=1 --timeout=0 --max-time=30 --json > "$output_path" &
master_pid=$!

wait_for_topology 1
wait_for_file "$started_path"
worker_pid="$(cat "$started_path")"
kill -0 "$master_pid"
kill -0 "$worker_pid"

php artisan tinker --execute='
    App\Models\Printer::query()->update([
        "hasActiveJob" => false,
        "activeFile" => null,
    ]);
' > /dev/null

wait_for_topology 0
sleep 1
kill -0 "$master_pid"
kill -0 "$worker_pid"

touch "$release_path"
wait_for_file "$finished_path"
wait_for_exit "$worker_pid"
kill -0 "$master_pid"

verified_master_pid="$master_pid"
kill -TERM "$master_pid"
wait "$master_pid"
master_pid=''

printf 'Scale-down preserved master PID %s and busy worker PID %s until completion.\n' "$verified_master_pid" "$worker_pid"
grep 'pool_topology_updated\|master_stopped' "$output_path"
