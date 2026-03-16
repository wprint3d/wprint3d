#!/bin/bash

export PATH="$PATH":$(pwd)/bin;
export PATH="$PATH":/root/gcodestat;
export PATH="$PATH":"$HOME"/bin;

source /var/www/internal/service-status.sh;

# Remove any temporary files that might have been left behind
rm -fv /tmp/*.txt /var/www/internal/startup/*.txt;

# Create the base storage directories
mkdir -p /var/www/storage/{app,framework/{cache,data,views},logs};

waitForSecrets() {
    echo 'Waiting for environment variables to become available...';

    # This logic triggers on production environments only
    if [[ -d '/var/www/.external-configs' ]]; then
        echo 'External secrets detected, copying...';

        touch /var/www/.external-configs/.env;

        ln -s /var/www/.external-configs/.env /var/www/.env;
    fi;

    # Wait for the .env file to be created and populated
    while [[ ! -f '/var/www/.env' || ! -s '/var/www/.env' ]]; do
        sleep .1;
    done;
}

generateSecrets() {
    # This logic triggers on production environments only
    if [[ -d '/var/www/.external-configs' ]]; then
        echo 'External secrets detected, copying...';

        touch /var/www/.external-configs/.env;

        ln -s /var/www/.external-configs/.env /var/www/.env;
    fi;

    # If the secrets already exist, skip the generation process
    if [[ -f '/var/www/.env' && -s '/var/www/.env' ]]; then
        echo 'Secrets already exist, skipping generation...';

        # Ensure that the OCTANE_ENABLED variable is present
        if ! grep -q 'OCTANE_ENABLED' /var/www/.env && [[ "${DEVELOPER_MODE}" != 'true' ]]; then
            echo 'OCTANE_ENABLED=true' >> /var/www/.env;
        fi;

        return;
    fi;

    if ! php artisan 2>&1 > /dev/null; then
        echo 'Composer dependencies are missing, installing...';

        composer install;
    fi;

    echo 'Generating secrets...';

    PUSHER_APP_KEY=$(uuidgen    | md5sum    | cut -d ' ' -f 1);
    PUSHER_APP_SECRET=$(uuidgen | sha512sum | cut -d ' ' -f 1);

    echo "PUSHER_APP_KEY=${PUSHER_APP_KEY}"     >> /tmp/.secrets;
    echo "PUSHER_APP_SECRET=${PUSHER_APP_KEY}"  >> /tmp/.secrets;

    # Generate the application key
    echo 'APP_KEY='$(PUSHER_APP_KEY="${PUSHER_APP_KEY}" PUSHER_APP_SECRET="${PUSHER_APP_KEY}" php artisan key:generate --show) >> /tmp/.secrets;

    # Enable Octane by default
    if [[ "${DEVELOPER_MODE}" != 'true' ]]; then
        echo 'OCTANE_ENABLED=true' >> /tmp/.secrets;
    fi;

    # Store the secrets in the target location
    cat /tmp/.secrets > /var/www/.env;
}

refreshDockerLog() {
    IFS=$'\n';

    ALL_SERVICES_READY=0;

    while [[ $ALL_SERVICES_READY -eq 0 ]]; do
        truncate --size 0 /tmp/services.txt /tmp/startup.txt;

        ALL_SERVICES_READY=1;

        for identifier in $(docker ps -a --format '{{ .ID }},{{ .Names }}' | grep wprint3d); do
            CID=$(printf  "$identifier" | cut -d ',' -f 1);
            NAME=$(printf "$identifier" | cut -d ',' -f 2);

            LINES=$(docker logs "$CID" --tail 3 2>&1);

            for line in $LINES; do
                echo "$NAME"'     | '"$line" >> /tmp/startup.txt;
            done;

            echo "$NAME" >> /tmp/services.txt;

            STATUS=0;

            if service_status_for_container "$CID" "$NAME"; then
                STATUS=1;
            fi;

            echo "$STATUS" > /tmp/"$NAME"_status.txt;

            if [[ "$STATUS" -eq 0 ]]; then
                ALL_SERVICES_READY=0;
            fi;

            CUR_SUM=$(md5sum /tmp/"$NAME"_status.txt 2> /dev/null | cut -d ' ' -f 1);
            OLD_SUM=$(md5sum /var/www/internal/startup/"$NAME"_status.txt 2> /dev/null | cut -d ' ' -f 1);

            if [[ "$CUR_SUM" != "$OLD_SUM" ]]; then
                cp -f /tmp/"$NAME"_status.txt /var/www/internal/startup/"$NAME"_status.txt;
            fi;
        done;

        if [[ -e '/tmp/startup.txt' ]]; then
            CUR_SUM=$(md5sum /tmp/startup.txt | cut -d ' ' -f 1);
            OLD_SUM=$(md5sum /var/www/internal/startup/startup.txt | cut -d ' ' -f 1);

            if [[ "$CUR_SUM" != "$OLD_SUM" ]]; then
                cp -f /tmp/startup.txt /var/www/internal/startup/startup.txt;
            fi;
        fi;

        if [[ -e '/tmp/services.txt' ]]; then
            CUR_SUM=$(md5sum /tmp/services.txt | cut -d ' ' -f 1);
            OLD_SUM=$(md5sum /var/www/internal/startup/services.txt | cut -d ' ' -f 1);

            if [[ "$CUR_SUM" != "$OLD_SUM" ]]; then
                cp -f /tmp/services.txt /var/www/internal/startup/services.txt;
            fi;
        fi;

        echo "All services ready: $ALL_SERVICES_READY";

        sleep 1;
    done;
}

refreshThirdPartyLicenses() {
    echo "Refreshing third-party licenses...";

    TPL_PATH='/var/www/THIRD_PARTY_LICENSES.txt';

    cat '/var/www/_STATIC_THIRD_PARTY_LICENSES.txt' > $TPL_PATH;

    printf '\n\n' >> $TPL_PATH;

    for license in $(find {vendor,frontend/node_modules} -name '*LICENSE*'); do \
        PROJECT_NAME=$(printf "$license" | sed -E 's/((vendor|frontend\/node_modules)\/)|(\/LICENSE.*)|(\/ORIGINAL.*)|(src\/)//g' | sort | uniq -u);

        echo '================================================================================' >> $TPL_PATH;
        echo "$PROJECT_NAME"                                                                    >> $TPL_PATH;
        echo '================================================================================' >> $TPL_PATH;
        echo ''                                                                                 >> $TPL_PATH;
        cat "$license"                                                                          >> $TPL_PATH;
        echo ''                                                                                 >> $TPL_PATH;
    done;
}

runDeferredTasks() {
    echo 'Running deferred tasks...';

    echo 'Resetting pending updates...';
    php artisan app:reset-pending-updates;

    echo 'Trying to look for updates...';
    php artisan app:check-for-updates;
}

if [[ "$ROLE" == 'server' ]]; then
    generateSecrets;
fi;

waitForSecrets;

wait-for-it mongo:27017 -t 0;

echo 'Waiting for Redis to be ready...';

while ! redis-cli -h redis get '' 2>&1 > /dev/null; do
    sleep 1;
done;

if [[ -z $ROLE ]]; then
    echo "End of script reached, this container will run as a dummy and, as such, it won't actually do anything.";

    tail -f /dev/null;
else
    while true; do
        MACHINE_UUID='';

        if [[ "$ROLE" != 'server' ]]; then
            echo 'Waiting for composer dependencies to become available...';

            while ! php artisan 2>&1 > /dev/null; do
                sleep 1;
            done;

            MACHINE_UUID=$(php artisan get:machine-uuid);

            if [[ "$MACHINE_UUID" == '' ]]; then
                echo 'Waiting for the UUID for this machine to be generated...';
                echo 'If this process takes more than a minute, try bringing your containers down and run "bash run.sh" in order to get back on track by rebuilding the image.';

                while [[ "$MACHINE_UUID" == '' ]]; do
                    sleep 1;

                    MACHINE_UUID=$(php artisan get:machine-uuid);
                done;
            fi;
        fi;

        if [[ "$ROLE" == 'server' ]]; then
            refreshDockerLog &

            # Reset proxy configuration for the recordings
            truncate --size 0 /var/www/proxy/internal/recordings.conf;

            # Disable permissions checks for the Git repository
            git config --global --add safe.directory /var/www;

            # Downloads the required dependencies if they're not already
            # present or if DEVELOPER_MODE is enabled
            if ! php artisan 2>&1 > /dev/null || [[ "${DEVELOPER_MODE}" == 'true' ]]; then
                composer install;
            fi;

            if [[ $? -ne 0 ]]; then
                exit 1; # crash and wait for self-restart
            fi;

            # Flush cached files
            php artisan optimize:clear;

            # If the Git repository is present, get the version from `git rev-parse`.
            if [[ -f '/var/www/.git/HEAD' ]] && [[ "${DEVELOPER_MODE}" == 'true' ]]; then
                git rev-parse --short HEAD > /var/www/internal/app_ver;
            fi;

            printf '' > /var/www/internal/startup/startup.txt;

            MACHINE_UUID=$(php artisan get:machine-uuid);

            if [[ "$MACHINE_UUID" == '' ]]; then
                MACHINE_UUID=$(php artisan make:machine-uuid);

                echo 'A machine UUID was generated: '"$MACHINE_UUID";
            else
                echo 'Machine UUID loaded: '"$MACHINE_UUID";
            fi;

            # Reset config file
            printf ''                                                        > /tmp/recordings.conf;
            printf "\nlocation /recordings/$MACHINE_UUID {"                 >> /tmp/recordings.conf;
            printf "\n\trewrite  ^/recordings/$MACHINE_UUID(.*) /$1 break;" >> /tmp/recordings.conf;
            printf "\n\troot     /public/recordings;"                       >> /tmp/recordings.conf;
            printf "\n}"                                                    >> /tmp/recordings.conf;

            CURRENT_SUM="$(md5sum /var/www/proxy/internal/recordings.conf | cut -d ' ' -f 1)"
            NEW_SUM="$(md5sum /tmp/recordings.conf | cut -d ' ' -f 1)";

            if [[ "$CURRENT_SUM" != "$NEW_SUM" ]]; then
                echo "Proxy server change detected, reloading... CSUM = ${CURRENT_SUM}, NSUM = ${NEW_SUM}" >&2;

                cp -fv /tmp/recordings.conf /var/www/proxy/internal/recordings.conf >&2;

                for container_id in $(docker ps --filter name=proxy --format '{{ .ID }}'); do
                    docker exec -t $container_id nginx -s reload;
                done;
            fi;

            refreshThirdPartyLicenses &

            # TODO: This is just for development and testing purposes and
            #       should be removed for production.
            echo "Creating the sample user (if it doesn't exist)...";
            php artisan create:sample-user;

            echo "Running migrations...";
            php artisan migrate --force;

            if [[ "${DEVELOPER_MODE}" == 'true' ]]; then
                echo "Generating Marlin labels...";
                php artisan make:marlin-labels;
            fi;

            echo "Resetting stalled jobs...";
            php artisan reset:active-jobs;

            echo 'Declare default configurations...';
            php artisan make:default-configuration;

            echo 'Declare the Docker Compose directory...';
            php artisan make:compose-path-config;

            runDeferredTasks &

            if [ "$(php artisan get:env OCTANE_ENABLED)" == 'true' ]; then
                echo 'Starting Octane web server...';
                php artisan octane:start --host 0.0.0.0 --port 80;
            else
                echo 'Starting Artisan web server...';
                php artisan serve        --host 0.0.0.0 --port 80;
            fi;
        elif [[ "$ROLE" == 'concurrency-scheduler' ]]; then
            php artisan cache:clear;
            php artisan queue:flush;
            php artisan queue:restart;

            echo 'Starting the supervisor...';
            mkdir -p /tmp/supervisor/logs;
            supervisord -c /var/www/internal/supervisor/supervisord.conf;
            echo 'Supervisor started!';

            php artisan concurrent:run-indefinitely &

            while true; do
                for log in /tmp/supervisor/logs/*.log; do
                    truncate --size 512K "$log"
                done;

                sleep 60;
            done;
        elif [[ "$ROLE" == 'ws-server' ]]; then
            while true; do
                php artisan reverb:start --host 0.0.0.0 --port 6001;
            done;
        elif [[ "$ROLE" == 'mapper' ]]; then
            wait-for-it ws-server:6001 -t 0;

            # Try to recognize a printer within them before enabling the udev monitor
            php artisan map:serial-printers     &
            php artisan map:hardware-cameras    &

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
                                VENDOR_PRODUCT=$(cat "$SYS_PATH"/idVendor)':'$(cat "$SYS_PATH"/idProduct); # 0c45:64ab

                                LABEL=$(lsusb -d "$VENDOR_PRODUCT" | sed 's/  */ /g' | cut -d ':' -f 3 | sed 's/.....//');
                            else
                                echo "$NODE: no label is available for this device.";
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
                            if [[ "$DEVNAME" != '' ]] && ([[ "$nodePath" == *'tty'* ]] || [[ "$nodePath" == *'video'* ]]) && ([[ "$ACTION" == 'add' ]] || [[ "$ACTION" == 'remove' ]]); then
                                php artisan map:hardware-cameras;
                                php artisan map:serial-printers   $(echo -n "$DEVNAME" | sed 's/.*tty//g');

                                if [[ "$DEVNAME" == *'video'* ]]; then
                                    mapCameraLabels;
                                fi;
                            fi;

                            deviceChanged=0;
                            nodePath='';

                            DEVNAME='';
                            ACTION='';
                        fi;
                    done;
            done;
        elif [[ "$ROLE" == 'scheduler' ]]; then
            if [[ "$KIND" == 'short' ]]; then
                while true; do
                    php artisan short-schedule:run;
                done;
            else
                crontab /var/www/internal/cron/crontab;

                cron -f;
            fi;
        elif [[ "$ROLE" == 'streamer' ]]; then
            # TODO: Holy fuck, we should improve this code for readability.
            #       Not even Sonnet 4.3 would do something so atrocious.
            #
            # What we should improve on:
            # - Redundancy
            # - Readability
            # - Runtime complexity
            # - Performance (get rid of on-demand calls, make it event-driven)
            # - (De-)duplication

            getFreePort() {
                port=$PORT_SCAN_START;

                maxPort=$PORT_SCAN_END;

                while ps -fax | grep -e ustreamer -e camera-streamer | grep "$port" 2>&1 > /dev/null; do
                    port=$(( $port + 1 ));

                    if [[ $port -gt $maxPort ]]; then
                        port='';

                        break;
                    fi;
                done;

                printf "$port";
            }

            # Here, we're redirecting any relevant information to stderr (>&2)
            # instead of stdout, as stdout has been trapped by this function.
            updateCameras() {
                TMP_CAMERAS_CONF=$(mktemp);
                YV_STREAMER_SOFTWARE_PORT=${YV_STREAMER_SOFTWARE_PORT:-8080};

                truncate --size 0 "$TMP_CAMERAS_CONF";

                IFS=$'\n';

                HAS_RPI_CAM_INCLUDES=$([[ $(uname -m) != 'aarch64' ]] && [[ $(uname -m) != 'arm' ]]; echo -n $?);

                CURRENT_ID='';

                HARDWARE_CAMERAS=$(php artisan get:hardware-cameras);

                if [[ $? -ne 0 ]]; then
                    echo "${HARDWARE_CAMERAS}";
                    echo 'Something went wrong while trying to update the list of connected cameras.';

                    sleep 1;

                    exit 1;
                fi;

                CURRENT_LINE=0;
                MAX_LINE=$(echo -n "$HARDWARE_CAMERAS" | wc -l);

                for var in $HARDWARE_CAMERAS; do
                    eval "$var";

                    if [[ "$CURRENT_ID" != '' ]] && ([[ "$_ID" != "$CURRENT_ID" ]] || [[ "$CURRENT_LINE" -eq "$MAX_LINE" ]]); then
                        port='';
                        FSWEBCAM_PID='';
                        USES_SOFTWARE_STREAMER=0;

                        echo "ENABLED = $ENABLED" >&2;
                        echo "NODE = $NODE" >&2;
                        echo "SUPPORTS_MJPEG = $SUPPORTS_MJPEG" >&2;
                        echo "STREAMS_MJPEG = $STREAMS_MJPEG" >&2;
                        echo "CAPTURE_ENCODING = $CAPTURE_ENCODING" >&2;
                        echo "port = $port" >&2;
                        echo "FSWEBCAM_PID = $FSWEBCAM_PID" >&2;

                        echo "Refreshing device at node $NODE with ID $CURRENT_ID" >&2;

                        # camera-streamer doesn't like the actual full path
                        CAMERA_STREAMER_NODE=$(echo -n "$NODE" | sed 's-/sys/firmware/devicetree--');

                        if [[ "$CAPTURE_ENCODING" == 'null' ]]; then
                            CAPTURE_ENCODING='';
                        fi;

                        if [[ "$SUPPORTS_MJPEG" -eq 0 ]] && [[ "$REQUIRES_LIB_CAMERA" -eq 0 ]] && [[ "$STREAMS_MJPEG" -eq 1 ]]; then
                            USES_SOFTWARE_STREAMER=1;

                            echo "This camera doesn't support hardware MJPEG, routing it through yv-streamer-software instead." >&2;

                            FSWEBCAM_PID=$(ps -fax | grep fswebcam | grep -- "$NODE" | xargs | cut -d ' ' -f 1);
                        else
                            if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]]; then
                                LIB_CAMERA_UVC_PID=$(ps -fax | grep camera-streamer | grep -- "$NODE"                 | xargs | cut -d ' ' -f 1);
                                LIB_CAMERA_CSI_PID=$(ps -fax | grep camera-streamer | grep -- "$CAMERA_STREAMER_NODE" | xargs | cut -d ' ' -f 1);
                            else
                                LIB_CAMERA_UVC_PID=$(ps -fax | grep ustreamer       | grep -- "$NODE"                 | xargs | cut -d ' ' -f 1);
                            fi;
                        fi;

                        if [[ "$ENABLED" -eq 1 ]] && [[ -e "$NODE" ]]; then
                            if [[ "$USES_SOFTWARE_STREAMER" -eq 1 ]]; then
                                if [[ "$FSWEBCAM_PID" != '' ]]; then
                                    echo "Stopping deprecated fswebcam process for $NODE" >&2;

                                    kill "$FSWEBCAM_PID" || true;
                                fi;
                            elif [[ $LIB_CAMERA_UVC_PID == '' ]] && [[ "$LIB_CAMERA_CSI_PID" == '' ]]; then # not yet started
                                port=$(getFreePort);

                                if [[ "$port" == '' ]]; then
                                    echo "Unable to allocate port: no ports are currently available." >&2;
                                else
                                    # php artisan map:set-hardware-camera-port "$NODE" "$port";

                                    if [[ "$REQUIRES_LIB_CAMERA" -ne 1 ]]; then
                                        if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]]; then
                                            echo "Starting camera-streamer in RPi-cam mode for $NODE" >&2;

                                            camera-streamer \
                                                --camera-type=v4l2 \
                                                --camera-path="$NODE" \
                                                --camera-fps="$FRAMERATE" \
                                                --camera-width=$( echo -n "$RESOLUTION" | cut -d 'x' -f 1) \
                                                --camera-height=$(echo -n "$RESOLUTION" | cut -d 'x' -f 2) \
                                                --http-listen=0.0.0.0 \
                                                --http-port="${port}" &

                                            echo "camera-streamer started with PID $! using port $port" >&2;
                                        else
                                            echo "Starting ustreamer for $NODE" >&2;

                                            ustreamer \
                                                --device      "$NODE" \
                                                --resolution  "$RESOLUTION" \
                                                --desired-fps $(printf "$FRAMERATE" | sed 's/\..*//' | sed 's/,.*//') \
                                                --host        'streamer' \
                                                --port        "$port" &

                                            echo "ustreamer started with PID $! using port $port" >&2;
                                        fi;
                                    else
                                        if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]] && [[ $(php artisan get:config enableLibCamera --default=true) == 'true' ]]; then
                                            echo "Starting camera-streamer in UVC mode for $NODE" >&2;

                                            camera-streamer \
                                                --camera-type=libcamera \
                                                --camera-path="$CAMERA_STREAMER_NODE" \
                                                --camera-fps="$FRAMERATE" \
                                                --camera-width=$( echo -n "$RESOLUTION" | cut -d 'x' -f 1) \
                                                --camera-height=$(echo -n "$RESOLUTION" | cut -d 'x' -f 2) \
                                                --http-listen=0.0.0.0 \
                                                --http-port="${port}" &
                                        else
                                            echo "This camera requires Libcamera, but it's disabled. Enable it by setting the LIB_CAMERA_ENABLED environment variable to 'true'. If you've configured this setting from the browser previously, change it there instead." >&2;
                                        fi;
                                    fi;
                                fi;
                            else
                                if [[ "$LIB_CAMERA_UVC_PID" != '' ]]; then
                                    echo "PID UVC: ${LIB_CAMERA_UVC_PID}" >&2;

                                    if [[ "$HAS_RPI_CAM_INCLUDES" -eq 1 ]]; then
                                        port=$(ps -fax | grep camera-streamer | grep "$NODE" | sed 's/.*--http-port=//' | cut -d ' ' -f 1 | xargs);
                                    else
                                        port=$(ps -fax | grep ustreamer       | grep "$NODE" | sed 's/.*--port //'      | xargs           | sed 's/ .*//g');
                                    fi;
                                elif [[ "$LIB_CAMERA_CSI_PID" != '' ]]; then
                                    echo "PID CSI: ${LIB_CAMERA_CSI_PID}" >&2;

                                    port=$(ps -fax | grep camera-streamer | grep "$CAMERA_STREAMER_NODE" | sed 's/.*--http-port=//' | cut -d ' ' -f 1 | xargs);
                                elif [[ "$FSWEBCAM_PID" != '' ]]; then
                                    echo "PID FSWEBCAM: ${FSWEBCAM_PID}" >&2;
                                fi;
                            fi;

                            PROXY_PREFIX='uvc';

                            if [[ "$port" != '' ]]; then
                                if [[ "$REQUIRES_LIB_CAMERA" -eq 1 ]]; then
                                    PROXY_PREFIX='csi';
                                fi;

                                printf "\nlocation /video/$MACHINE_UUID/$PROXY_PREFIX/$INDEX {" >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_pass            http://streamer:${port}/;"    >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_set_header Host \$host;"                      >> $TMP_CAMERAS_CONF;
                                printf "\n\tinclude               nginxconfig.io/proxy.conf;"   >> $TMP_CAMERAS_CONF;
                                printf "\n}"                                                    >> $TMP_CAMERAS_CONF;
                            elif [[ "$USES_SOFTWARE_STREAMER" -eq 1 ]]; then
                                printf "\nlocation /video/$MACHINE_UUID/$PROXY_PREFIX/$INDEX {"  >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_pass            http://yv-streamer-software:${YV_STREAMER_SOFTWARE_PORT}/${CURRENT_ID}/;" >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_set_header Host \$host;"                      >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_set_header X-Node ${NODE};"          >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_set_header X-Resolution ${RESOLUTION};" >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_set_header X-Framerate ${FRAMERATE};" >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_set_header X-Capture-Encoding ${CAPTURE_ENCODING};" >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_buffering      off;"                         >> $TMP_CAMERAS_CONF;
                                printf "\n\tproxy_ignore_headers X-Accel-Buffering;"            >> $TMP_CAMERAS_CONF;
                                printf "\n\tinclude               nginxconfig.io/proxy.conf;"  >> $TMP_CAMERAS_CONF;
                                printf "\n}"                                                     >> $TMP_CAMERAS_CONF;
                            fi;
                        else # the camera has been disabled, kill and de-allocate resources
                            if [[ "$LIB_CAMERA_UVC_PID" != '' ]] || [[ "$LIB_CAMERA_CSI_PID" != '' ]] || [[ "$FSWEBCAM_PID" != '' ]]; then
                                echo "Killing processes for $NODE" >&2;

                                PARENT_PID='';

                                if [[ "$LIB_CAMERA_UVC_PID" != '' ]]; then
                                    PARENT_PID="$LIB_CAMERA_UVC_PID";
                                elif [[ "$LIB_CAMERA_CSI_PID" != '' ]]; then
                                    PARENT_PID="$LIB_CAMERA_CSI_PID";
                                elif [[ "$FSWEBCAM_PID" != '' ]]; then
                                    PARENT_PID="$FSWEBCAM_PID";
                                fi;

                                if [[ "$PARENT_PID" != '' ]]; then
                                    # kill parent and child processes
                                    for pid in $(pstree -p -a ${PARENT_PID} | cut -d ',' -f 2 | cut -d ' ' -f 1); do
                                        kill "$pid";
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
                NEW_SUM="$(md5sum "$TMP_CAMERAS_CONF" | cut -d ' ' -f 1)";

                if [[ "$CURRENT_SUM" != "$NEW_SUM" ]]; then
                    echo "Proxy server change detected, reloading... CSUM = ${CURRENT_SUM}, NSUM = ${NEW_SUM}" >&2;

                    cp -fv "$TMP_CAMERAS_CONF" /var/www/proxy/internal/cameras.conf >&2;
                    rm -fv "$TMP_CAMERAS_CONF" >&2;

                    for container_id in $(docker ps --filter name=proxy --format '{{ .ID }}'); do
                        docker exec -t $container_id nginx -s reload;
                    done;
                else
                    echo "No changes detected in the cameras.conf file (${CURRENT_SUM} == ${NEW_SUM})" >&2;
                fi;
            }

            updateCameras;

            inotifywait -m /dev /var/www/internal -e create -e delete -e delete_self |
                while read event ; do
                    echo "EVENT: $event";

                    ACTION=$(printf "$event" | cut -d ' ' -f 2);
                    FILENAME=$(printf "$event" | cut -d ' ' -f 3);

                    if ([[ "$FILENAME" == *'video'* ]]); then
                        updateCameras;
                    fi;
                done;
        fi;
    done;
fi;
