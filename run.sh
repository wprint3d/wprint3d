#!/bin/bash

# Avoid running on an unrelated path, get absolute path to the script.
#
# For more information, please see https://stackoverflow.com/a/4774063.
SCRIPT_PATH="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )";

cd "$SCRIPT_PATH";

source "${SCRIPT_PATH}/internal/container-runtime.sh";

normalize_compose_logging_driver() {
    local compose_file="${1:-docker-compose.yml}";

    if [[ ! -f "$compose_file" ]]; then
        return 0;
    fi;

    sed -i 's/driver: local/driver: ${CONTAINER_LOG_DRIVER:-local}/g' "$compose_file";
}

offer_frontend_node_modules_reownership() {
    local node_modules_path='frontend/node_modules';
    local reown_choice="${WPRINT3D_REOWN_FRONTEND_NODE_MODULES:-ask}";

    if ! frontend_node_modules_needs_permission_repair "$node_modules_path"; then
        return 0;
    fi;

    echo 'Detected frontend/node_modules files that are not owned by the current user.';
    echo 'This commonly happens after switching from Docker to Podman.';

    case "$reown_choice" in
        1|true|yes)
            repair_frontend_node_modules_permissions "$node_modules_path" || return 1;

            return 0;
            ;;
        0|false|no)
            echo 'Skipping frontend/node_modules ownership repair.';

            return 0;
            ;;
    esac;

    if [[ -t 0 ]]; then
        read -r -p "Re-own frontend/node_modules to $(id -un):$(id -gn) before continuing? [y/N] " reown_choice;

        case "$reown_choice" in
            y|Y|yes|YES)
                repair_frontend_node_modules_permissions "$node_modules_path" || return 1;
                ;;
            *)
                echo 'Skipping frontend/node_modules ownership repair.';
                ;;
        esac;

        return 0;
    fi;

    echo 'Non-interactive session detected. Re-run with WPRINT3D_REOWN_FRONTEND_NODE_MODULES=1 to repair ownership automatically.' >&2;
}

ensure_podman_development_ports_supported() {
    local rootless='false';
    local unprivileged_port_start='1024';

    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        return 0;
    fi;

    if [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        return 0;
    fi;

    if run_host_container_cli info --format '{{.Host.Security.Rootless}}' 2> /dev/null | grep -qx 'true'; then
        rootless='true';
    fi;

    if [[ "$rootless" != 'true' ]]; then
        return 0;
    fi;

    if [[ -r /proc/sys/net/ipv4/ip_unprivileged_port_start ]]; then
        unprivileged_port_start="$(cat /proc/sys/net/ipv4/ip_unprivileged_port_start)";
    fi;

    if [[ "$unprivileged_port_start" =~ ^[0-9]+$ ]] && [[ "$unprivileged_port_start" -gt 80 ]]; then
        echo 'Rootless Podman cannot bind the development stack to ports 80/443 on this host.' >&2;
        echo "The current net.ipv4.ip_unprivileged_port_start value is ${unprivileged_port_start}." >&2;
        echo 'Use rootful Podman, lower net.ipv4.ip_unprivileged_port_start to 80 or less, or restore high host ports for the proxy service.' >&2;

        return 1;
    fi;
}

prepare_startup_elevation() {
    local needs_elevation='false';

    if podman_rootful_enabled; then
        needs_elevation='true';
    fi;

    if [[ -r /proc/sys/fs/inotify/max_user_watches ]] && [[ $(cat /proc/sys/fs/inotify/max_user_watches) -lt 65536 ]]; then
        needs_elevation='true';
    fi;

    if [[ "$needs_elevation" != 'true' ]]; then
        return 0;
    fi;

    prime_elevated_access;
}

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

        normalize_compose_logging_driver 'docker-compose.yml';

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

prepare_startup_elevation || exit 1;

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
    printf '%s\n' fs.inotify.max_user_watches=65536 | run_with_elevation tee -a /etc/sysctl.conf > /dev/null && run_with_elevation sysctl -p;
fi;

if [[ "$ENV" == 'dev' ]]; then
    if [[ ! -d 'frontend' ]]; then
        echo 'The frontend directory is missing. Restore it from git and try again.';

        exit 1;
    fi;

    init_container_runtime || exit 1;
    offer_frontend_node_modules_reownership || exit 1;
    ensure_podman_development_ports_supported || exit 1;
    run_host_compose -f docker-compose-development.yml pull || exit 1;

    if [[ "$NO_BUILD" != 1 ]]; then
        if [[ "$HOST_COMPOSE_COMMAND" == 'podman-compose' ]]; then
            run_host_compose -f docker-compose-development.yml build || exit 1;
        else
            run_host_compose -f docker-compose-development.yml build --progress plain || exit 1;
        fi;
    fi;
elif [[ "$ENV" == 'production' ]]; then
    init_container_runtime || exit 1;

    run_host_compose pull || exit 1;
fi;

if [[ "$HOST_CONTAINER_RUNTIME" == 'docker' ]]; then
    for container_name in $(run_host_container_cli ps --format '{{ .Names }}'  | grep buildx_buildkit_builder); do
        run_host_container_cli stop "$container_name";
    done;
fi;

if [[ "$ENV" == 'dev' ]]; then
    echo 'Starting development environment...';

    if [[ -f 'docker-compose.override.yml' ]]; then
        run_host_compose -f docker-compose-development.yml -f docker-compose.override.yml up -d --remove-orphans;
    else
        run_host_compose -f docker-compose-development.yml up -d --remove-orphans;
    fi;
elif [[ "$ENV" == 'production' ]]; then
    echo 'Starting production environment...';

    run_host_compose up -d --remove-orphans;
fi;
