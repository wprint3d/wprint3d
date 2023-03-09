#!/bin/bash

# if [[ -z $ROLE ]]; then
#     echo 'A role was expected. Pass ROLE as an environment variable and try again.';

#     exit 1;
# fi;

export PATH="$PATH":$(pwd)/bin;

wait-for-it mongo:27017;

# TODO: Re-enable Telescope
#
# wait-for-it mariadb:3306;

if [[ -z $ROLE ]]; then
    echo "End of script reached, this container will run as a dummy and, as such, it won't actually do anything.";

    tail -f /dev/null;
else
    while true; do
        touch /var/www/.composer_busy.lock;

        if [[ "$ROLE" != 'server' ]] && [[ -f /var/www/.composer_busy.lock ]]; then
            echo 'Waiting for composer dependencies to become available...';

            while [[ "$ROLE" != 'server' ]] && [[ -f /var/www/.composer_busy.lock ]]; do
                sleep 1;
            done;
        fi;

        if [[ "$ROLE" == 'server' ]]; then
            composer install;

            rm -fv /var/www/.composer_busy.lock;

            echo 'Waiting for Redis to be ready...';

            false; # Force initial return status ($?) to 1

            while [ $? -ne 0 ]; do
                redis-cli -h redis get '' 2>&1 > /dev/null;

                sleep 0.1;
            done;

            # Wait for Vite to become available (no timeout)
            wait-for-it watcher:5173 -t 0;

            # TODO: Re-enable Telescope
            #
            # # Create a database for Laravel Telescope if it doesn't exist
            # mysql \
            #     --host=$(php artisan tinker --execute="print env('TELESCOPE_DB_HOST')"       | tr -d '[:space:]') \
            #     --port=$(php artisan tinker --execute="print env('TELESCOPE_DB_PORT')"       | tr -d '[:space:]') \
            #     --user=$(php artisan tinker --execute="print env('TELESCOPE_DB_USERNAME')"   | tr -d '[:space:]') \
            #     --password="$(php artisan tinker --execute="print env('TELESCOPE_DB_PASSWORD')" | tr -d '[:space:]')" \
            #     --execute="CREATE DATABASE IF NOT EXISTS $(php artisan tinker --execute="print env('TELESCOPE_DB_DATABASE')" | tr -d '[:space:]')";

            # TODO: This is just for development and testing purposes and
            #       should be removed for production.
            php artisan reset:users;
            php artisan create:sample-user;

            php artisan migrate;

            php artisan make:marlin-labels;

            if [ $(php artisan get:env OCTANE_ENABLED) == 'true' ]; then
                echo 'Starting Octane web server...';

                php artisan octane:start --host 0.0.0.0 --port 80 --watch;
            else
                echo 'Starting Artisan web server...';

                php artisan serve        --host 0.0.0.0 --port 80;
            fi;
        elif [[ "$ROLE" == 'queue' ]]; then
            php artisan cache:clear;
            php artisan queue:flush;
            php artisan queue:restart;

            if [[ -z $QUEUE ]]; then
                QUEUE=default;
            fi;

            while true; do
                php artisan queue:work --queue="$QUEUE" --timeout=0;
            done;
        elif [[ "$ROLE" == 'ws-server' ]]; then
            # Wait for Vite to become available (no timeout)
            wait-for-it watcher:5173 -t 0;

            while true; do
                php artisan websockets:serve --host 0.0.0.0 --port 6001;
            done;
        elif [[ "$ROLE" == 'mapper' ]]; then
            # Try to recognize a printer within them before enabling the udev monitor
            php artisan map:serial-printers;
            php artisan map:hardware-cameras;

            IFS=$'\n';

            for camera in $(ls -l /dev/video* | sed 's/  */ /g'); do # this will remove repeated spaces
                MAJOR=$(printf   "$camera" | cut -d ' ' -f 5  | cut -d ',' -f 1);
                MINOR=$(printf   "$camera" | cut -d ' ' -f 6  | cut -d ',' -f 1);
                DEVNAME=$(printf "$camera" | cut -d ' ' -f 10 | cut -d ',' -f 1);

                DEVICE_INDEX=$(printf "$DEVNAME" | sed 's-/dev/video--');

                # find the path in /sys/devices to idProduct and idVendor for the current camera
                SYS_PATH=$(find /sys/devices -name uevent -exec grep -Hr 'video'"$DEVICE_INDEX" {} \; | sed 's/:DEVNAME.*//g' | sed 's/video4linux.*//g')'..';

                VENDOR_PRODUCT=$(cat $SYS_PATH/idVendor)':'$(cat $SYS_PATH/idProduct); # 0c45:64ab

                LABEL=$(lsusb -d "$VENDOR_PRODUCT" | sed 's/  */ /g' | cut -d ':' -f 3 | sed 's/.....//');

                php artisan map:set-hardware-camera-label "$DEVICE_INDEX" "$LABEL"
            done;

            while true; do
                deviceChanged=0;
                isPollingPrinters=0;

                # monitor for kernel-ring udev events
                udevadm monitor -p | \
                    while read line; do
                        if   [[ "$line" == 'KERNEL'* ]] && [[ "$line" == *'tty'* ]]; then
                            printf  'New event detected: '"$line"'\nMapping variables...\n';
                        elif [[ "$MINOR" != '' ]]; then
                            deviceChanged=1;

                            echo 'DEVNAME: '"$DEVNAME";

                            if [[ "$DEVNAME" != *'bus'* ]]; then # we don't care about exposing anything about the raw bus
                                nodePath=/dev/$(printf "$DEVNAME" | sed 's-/dev/--');

                                if [[ "$ACTION" == 'add' ]]      && [[ ! -e "$nodePath" ]]; then
                                    # mknod -m 0777 "$nodePath" c "$MAJOR" "$MINOR";

                                    echo "$nodePath: character device created with major $MAJOR and minor $MINOR.";
                                elif [[ "$ACTION" == 'remove' ]] && [[ -e "$nodePath"   ]]; then
                                    # rm -f "$nodePath";

                                    echo "$nodePath: character device removed.";
                                fi;
                            fi;

                            MINOR='';
                        elif [[ "$line" == *'='* ]] && [[ "$line" != *' '* ]] && [[ "$line" != *'('* ]] && [[ "$line" != *')'* ]]; then # is a kernel variable
                            printf '  '"$line"'\n';

                            eval "$line";
                        else
                            printf '\n';
                        fi;

                        if [[ $deviceChanged -eq 1 ]]; then
                            if [[ "$DEVNAME" != '' ]] && ([[ "$nodePath" == *'tty'* ]] || [[ "$nodePath" == '*video'* ]]) && ([[ "$ACTION" == 'add' ]] || [[ "$ACTION" == 'remove' ]]); then
                                php artisan map:serial-printers;
                                php artisan map:hardware-cameras;

                                if [[ "$DEVNAME" == *'video'* ]]; then
                                    VENDOR_PRODUCT=$(printf "$PRODUCT" | sed 's/\//:/' | sed 's/\/.*//'); # c45:64ab

                                    LABEL=$(lsusb -d "$VENDOR_PRODUCT" | sed 's/  */ /g' | cut -d ':' -f 3 | sed 's/.....//');

                                    echo "$VENDOR_PRODUCT"': '"$LABEL";

                                    php artisan map:set-hardware-camera-label "$DEVICE_INDEX" "$LABEL"
                                fi;
                            fi;

                            deviceChanged=0;
                        fi;
                    done;
            done;
        elif [[ "$ROLE" == 'scheduler' ]]; then
            if [[ "$KIND" == 'short' ]]; then
                while true; do
                    php artisan short-schedule:run;
                done;
            else
                while true; do
                    php artisan schedule:run;

                    sleep 60;
                done;
            fi;
        elif [[ "$ROLE" == 'watcher' ]]; then
            php artisan down;

            npm i;

            npm run build;

            php artisan up;

            npm run dev;
        elif [[ "$ROLE" == 'streamer' ]]; then
            getFreePort() {
                port=$PORT_SCAN_START;

                maxPort=$PORT_SCAN_END;

                while [[ $(ps -fax | grep mjpg_streamer | grep "$port" 2>&1 > /dev/null ; printf $?) -eq 0 ]]; do
                    port=$(( $port + 1 ));

                    if [[ $port -gt $maxPort ]]; then
                        port='';

                        break;
                    fi;
                done;

                printf $port;
            }

            if [[ ! -e '/var/www/proxy/internal/cameras.conf' ]]; then
                truncate --size 0 /var/www/proxy/internal/cameras.conf;
            fi;

            truncate --size 0 /tmp/cameras.conf;

            while true; do
                truncate --size 0 /tmp/cameras.conf;

                for device in $(ls /dev/video*); do
                    INFO=$(php artisan get:hardware-camera $(printf "$device" | sed 's-/dev/video--'));

                    DEVICE_INDEX=$(printf "$device" | sed 's-/dev/video--');

                    if [[ "$INFO" != 'null' ]]; then
                        IFS=$'\n';

                        for var in "$INFO"; do
                            eval "$var";
                        done;

                        echo "Refreshing device at node $device with ID $_ID";

                        if [[ "$ENABLED" -eq 1 ]] && [[ -e "$device" ]]; then
                            ps -fax | grep mjpg_streamer | grep $device 2>&1 > /dev/null;

                            if [[ $? -eq 1 ]]; then # not yet started
                                port=$(getFreePort);

                                if [[ "$port" == '' ]]; then
                                    echo "Unable to allocate port: no ports are currently available.";
                                else
                                    # php artisan map:set-hardware-camera-port "$DEVICE_INDEX" "$port";

                                    mjpg_streamer \
                                        -i "input_uvc.so -d ${device} -r ${RESOLUTION} -f ${FRAMERATE}" \
                                        -o "output_http.so -p ${port}" &
                                fi;
                            else
                                port=$(ps -fax | grep mjpg_streamer | grep "$device" | sed 's/.*-p //' | sed 's/ //g');
                            fi;

                            if [[ "$port" != '' ]]; then
                                printf "\nlocation /video/${DEVICE_INDEX} {"                                >> /tmp/cameras.conf;
                                printf "\n\tproxy_pass            http://streamer:${port}/?action=stream;"  >> /tmp/cameras.conf;
                                printf "\n\tproxy_set_header Host \$host;"                                  >> /tmp/cameras.conf;
                                printf "\n\tinclude               nginxconfig.io/proxy.conf;"               >> /tmp/cameras.conf;
                                printf "\n}"                                                                >> /tmp/cameras.conf;
                            fi;
                        else # camera removed or bus error, kill and de-allocate resources
                            pid=$(ps -fax | grep mjpg_streamer | sed 's/^[ \t]*//' | cut -d ' ' -f 1 | grep "$device");

                            if [[ "$pid" != '' ]]; then
                                kill $pid;
                            fi;
                        fi;
                    fi;
                done;

                printf '\n';

                CURRENT_SUM="$(md5sum /var/www/proxy/internal/cameras.conf | cut -d ' ' -f 1)"
                NEW_SUM="$(md5sum /tmp/cameras.conf | cut -d ' ' -f 1)";

                if [[ "$CURRENT_SUM" != "$NEW_SUM" ]]; then
                    echo "Proxy server change detected, reloading... CSUM = ${CURRENT_SUM}, NSUM = ${NEW_SUM}";

                    cp -fv /tmp/cameras.conf /var/www/proxy/internal/cameras.conf;

                    docker exec -t wprint3d-proxy-1 nginx -s reload;
                fi;

                sleep 5;
            done;
        fi;
    done;
fi;