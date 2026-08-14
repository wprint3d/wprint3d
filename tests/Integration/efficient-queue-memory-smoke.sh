#!/bin/bash

set -euo pipefail

if [[ "${DB_DATABASE:-}" != wprint3d_codex_* ]] || [[ "${REDIS_DB:-}" != '15' ]]; then
    echo 'This smoke test requires an isolated wprint3d_codex_* MongoDB database and Redis database 15.' >&2
    exit 1
fi

queues=(default recordings broadcasts prints previews snapshots)
official_pids=()
cow_pid=''

cleanup() {
    if [[ -n "$cow_pid" ]] && kill -0 "$cow_pid" 2> /dev/null; then
        kill -TERM "$cow_pid" 2> /dev/null || true
        wait "$cow_pid" 2> /dev/null || true
    fi

    for pid in "${official_pids[@]}"; do
        kill -TERM "$pid" 2> /dev/null || true
    done
    wait "${official_pids[@]}" 2> /dev/null || true
    php artisan tinker --execute='Illuminate\Support\Facades\Redis::connection()->flushdb();' > /dev/null 2>&1 || true
}

pss_kb() {
    awk '/^Pss:/ { print $2; exit }' "/proc/$1/smaps_rollup"
}

sum_pss() {
    local total=0
    local pid

    for pid in "$@"; do
        total=$((total + $(pss_kb "$pid")))
    done

    printf '%s\n' "$total"
}

wait_for_cow_layout() {
    for _ in $(seq 1 120); do
        read -r -a children <<< "$(cat "/proc/$cow_pid/task/$cow_pid/children")"
        if [[ "${#children[@]}" -eq 7 ]]; then
            return 0
        fi
        sleep 0.1
    done

    return 1
}

trap cleanup EXIT
php artisan tinker --execute='Illuminate\Support\Facades\Redis::connection()->flushdb();' > /dev/null

for queue in "${queues[@]}"; do
    php artisan queue:work redis --queue="$queue" --sleep=5 --timeout=0 > "/tmp/official-${queue}.log" 2>&1 &
    official_pids+=("$!")
done

sleep 5
for pid in "${official_pids[@]}"; do
    kill -0 "$pid"
done
official_pss="$(sum_pss "${official_pids[@]}")"

for pid in "${official_pids[@]}"; do
    kill -TERM "$pid"
done
wait "${official_pids[@]}" || true
official_pids=()

php artisan queue:cow-work redis --sleep=5 --timeout=0 --json > /tmp/cow-memory.log &
cow_pid=$!
wait_for_cow_layout
sleep 5
read -r -a cow_children <<< "$(cat "/proc/$cow_pid/task/$cow_pid/children")"
cow_pss="$(sum_pss "$cow_pid" "${cow_children[@]}")"

kill -TERM "$cow_pid"
wait "$cow_pid"
cow_pid=''

printf 'Official aggregate PSS: %s KiB\n' "$official_pss"
printf 'Efficient Queues aggregate PSS: %s KiB\n' "$cow_pss"

if [[ "$cow_pss" -ge "$official_pss" ]]; then
    echo 'Efficient Queues did not reduce aggregate PSS.' >&2
    exit 1
fi
