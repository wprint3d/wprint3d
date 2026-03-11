#!/bin/bash

set -e; # quit on error

start() {
    echo '=> Starting the server in production mode...';
    lighttpd -D -f /etc/lighttpd/lighttpd.conf;

    return $?;
}

start;

if [ $? -ne 0 ]; then
    echo "Couldn't start the server, please check the logs above.";
fi;

exit 1;