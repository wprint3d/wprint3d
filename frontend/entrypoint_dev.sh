#!/bin/bash

set -e; # quit on error

install_dependencies() {
    echo '=> Installing packages with NPM...';
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