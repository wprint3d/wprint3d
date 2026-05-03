#!/bin/bash

set -e; # quit on error

install_dependencies() {
    echo '=> Installing packages with NPM...';

    if pnpm i --force --loglevel verbose; then
        return 0;
    fi;

    echo 'PNPM install failed. Clearing local frontend dependencies and retrying...';

    rm -rf /app/node_modules /app/.pnpm-store;

    pnpm i --force --loglevel verbose;
}

start() {
    install_dependencies;

    echo '=> Starting the server in developer mode...';

    export EXPO_UNSTABLE_ATLAS=true;

    pnpm exec expo start --clear;

    return $?;
}

start;

if [ $? -ne 0 ]; then
    echo "Couldn't start the server, please check the logs above.";
fi;

exit 1;
