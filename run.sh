#!/bin/bash

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

docker compose build;
docker compose up -d;