#!/bin/bash

# Avoid running on an unrelated path, get absolute path to the script.
#
# For more information, please see https://stackoverflow.com/a/4774063.
SCRIPT_PATH="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )";

cd "$SCRIPT_PATH";

docker_is_podman_wrapper() {
    local version_output

    version_output="$(docker --version 2>/dev/null || true)"

    [[ "${version_output,,}" == *podman* ]]
}

run_as_root() {
    if [[ "${EUID:-1}" -eq 0 ]]; then
        "$@"

        return $?
    fi

    if command -v sudo > /dev/null 2>&1; then
        if sudo -n true > /dev/null 2>&1; then
            sudo "$@"

            return $?
        fi

        if { exec 3<> /dev/tty; } 2> /dev/null; then
            echo 'Administrator privileges are required to clean up stale Podman runtime state. sudo will prompt for your password.' >&3

            sudo -v <&3 >&3 || {
                exec 3<&-
                exec 3>&-

                return 1
            }

            sudo "$@" <&3

            local sudo_exit_code=$?

            exec 3<&-
            exec 3>&-

            return $sudo_exit_code
        fi

        echo "sudo access is required to run '${*}', but no interactive terminal is available for a password prompt." >&2

        return 1
    fi

    if command -v doas > /dev/null 2>&1; then
        doas "$@"

        return $?
    fi

    echo "Root privileges are required to run: $*" >&2

    return 1
}
docker_socket_points_to_podman() {
    local socket_path="$1"
    local link_target=''
    local resolved_target=''

    [[ -L "$socket_path" ]] || return 1

    link_target="$(readlink "$socket_path" 2>/dev/null || true)"
    resolved_target="$(readlink -f "$socket_path" 2>/dev/null || true)"

    [[ "${link_target,,}" == *podman* || "${resolved_target,,}" == *podman* ]]
}

docker_socket_path_is_invalid() {
    local socket_path="$1"

    [[ -e "$socket_path" && ! -S "$socket_path" && ! -L "$socket_path" ]]
}

cleanup_stale_podman_docker_socket() {
    local socket_paths="${WPRINT3D_DOCKER_SOCKET_PATHS:-/var/run/docker.sock /run/docker.sock}"
    local socket_path
    local found_stale_socket=0
    local stopped_docker_socket=0

    for socket_path in $socket_paths; do
        if docker_socket_points_to_podman "$socket_path"; then
            found_stale_socket=1

            echo "Removing stale Podman-backed Docker socket symlink at ${socket_path}..." >&2

            if [[ "$stopped_docker_socket" -eq 0 ]] && command -v systemctl > /dev/null 2>&1; then
                run_as_root systemctl stop docker.service docker.socket > /dev/null 2>&1 || true
                stopped_docker_socket=1
            fi

            run_as_root rm -f "$socket_path" || return 1
        fi

        if docker_socket_path_is_invalid "$socket_path"; then
            found_stale_socket=1

            echo "Removing invalid Docker socket path at ${socket_path}..." >&2

            if [[ "$stopped_docker_socket" -eq 0 ]] && command -v systemctl > /dev/null 2>&1; then
                run_as_root systemctl stop docker.service docker.socket > /dev/null 2>&1 || true
                stopped_docker_socket=1
            fi

            run_as_root rm -rf "$socket_path" || return 1
        fi
    done

    if [[ "$found_stale_socket" -eq 0 ]]; then
        return 0
    fi

    if command -v systemctl > /dev/null 2>&1; then
        run_as_root systemctl start docker.socket > /dev/null 2>&1 || true
        run_as_root systemctl restart docker > /dev/null 2>&1 || run_as_root systemctl start docker > /dev/null 2>&1 || return 1
    elif command -v service > /dev/null 2>&1; then
        run_as_root service docker restart > /dev/null 2>&1 || run_as_root service docker start > /dev/null 2>&1 || return 1
    fi
}

cleanup_stale_podman_wprint3d_service() {
    local unit_contents=''

    command -v systemctl > /dev/null 2>&1 || return 0

    unit_contents="$(systemctl cat wprint3d.service 2>/dev/null || true)"

    if [[ "${unit_contents,,}" != *podman* ]]; then
        return 0
    fi

    echo 'Disabling stale WPrint 3D Podman systemd service...' >&2

    run_as_root systemctl disable --now wprint3d.service > /dev/null 2>&1 || true
    run_as_root rm -f /etc/systemd/system/wprint3d.service /etc/systemd/system/multi-user.target.wants/wprint3d.service > /dev/null 2>&1 || true
    run_as_root systemctl daemon-reload > /dev/null 2>&1 || true
}

ensure_docker_runtime() {
    local docker_info_output

    if ! command -v docker > /dev/null 2>&1 || ! docker --version > /dev/null 2>&1; then
        echo 'Docker is not installed. Please install Docker Engine and try again.' >&2

        return 1
    fi

    if docker_is_podman_wrapper; then
        echo "The 'docker' command on this host is still the Podman compatibility wrapper." >&2
        echo 'Remove podman-docker/the local Docker wrapper and install Docker Engine, then run this script again.' >&2

        return 1
    fi

    cleanup_stale_podman_wprint3d_service
    cleanup_stale_podman_docker_socket || {
        echo 'Failed to clean up the stale Podman Docker socket. Remove /var/run/docker.sock if it points to Podman, restart Docker, and try again.' >&2

        return 1
    }

    if ! docker_info_output="$(docker info 2>&1)"; then
        echo 'Docker is installed, but the Docker daemon is not reachable by this user.' >&2
        echo "$docker_info_output" >&2
        echo 'Make sure Docker Engine is running and that this shell has access to /var/run/docker.sock.' >&2

        return 1
    fi
}

run_docker_compose() {
    if docker compose version > /dev/null 2>&1; then
        docker compose "$@"

        return $?
    fi

    if command -v docker-compose > /dev/null 2>&1 && docker-compose version > /dev/null 2>&1; then
        docker-compose "$@"

        return $?
    fi

    echo 'Docker Compose is not available to the Docker CLI. Install the Docker Compose plugin or docker-compose, then try again.' >&2

    return 1
}

run_docker_compose_up() {
    local output_file
    local compose_args=("$@")
    local compose_prefix=()
    local arg
    local status
    local pipefail_was_enabled=0

    output_file="$(mktemp --suffix=-wprint3d-compose-up)"

    if set -o | grep -q '^pipefail[[:space:]]*on'; then
        pipefail_was_enabled=1
    fi

    set -o pipefail
    run_docker_compose "${compose_args[@]}" 2>&1 | tee "$output_file"
    status="$?"

    if [[ "$pipefail_was_enabled" -eq 0 ]]; then
        set +o pipefail
    fi

    if [[ "$status" -eq 0 ]]; then
        rm -f "$output_file"

        return 0
    fi

    if ! grep -q 'AlreadyExists: task' "$output_file"; then
        rm -f "$output_file"

        return "$status"
    fi

    rm -f "$output_file"

    for arg in "${compose_args[@]}"; do
        if [[ "$arg" == 'up' ]]; then
            break
        fi

        compose_prefix+=("$arg")
    done

    echo 'Docker reported a stale containerd task from a previous crash; recreating the compose stack and retrying...' >&2

    run_docker_compose "${compose_prefix[@]}" down --remove-orphans || return 1
    run_docker_compose "${compose_args[@]}"
}

ensure_docker_runtime || exit 1;
run_docker_compose version > /dev/null || exit 1;

if [[ "$2" != 'dev' ]]; then
    if [[ ! -f 'docker-compose.yml' ]] || grep -q 'wprint3d' 'docker-compose.yml' || [[ ! -s 'docker-compose.yml' ]]; then
        if [[ ! -f 'docker-compose.yml' ]]; then
            echo 'The docker-compose.yml file is missing, downloading it...';
        else
            echo 'Updating the docker-compose.yml file...';
        fi;

        TEMP_FILE=$(mktemp --suffix=-wprint3d-docker-compose);

        DEFAULT_BRANCH=$(curl -sfL -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28" https://api.github.com/repos/wprint3d/wprint3d-core | grep default | sed 's/.*: "//' | sed 's/".*//');

        if [[ "$DEFAULT_BRANCH" == '' ]]; then
            echo 'Failed to get the default branch.';

            exit 1;
        fi;

        curl -fL https://raw.githubusercontent.com/wprint3d/wprint3d/${DEFAULT_BRANCH}/docker-compose.yml > "$TEMP_FILE";

        if [[ ! -f "$TEMP_FILE" ]] || [[ ! -s "$TEMP_FILE" ]]; then
            if [[ -f 'docker-compose.yml' ]]; then
                echo 'Failed to download the new docker-compose.yml file.';
            else
                echo 'Failed to download the docker-compose.yml file.';

                exit 1;
            fi;
        fi;

        if [[ -f 'docker-compose.yml' ]]; then
            mv -v 'docker-compose.yml' 'docker-compose.yml.bak';

            echo 'The old docker-compose.yml file was renamed to docker-compose.yml.bak.';
        fi;

        mv -v "$TEMP_FILE" 'docker-compose.yml';

        echo 'The docker-compose.yml file was updated.';

        if [[ -f 'docker-compose.yml.bak' ]]; then
            echo 'You can remove the old docker-compose.yml file by running: rm docker-compose.yml.bak';
        fi;
    elif ! grep -q 'wprint3d' 'docker-compose.yml'; then
        echo 'The docker-compose.yml file present is not compatible with this project, please create a new directory, cd into it and run this script again.';

        exit 1;
    fi;
fi;

ENV='production';

if [[ "$1" == '-h' ]] || [[ "$1" == '--help' ]]; then
    printf 'Usage \n\n'"$0"' [-e dev | --environment dev] (builds and runs the image locally)\n';

    exit 0;
elif ([[ "$1" == '-e' ]] || [[ "$1" == '--environment' ]]); then
    case "$2" in
        dev)
            ENV='dev';

            ;;
        *)
            if [[ "$2" == '' ]]; then
                printf 'The environment cannot be empty.\n';
            else
                printf 'Invalid environment "'"$2"'".\n';
            fi;

            exit 1;

            ;;
    esac;
fi;

if ([[ "$ENV" == 'dev' ]] && ([[ "$3" == '-n' ]] || [[ "$3" == '--no-build' ]])); then
    NO_BUILD=1;
fi;

if [[ ! -d 'bin' ]]; then
    printf 'Creating prebuilts storage... ';

    mkdir bin;

    if [ $? -eq 0 ]; then
        printf 'OK\n';
    else
        exit 1;
    fi;
fi;

# If there's less than 65536 file watchers allowed, increase it to 65536.
if [[ $(cat /proc/sys/fs/inotify/max_user_watches) -lt 65536 ]]; then
    echo fs.inotify.max_user_watches=65536 | sudo tee -a /etc/sysctl.conf && sudo sysctl -p;
fi;

# If this host previously ran WPrint 3D with Podman, copy its MongoDB
# data volume back into Docker before Docker Compose starts MongoDB.
if [[ -x "${SCRIPT_PATH}/internal/migrate-podman-mongo-volume-to-docker.sh" ]]; then
    "${SCRIPT_PATH}/internal/migrate-podman-mongo-volume-to-docker.sh" "$ENV" || exit 1;
fi;

if [[ "$ENV" == 'dev' ]]; then
    if [[ ! -d 'frontend' ]]; then
        echo 'The frontend directory is missing. Restore it from git and try again.';

        exit 1;
    fi;

    run_docker_compose -f docker-compose-development.yml pull || exit 1;

    if [[ "$NO_BUILD" != 1 ]]; then
        run_docker_compose -f docker-compose-development.yml build --progress plain || exit 1;
    fi;
elif [[ "$ENV" == 'production' ]]; then
    run_docker_compose pull || exit 1;
fi;

for container_name in $(docker ps --format '{{ .Names }}'  | grep buildx_buildkit_builder); do
    docker stop "$container_name";
done;

if [[ "$ENV" == 'dev' ]]; then
    echo 'Starting development environment...';

    if [[ -f 'docker-compose.override.yml' ]]; then
        run_docker_compose_up -f docker-compose-development.yml -f docker-compose.override.yml up -d --remove-orphans;
    else
        run_docker_compose_up -f docker-compose-development.yml up -d --remove-orphans;
    fi;
elif [[ "$ENV" == 'production' ]]; then
    echo 'Starting production environment...';

    run_docker_compose_up up -d --remove-orphans;
fi;
