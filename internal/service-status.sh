#!/bin/bash

service_status_http_ok() {
    local url="$1"

    curl --silent --show-error --fail --max-time 2 "$url" > /dev/null 2>&1
}

service_status_matches_process_list() {
    local container_id="$1"
    shift

    local process_list
    local grep_args=()
    local pattern

    process_list="$(docker top "$container_id" 2> /dev/null)" || return 1

    for pattern in "$@"; do
        grep_args+=(-e "$pattern")
    done

    printf '%s\n' "$process_list" | tail -n +2 | grep -F "${grep_args[@]}" > /dev/null 2>&1
}

service_status_for_container() {
    local container_id="$1"
    local container_name="$2"

    if [[ "$container_name" == *"proxy"* ]]; then
        service_status_matches_process_list "$container_id" nginx
    elif [[ "$container_name" == *"mongo"* ]]; then
        service_status_matches_process_list "$container_id" mongod
    elif [[ "$container_name" == *"redis"* ]]; then
        service_status_matches_process_list "$container_id" redis-server
    elif [[ "$container_name" == *"memcached"* ]]; then
        service_status_matches_process_list "$container_id" memcached
    elif [[ "$container_name" == *"backend"* ]]; then
        service_status_matches_process_list "$container_id" php
    elif [[ "$container_name" == *"scheduler"* ]] && [[ "$container_name" != *"concurrency"* ]]; then
        service_status_matches_process_list "$container_id" cron
    elif [[ "$container_name" == *"mapper"* ]]; then
        service_status_matches_process_list "$container_id" udevadm || [[ "$(docker inspect --format '{{.State.Status}}' "$container_id" 2> /dev/null)" == 'running' ]]
    elif [[ "$container_name" == *"yv-streamer-software"* ]]; then
        service_status_http_ok 'http://yv-streamer-software:8080/api/v1/health'
    elif [[ "$container_name" == *"streamer"* ]]; then
        service_status_matches_process_list "$container_id" inotifywait
    elif [[ "$container_name" == *"web"* ]]; then
        service_status_http_ok 'http://web:8081'
    else
        service_status_matches_process_list "$container_id" php
    fi
}
