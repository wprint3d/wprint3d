#!/bin/bash

# Avoid running on an unrelated path, get absolute path to the script.
#
# For more information, please see https://stackoverflow.com/a/4774063.
SCRIPT_PATH="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )";

cd "$SCRIPT_PATH";

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

if [[ "$ENV" == 'dev' ]]; then
    if [[ ! -d 'frontend' ]]; then
        echo 'Cloning frontend repository...';

        git clone https://github.com/wprint3d/wprint3d-frontend frontend;

        if [ $? -ne 0 ]; then
            echo 'Failed to clone the frontend repository.';

            exit 1;
        fi;
    fi;

    docker compose -f docker-compose-development.yml pull || exit 1;

    if [[ "$NO_BUILD" != 1 ]]; then
        docker compose -f docker-compose-development.yml build --progress plain || exit 1;
    fi;
elif [[ "$ENV" == 'production' ]]; then
    docker compose pull || exit 1;
fi;

for container_name in $(docker ps --format '{{ .Names }}'  | grep buildx_buildkit_builder); do
    docker stop "$container_name";
done;

if [[ "$ENV" == 'dev' ]]; then
    echo 'Starting development environment...';

    if [[ -f 'docker-compose.override.yml' ]]; then
        docker compose -f docker-compose-development.yml -f docker-compose.override.yml up -d --remove-orphans;
    else
        docker compose -f docker-compose-development.yml up -d --remove-orphans;
    fi;
elif [[ "$ENV" == 'production' ]]; then
    echo 'Starting production environment...';

    docker compose up -d --remove-orphans;
fi;