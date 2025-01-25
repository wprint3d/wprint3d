#!/bin/bash

set -e; # quit on error

start() {
    echo '=> Starting the server...';
    pnpm exec expo start --clear;

    return $?;
}

if [[ -e '.upgrade-pending' ]] || [[ ! -e 'node_modules' ]]; then
    echo '=> Updating dependencies...';

    echo '=> Installing packages with NPM...';
    pnpm i --force --loglevel verbose;

    rm -f .upgrade-pending;
fi;

start;

if [ $? -ne 0 ]; then
    echo "Couldn't start the server, (re-)installing dependencies...";

    echo '=> Installing packages with NPM...';
    pnpm i --force --loglevel verbose;
fi;

start;

if [ $? -ne 0 ]; then
    echo "Couldn't start the server, please check the logs above.";
fi;

exit 1;