#!/bin/bash

set -euo pipefail

SCRIPT_PATH="$( cd -- "$(dirname "$0")/.." >/dev/null 2>&1 ; pwd -P )"
ENVIRONMENT="${1:-production}"
MONGO_VOLUME_NAME='mongo'
BUSYBOX_IMAGE="${WPRINT3D_MIGRATION_IMAGE:-docker.io/library/busybox:1.36}"
MIGRATION_LABEL='wprint3d.migrated-from-podman'
SOURCE_LABEL='wprint3d.podman-source-volume'

log() {
    printf '%s\n' "$*"
}

command_exists() {
    command -v "$1" > /dev/null 2>&1
}

normalize_compose_project_name() {
    local project="$1"

    project="${project,,}"

    while [[ -n "$project" ]] && [[ "${project:0:1}" =~ [^a-z0-9] ]]; do
        project="${project:1}"
    done

    project="${project//[^a-z0-9_-]/}"

    printf '%s\n' "$project"
}

compose_project_name() {
    local project="${COMPOSE_PROJECT_NAME:-}"

    if [[ -z "$project" ]]; then
        project="$(basename "$SCRIPT_PATH")"
    fi

    normalize_compose_project_name "$project"
}

volume_candidates() {
    local project="$1"
    local candidate
    local candidates=(
        "${WPRINT3D_PODMAN_MONGO_VOLUME:-}"
        "${WPRINT3D_PODMAN_PROJECT_NAME:-}"
        "$project"
        'wprint3d'
        'wprint3d-core'
    )

    for candidate in "${candidates[@]}"; do
        [[ -n "$candidate" ]] || continue

        if [[ "$candidate" == *"_${MONGO_VOLUME_NAME}" ]]; then
            normalize_compose_project_name "$candidate"
        else
            printf '%s_%s\n' "$(normalize_compose_project_name "$candidate")" "$MONGO_VOLUME_NAME"
        fi
    done | awk 'NF && !seen[$0]++'
}

sudo_available_without_prompt() {
    command_exists sudo && sudo -n true > /dev/null 2>&1
}

prime_sudo_if_interactive() {
    command_exists sudo || return 1

    if sudo -n true > /dev/null 2>&1; then
        return 0
    fi

    if [[ -t 0 ]]; then
        log 'Administrator privileges are required to read rootful Podman volumes. sudo may prompt for your password.' >&2
        sudo -v

        return $?
    fi

    return 1
}

podman_volume_exists_with() {
    local podman_prefix="$1"
    local volume="$2"

    if [[ "$podman_prefix" == 'sudo' ]]; then
        sudo podman volume inspect "$volume" > /dev/null 2>&1
    else
        podman volume inspect "$volume" > /dev/null 2>&1
    fi
}

find_podman_volume() {
    local project="$1"
    local candidate

    command_exists podman || return 1

    # Previous WPrint 3D Podman deployments used rootful Podman by default, so
    # prefer sudo podman volumes. Rootless is only a fallback, or the explicit
    # mode when WPRINT3D_PODMAN_ROOTFUL=0 is set.
    if [[ "${WPRINT3D_PODMAN_ROOTFUL:-1}" != '0' ]] && prime_sudo_if_interactive; then
        while IFS= read -r candidate; do
            [[ -n "$candidate" ]] || continue

            if podman_volume_exists_with 'sudo' "$candidate"; then
                printf '%s|%s\n' 'sudo' "$candidate"
                return 0
            fi
        done < <(volume_candidates "$project")
    fi

    while IFS= read -r candidate; do
        [[ -n "$candidate" ]] || continue

        if podman_volume_exists_with '' "$candidate"; then
            printf '%s|%s\n' '' "$candidate"
            return 0
        fi
    done < <(volume_candidates "$project")

    return 1
}

docker_volume_exists() {
    local volume="$1"

    docker volume inspect "$volume" > /dev/null 2>&1
}

docker_volume_has_migration_label() {
    local volume="$1"
    local label_value

    label_value="$(docker volume inspect --format '{{ index .Labels "'"${MIGRATION_LABEL}"'" }}' "$volume" 2> /dev/null || true)"

    [[ "$label_value" == 'true' ]]
}

release_existing_docker_volume() {
    local compose_file='docker-compose.yml'

    if [[ "$ENVIRONMENT" == 'dev' ]]; then
        compose_file='docker-compose-development.yml'
    fi

    if [[ -f "${SCRIPT_PATH}/${compose_file}" ]]; then
        docker compose -f "${SCRIPT_PATH}/${compose_file}" down > /dev/null 2>&1 || true
    fi
}

remove_docker_volume_if_exists() {
    local volume="$1"

    if ! docker_volume_exists "$volume"; then
        return 0
    fi

    log "Docker volume '${volume}' already exists; replacing it with Podman MongoDB data."
    release_existing_docker_volume

    if ! docker volume rm -f "$volume" > /dev/null; then
        log "Failed to remove Docker volume '${volume}'. Stop containers using it and retry." >&2
        return 1
    fi
}

create_docker_volume() {
    local destination_volume="$1"
    local source_volume="$2"

    docker volume create \
        --label "${MIGRATION_LABEL}=true" \
        --label "${SOURCE_LABEL}=${source_volume}" \
        "$destination_volume" > /dev/null
}

copy_volume_data() {
    local podman_prefix="$1"
    local source_volume="$2"
    local destination_volume="$3"

    if [[ "$podman_prefix" == 'sudo' ]]; then
        sudo podman run --rm \
            -v "${source_volume}:/src:ro" \
            "$BUSYBOX_IMAGE" \
            tar -cC /src . \
            | docker run --rm -i \
                -v "${destination_volume}:/dst" \
                "$BUSYBOX_IMAGE" \
                tar -xC /dst

        return $?
    fi

    podman run --rm \
        -v "${source_volume}:/src:ro" \
        "$BUSYBOX_IMAGE" \
        tar -cC /src . \
        | docker run --rm -i \
            -v "${destination_volume}:/dst" \
            "$BUSYBOX_IMAGE" \
            tar -xC /dst
}

main() {
    if [[ "${WPRINT3D_SKIP_PODMAN_MONGO_MIGRATION:-0}" == '1' ]]; then
        log 'Skipping Podman MongoDB volume migration because WPRINT3D_SKIP_PODMAN_MONGO_MIGRATION=1.'
        return 0
    fi

    if ! command_exists docker; then
        log 'Docker is not available; skipping Podman MongoDB volume migration.' >&2
        return 0
    fi

    if ! command_exists podman; then
        log 'Podman is not available; skipping Podman MongoDB volume migration.'
        return 0
    fi

    local project
    local destination_volume
    local source_info
    local podman_prefix
    local source_volume

    project="$(compose_project_name)"
    destination_volume="${WPRINT3D_DOCKER_MONGO_VOLUME:-${project}_${MONGO_VOLUME_NAME}}"

    if docker_volume_exists "$destination_volume" \
        && docker_volume_has_migration_label "$destination_volume" \
        && [[ "${WPRINT3D_FORCE_PODMAN_MONGO_MIGRATION:-0}" != '1' ]]; then
        log "Docker MongoDB volume '${destination_volume}' was already migrated from Podman; skipping."
        return 0
    fi

    source_info="$(find_podman_volume "$project" || true)"

    if [[ -z "$source_info" ]]; then
        log 'No matching Podman MongoDB volume found; skipping migration.'
        return 0
    fi

    podman_prefix="${source_info%%|*}"
    source_volume="${source_info#*|}"

    log "Migrating Podman MongoDB volume '${source_volume}' to Docker volume '${destination_volume}'..."

    remove_docker_volume_if_exists "$destination_volume"
    create_docker_volume "$destination_volume" "$source_volume"

    if ! copy_volume_data "$podman_prefix" "$source_volume" "$destination_volume"; then
        docker volume rm -f "$destination_volume" > /dev/null 2>&1 || true
        log "Failed to migrate Podman MongoDB volume '${source_volume}' to Docker volume '${destination_volume}'." >&2
        return 1
    fi

    log "Migrated Podman MongoDB volume '${source_volume}' to Docker volume '${destination_volume}'."
}

main "$@"
