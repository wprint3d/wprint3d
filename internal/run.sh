#!/bin/bash

# if [[ -z $ROLE ]]; then
#     echo 'A role was expected. Pass ROLE as an environment variable and try again.';

#     exit 1;
# fi;

export PATH="$PATH":$(pwd)/bin;

wait-for-it mongo:27017 -t 0;

echo 'Waiting for Redis to be ready...';

while ! redis-cli -h keydb get '' 2>&1 > /dev/null; do
    sleep 1;
done;

# TODO: Re-enable Telescope
#
# wait-for-it mariadb:3306;

rm -fv '/var/www/internal/.bundler-exit-status';

waitForAssetBundler() {
    if [[ $(php artisan get:env ASSETS_WATCHER_ENABLED --default=true) == 'true' ]]; then
        # Wait for Vite to become available (no timeout)
        wait-for-it bundler:5173 -t 0;
    else
        echo 'Waiting for the asset bundler to exit...';

        while [[ ! -e '/var/www/internal/.bundler-exit-status' ]]; do
            sleep 1;
        done;
    fi;
}

if [[ -z $ROLE ]]; then
    echo "End of script reached, this container will run as a dummy and, as such, it won't actually do anything.";

    tail -f /dev/null;
else
    while true; do
        if [[ "$ROLE" != 'server' ]]; then
            echo 'Waiting for composer dependencies to become available...';

            while ! php artisan 2>&1 > /dev/null; do
                sleep 1;
            done;
        fi;

        if [[ "$ROLE" == 'server' ]]; then
            composer install;

            waitForAssetBundler;

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

            php artisan reset:active-jobs;

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
            waitForAssetBundler;

            while true; do
                php artisan websockets:serve --host 0.0.0.0 --port 6001;
            done;
        elif [[ "$ROLE" == 'mapper' ]]; then
            # Try to recognize a printer within them before enabling the udev monitor
            php artisan map:serial-printers;
            php artisan map:hardware-cameras;

            IFS=$'\n';

            mapCameraLabels() {
                HARDWARE_CAMERAS=$(php artisan get:hardware-cameras);

                CURRENT_LINE=0;
                MAX_LINE=$(echo -n "$HARDWARE_CAMERAS" | wc -l);

                for var in $HARDWARE_CAMERAS; do
                    eval "$var";

                    if [[ "$CURRENT_ID" != '' ]] && ([[ "$_ID" != "$CURRENT_ID" ]] || [[ "$CURRENT_LINE" -eq "$MAX_LINE" ]]); then
                        echo "Setting label for device at node $NODE with ID $CURRENT_ID";

                        LABEL='';

                        if printf "$NODE" | grep '/dev/video' > /dev/null; then
                            camera=$(ls -l "$NODE");

                            MAJOR=$(printf   "$camera" | cut -d ' ' -f 5  | cut -d ',' -f 1);
                            MINOR=$(printf   "$camera" | cut -d ' ' -f 6  | cut -d ',' -f 1);
                            DEVNAME=$(printf "$camera" | cut -d ' ' -f 10 | cut -d ',' -f 1);

                            DEVICE_INDEX=$(printf "$DEVNAME" | sed 's-/dev/video--');

                            # find the path in /sys/devices to idProduct and idVendor for the current camera
                            SYS_PATH=$(find /sys/devices -name uevent -exec grep -Hr 'video'"$DEVICE_INDEX" {} \; | sed 's/:DEVNAME.*//g' | sed 's/video4linux.*//g')'..';

                            if [[ -e "$SYS_PATH/idVendor" ]] && [[ -e "$SYS_PATH/idProduct" ]]; then
                                VENDOR_PRODUCT=$(cat $SYS_PATH/idVendor)':'$(cat $SYS_PATH/idProduct); # 0c45:64ab

                                LABEL=$(lsusb -d "$VENDOR_PRODUCT" | sed 's/  */ /g' | cut -d ':' -f 3 | sed 's/.....//');
                            else
                                echo "$DEVICE_INDEX: no label is available for this device.";
                            fi;
                        elif printf "$NODE" | grep '/sys/firmware/devicetree' > /dev/null; then
                            VENDOR_PRODUCT=$(cat "$NODE"/compatible);

                            VENDOR=$(printf "$VENDOR_PRODUCT" | cut -d ',' -f 1)
                            VENDOR="${VENDOR^}";

                            PRODUCT=$(printf "$VENDOR_PRODUCT" | cut -d ',' -f 2);
                            PRODUCT="${PRODUCT^^}";

                            LABEL="$VENDOR"' '"$PRODUCT";
                        fi;

                        if [[ "$LABEL" != '' ]]; then
                            php artisan map:set-hardware-camera-label "$NODE" "$LABEL";
                        fi;
                    fi;

                    CURRENT_ID="$_ID";
                    CURRENT_LINE=$(( $CURRENT_LINE + 1 ));
                done;
            }

            mapCameraLabels;

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
                            if [[ "$DEVNAME" != '' ]] && ([[ "$nodePath" == *'tty'* ]] || [[ "$nodePath" == *'video'* ]]) && [[ "$ACTION" == 'add' ]]; then
                                php artisan map:serial-printers;
                                php artisan map:hardware-cameras;

                                if [[ "$DEVNAME" == *'video'* ]]; then
                                    mapCameraLabels;
                                fi;
                            fi;

                            deviceChanged=0;
                            nodePath='';
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
        elif [[ "$ROLE" == 'serial-scheduler' ]]; then
            # we need "web" up in order to have the Marlin class available
            wait-for-it web:80         -t 0;
            wait-for-it ws-server:6001 -t 0;

            while true; do
                php artisan printers:handle-auto-serial;

                sleep 1;
            done;
        elif [[ "$ROLE" == 'bundler' ]]; then
            php artisan down;

            npm i;

            npm run build;

            BUILD_EXIT_STATUS=$?;

            php artisan up;

            if [[ $(php artisan get:env ASSETS_WATCHER_ENABLED --default=true) == 'true' ]]; then
                npm run dev;
            else
                printf $BUILD_EXIT_STATUS > /var/www/internal/.bundler-exit-status;

                while [[ -e /var/www/internal/.bundler-exit-status ]]; do
                    sleep 5;
                done;
            fi;
        elif [[ "$ROLE" == 'streamer' ]]; then
            getFreePort() {
                port=$PORT_SCAN_START;

                maxPort=$PORT_SCAN_END;

                while ps -fax | grep -e mjpg_streamer -e camera-streamer | grep "$port" 2>&1 > /dev/null; do
                    port=$(( $port + 1 ));

                    if [[ $port -gt $maxPort ]]; then
                        port='';

                        break;
                    fi;
                done;

                printf $port;
            }

            # Here, we're redirecting any relevant information to stderr (>&2)
            # instead of stdout, as stdout has been trapped by this function.
            updateCameras() {
                truncate --size 0 /tmp/cameras.conf;

                IFS=$'\n';

                HAS_RPI_CAM_INCLUDES=$([ ! -e /internal/vc/LICENCE ]; echo -n $?);

                CURRENT_ID='';

                HARDWARE_CAMERAS=$(php artisan get:hardware-cameras);

                if [[ $? -ne 0 ]]; then
                    echo 'Something went wrong while trying to update the list of connected cameras.';

                    sleep 1;

                    exit 1;
                fi;

                CURRENT_LINE=0;
                MAX_LINE=$(echo -n "$HARDWARE_CAMERAS" | wc -l);

                for var in $HARDWARE_CAMERAS; do
                    eval "$var";

                    if [[ "$CURRENT_ID" != '' ]] && ([[ "$_ID" != "$CURRENT_ID" ]] || [[ "$CURRENT_LINE" -eq "$MAX_LINE" ]]); then
                        echo "Refreshing device at node $NODE with ID $CURRENT_ID" >&2;

                        # camera-streamer doesn't like the actual full path
                        CAMERA_STREAMER_NODE=$(echo -n "$NODE" | sed 's-/sys/firmware/devicetree--');

                        if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]]; then
                            LIB_CAMERA_UVC_PID=$(ps -fax | grep usb_camera.sh       | grep -- $NODE                 | xargs | cut -d ' ' -f 1);
                            LIB_CAMERA_CSI_PID=$(ps -fax | grep libcamera_camera.sh | grep -- $CAMERA_STREAMER_NODE | xargs | cut -d ' ' -f 1);
                        else
                            LIB_CAMERA_UVC_PID=$(ps -fax | grep mjpg_streamer       | grep -- $NODE                 | xargs | cut -d ' ' -f 1);
                        fi;

                        if [[ "$ENABLED" -eq 1 ]] && [[ -e "$NODE" ]]; then
                            if [[ $LIB_CAMERA_UVC_PID == '' ]] && [[ "$LIB_CAMERA_CSI_PID" == '' ]]; then # not yet started
                                port=$(getFreePort);

                                if [[ "$port" == '' ]]; then
                                    echo "Unable to allocate port: no ports are currently available." >&2;
                                else
                                    # php artisan map:set-hardware-camera-port "$NODE" "$port";

                                    if [[ "$REQUIRES_LIB_CAMERA" -ne 1 ]]; then
                                        if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]]; then
                                            /camera-streamer/tools/usb_camera.sh \
                                                --camera-path=$NODE \
                                                --camera-fps=$FRAMERATE \
                                                --camera-width=$( echo -n $RESOLUTION | cut -d 'x' -f 1) \
                                                --camera-height=$(echo -n $RESOLUTION | cut -d 'x' -f 2) \
                                                --http-port=${port} &
                                        else
                                            mjpg_streamer \
                                                -i "input_uvc.so -d $NODE -r $RESOLUTION -f $FRAMERATE" \
                                                -o "output_http.so -p ${port}" &
                                        fi;
                                    else
                                        if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]] && [[ $(php artisan get:config-or-env enableLibCamera LIB_CAMERA_ENABLED --default=false) == 'true' ]]; then
                                            /camera-streamer/tools/libcamera_camera.sh \
                                                --camera-path=$CAMERA_STREAMER_NODE \
                                                --camera-fps=$FRAMERATE \
                                                --camera-width=$( echo -n $RESOLUTION | cut -d 'x' -f 1) \
                                                --camera-height=$(echo -n $RESOLUTION | cut -d 'x' -f 2) \
                                                --http-port=${port} &
                                        else
                                            echo "This camera requires Libcamera, but it's disabled. Enable it by setting the LIB_CAMERA_ENABLED environment variable to 'true'. If you've configured this setting from the browser previously, change it there instead." >&2;
                                        fi;
                                    fi;
                                fi;
                            else
                                port='';

                                if [[ "$LIB_CAMERA_UVC_PID" != '' ]]; then
                                    echo "PID UVC: ${LIB_CAMERA_UVC_PID}" >&2;

                                    if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]]; then
                                        port=$(ps -fax | grep usb_camera.sh | grep $NODE | sed 's/.*--http-port=//' | cut -d ' ' -f 1);
                                    else
                                        port=$(ps -fax | grep mjpg_streamer | grep $NODE | sed 's/.*-p //' | sed 's/ //g');
                                    fi;
                                elif [[ "$LIB_CAMERA_CSI_PID" != '' ]]; then
                                    echo "PID CSI: ${LIB_CAMERA_CSI_PID}" >&2;

                                    port=$(ps -fax | grep libcamera_camera.sh | grep $CAMERA_STREAMER_NODE | sed 's/.*--http-port=//' | cut -d ' ' -f 1);
                                fi;
                            fi;

                            if [[ "$port" != '' ]]; then
                                PROXY_PREFIX='uvc';

                                if [[ "$REQUIRES_LIB_CAMERA" -eq 1 ]]; then
                                    PROXY_PREFIX='csi';
                                fi;

                                printf "\nlocation /video/$PROXY_PREFIX/$INDEX {"                           >> /tmp/cameras.conf;
                                printf "\n\tproxy_pass            http://streamer:${port}/?action=stream;"  >> /tmp/cameras.conf;
                                printf "\n\tproxy_set_header Host \$host;"                                  >> /tmp/cameras.conf;
                                printf "\n\tinclude               nginxconfig.io/proxy.conf;"               >> /tmp/cameras.conf;
                                printf "\n}"                                                                >> /tmp/cameras.conf;
                            fi;
                        else # the camera has been disabled, kill and de-allocate resources
                            if [[ "$LIB_CAMERA_UVC_PID" != '' ]] || [[ "$LIB_CAMERA_CSI_PID" != '' ]]; then
                                PARENT_PID='';

                                if [[ "$LIB_CAMERA_UVC_PID" != '' ]]; then
                                    PARENT_PID="$LIB_CAMERA_UVC_PID";
                                elif [[ "$LIB_CAMERA_CSI_PID" != '' ]]; then
                                    PARENT_PID="$LIB_CAMERA_CSI_PID";
                                fi;

                                if [[ "$PARENT_PID" != '' ]]; then
                                    # kill parent and child processes
                                    for pid in $(pstree -p -a ${PARENT_PID} | cut -d ',' -f 2 | cut -d ' ' -f 1); do
                                        kill $pid;
                                    done;
                                fi;
                            fi;
                        fi;
                    fi;

                    CURRENT_ID="$_ID";
                    CURRENT_LINE=$(( $CURRENT_LINE + 1 ));
                done;

                rm -fv /var/www/internal/.requires_camera_detection;

                CURRENT_SUM="$(md5sum /var/www/proxy/internal/cameras.conf | cut -d ' ' -f 1)"
                NEW_SUM="$(md5sum /tmp/cameras.conf | cut -d ' ' -f 1)";

                if [[ "$CURRENT_SUM" != "$NEW_SUM" ]]; then
                    echo "Proxy server change detected, reloading... CSUM = ${CURRENT_SUM}, NSUM = ${NEW_SUM}" >&2;

                    cp -fv /tmp/cameras.conf /var/www/proxy/internal/cameras.conf >&2;

                    docker exec -t wprint3d-proxy-1 nginx -s reload;
                fi;
            }

            if [[ ! -e '/var/www/proxy/internal/cameras.conf' ]]; then
                truncate --size 0 /var/www/proxy/internal/cameras.conf;
            fi;

            truncate --size 0 /tmp/cameras.conf;

            waitForAssetBundler;

            CURRENT_SUM=$(ps -x | grep -e mjpg -e camera-streamer | grep -v -e grep -e sed | sed 's/.*mjpg//' | sed 's/.*camera-streamer//' | md5sum | cut -d ' ' -f 1);

            while true; do
                NEW_SUM=$(ps -x | grep -e mjpg -e camera-streamer | grep -v -e grep -e sed | sed 's/.*mjpg//' | sed 's/.*camera-streamer//' | md5sum | cut -d ' ' -f 1);

                if [[ "$CURRENT_SUM" != "$NEW_SUM" ]]; then
                    echo "Camera configuration change detected, probing cameras... CSUM = ${CURRENT_SUM}, NSUM = ${NEW_SUM}";

                    CURRENT_SUM="$NEW_SUM";

                    touch /var/www/internal/.requires_camera_detection;
                fi;

                sleep 5;
            done &

            updateCameras;

            inotifywait -m /dev /var/www/internal -e create -e delete -e delete_self |
                while read event ; do
                    echo "EVENT: $event";

                    ACTION=$(printf "$event" | cut -d ' ' -f 2);
                    FILENAME=$(printf "$event" | cut -d ' ' -f 3);

                    if ([[ "$ACTION" != 'DELETE' ]] && [[ "$FILENAME" == '.requires_camera_detection' ]]) || [[ "$FILENAME" != '.requires_camera_detection' ]]; then
                        updateCameras;
                    fi;
                done;
        fi;
    done;
fi;