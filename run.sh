#!/bin/bash

# Avoid running on an unrelated path, get absolute path to the script.
#
# For more information, please see https://stackoverflow.com/a/4774063.
SCRIPT_PATH="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )";

cd "$SCRIPT_PATH";

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

if [[ ! -d 'bin' ]]; then
    printf 'Creating prebuilts storage... ';

    mkdir bin;

    if [ $? -eq 0 ]; then
        printf 'OK\n';
    else
        exit 1;
    fi;
fi;

if [[ ! -f 'bin/wait-for-it' ]]; then
    printf 'Installing dependency: wait-for-it... ';

    curl -s https://raw.githubusercontent.com/vishnubob/wait-for-it/master/wait-for-it.sh -o bin/wait-for-it &&\
         chmod +x bin/wait-for-it;

    if [ $? -eq 0 ]; then
        printf 'OK\n';
    else
        exit 1;
    fi;
fi;

export PATH="$PATH":$(pwd)/bin;

# If there's less than 65536 file watchers allowed, increase it to 65536.
if [[ $(cat /proc/sys/fs/inotify/max_user_watches) -lt 65536 ]]; then
    echo fs.inotify.max_user_watches=65536 | sudo tee -a /etc/sysctl.conf && sudo sysctl -p;
fi;

# If on a RPi, check if memory-limiting cgroups are enabled.
if [[ ! -e /sys/fs/cgroup/memory.stat ]] && [[ -e /boot/cmdline.txt ]]; then
    echo "Your kernel doesn't have the memory-limiting cgroups feature enabled. Try opening the /boot/cmdline.txt file and then, add the following text at the end of the line:";
    echo '';
    echo 'cgroup_enable=memory cgroup_memory=1 swapaccount=1';
    echo '';
    echo "Once you're done with that, reboot your Raspberry Pi and try again.";

    exit 1;
fi;

# If on a RPi, check if we're missing the firmware modules to support the
# camera. If they're missing, prepare them by copying everything to a local
# path.
if [[ -e /opt/vc ]]; then
    echo 'Copying camera firmware...';

    cp -rfv /opt/vc ./internal/vc;
fi;

if [[ "$ENV" == 'dev' ]]; then
    docker compose -f docker-compose-development.yml pull;
    docker compose -f docker-compose-development.yml build --progress plain;
elif [[ "$ENV" == 'production' ]]; then
    docker compose pull;
fi;

for container_name in $(docker ps --format '{{ .Names }}'  | grep buildx_buildkit_builder); do
    docker stop "$container_name";
done;

if [[ "$ENV" == 'dev' ]]; then
    docker compose -f docker-compose-development.yml up -d;
elif [[ "$ENV" == 'production' ]]; then
    docker compose up -d;
fi;