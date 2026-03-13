#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$( cd -- "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )"
PROJECT_ROOT="$( cd -- "${SCRIPT_DIR}/../.." >/dev/null 2>&1 ; pwd -P )"

cd "$PROJECT_ROOT"

if rg -n 'configure_podman_development_ports|find_available_host_port|host_port_is_listening' run.sh > /dev/null; then
    echo 'run.sh still contains custom development port selection logic.'
    exit 1
fi

if ! rg -n '\- 80:80' docker-compose-development.yml > /dev/null; then
    echo 'Development compose file no longer binds proxy HTTP to port 80.'
    exit 1
fi

if ! rg -n '\- 443:443' docker-compose-development.yml > /dev/null; then
    echo 'Development compose file no longer binds proxy HTTPS to port 443.'
    exit 1
fi

if ! rg -n '\- 6001:6001' docker-compose-development.yml > /dev/null; then
    echo 'Development compose file no longer binds the socket port to 6001.'
    exit 1
fi

if ! rg -n '\- 8081:8081' docker-compose-development.yml > /dev/null; then
    echo 'Development compose file no longer binds the frontend to port 8081.'
    exit 1
fi

if ! rg -n '\- 27017:27017' docker-compose-development.yml > /dev/null; then
    echo 'Development compose file no longer binds MongoDB to port 27017.'
    exit 1
fi
