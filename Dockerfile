FROM php:8.2

SHELL ["/bin/bash", "-c"]

# Node.js
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg &&\
    mkdir -p /etc/apt/keyrings &&\
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg &&\
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_16.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list &&\
    apt-get update && apt-get install -y --no-install-recommends nodejs &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*;

# Basic dependencies
RUN apt-get update && apt-get install -y --no-install-recommends coreutils git curl gnupg libcurl4-openssl-dev libxml2-dev libzip-dev nodejs npm fswebcam procps build-essential cmake usbutils docker.io libssl-dev pkg-config &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# MySQL/MariaDB CLI client
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Redis CLI client
RUN apt-get update && apt-get install -y --no-install-recommends redis-tools &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install inotify-tools
RUN apt-get update && apt-get install -y --no-install-recommends inotify-tools &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# PHP extensions: MongoDB + Redis + DIO (Direct I/O)
RUN pecl install -f mongodb redis dio

# PHP extension: downgraded Swoole (5.0.3), workarounds issue #5198
RUN pecl install -f swoole-5.0.3

# Enable PECL-based extensions: MongoDB + Redis + DIO (Direct I/O)
RUN docker-php-ext-enable mongodb redis dio swoole

# Install several officially supported PHP extensions: cURL, XML, ZIP, DOM, MySQLi, PDO MySQL, Sockets and PCNTL.
RUN docker-php-ext-install -j$(( $(nproc --all) * 2 )) curl xml zip dom mysqli pdo_mysql sockets pcntl

# Build and install Camera Streamer and MJPG Streamer
#
# In the two blocks shown below, we will compile and install the RPi camera
# dependencies (libcamera and libcamera-apps-lite) which are then bundled with
# the image. This is useful as most SBCs DO have a built-in hardware
# encoder/decoder that camera-streamer can take advantage of.
#
# Note that we're manually removing "input_raspicam" from MJPG streamer as it's
# broken on 64-bit builds of Raspberry Pi OS and it's terribly slow too, so
# we'll just build and use "input_libcamera" instead. As a side note, even
# though we're actually building "input_libcamera" into MJPG Streamer, we'll be
# using Camera Streamer instead whenever an RPi camera is found, as it's faster
# and more reliable.
RUN apt-get update && apt-get install -y --no-install-recommends meson python3 python3-pip python3-jinja2 python3-ply python3-yaml libjpeg62-turbo-dev libavformat-dev libavutil-dev libavcodec-dev v4l-utils pkg-config xxd build-essential cmake libssl-dev libboost-program-options-dev libdrm-dev libexif-dev libglib2.0-dev libgstreamer-plugins-base1.0-dev &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    git clone https://github.com/raspberrypi/libcamera.git -b release-v0.0.5+83-bde9b04f &&\
    cd libcamera &&\
    meson build --buildtype=release -Dpipelines=rpi/vc4 -Dipas=rpi/vc4 -Dv4l2=true -Dgstreamer=enabled -Dtest=false -Dlc-compliance=disabled -Dcam=disabled -Dqcam=disabled -Ddocumentation=disabled -Dpycamera=disabled &&\
    ninja -C build &&\
    ninja -C build install;
RUN apt-get update && apt-get install -y --no-install-recommends libtiff5-dev libpng-dev &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    git clone https://github.com/raspberrypi/libcamera-apps.git -b v1.2.1 &&\
    cd libcamera-apps &&\
    meson setup build -Denable_libav=true -Denable_drm=true -Denable_egl=false -Denable_qt=false -Denable_opencv=false -Denable_tflite=false &&\
    meson compile -C build -j"$(nproc --all)" &&\
    meson install -C build &&\
    ldconfig;

# camera-streamer
RUN git clone https://github.com/ayufan/camera-streamer.git -b v0.2.6 --recursive --shallow-submodules &&\
    cd camera-streamer &&\
    make -j$(( "$(nproc --all)" * 2 )) &&\
    make install;

# MJPG Streamer
RUN git clone https://github.com/ArduCAM/mjpg-streamer.git &&\
    cd mjpg-streamer/mjpg-streamer-experimental &&\
    sed -i 's/add_subdirectory(plugins\/input_raspicam)//g' CMakeLists.txt &&\
    make -j$(( "$(nproc --all)" * 2 )) &&\
    make install

# Install the ping tool
RUN apt-get update && apt-get install -y --no-install-recommends inetutils-ping &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install the Composer PHP package manager
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install udev
RUN apt-get update && apt-get install -y --no-install-recommends udev &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# PHP extension: memcached
RUN apt-get update && apt-get install -y --no-install-recommends libmemcached-dev &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    pecl install -f memcached &&\
    docker-php-ext-enable memcached

# Install ffmpeg
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# PHP extension: intl
RUN docker-php-ext-install -j$(( $(nproc --all) * 2 )) intl

# Install supervisor(d)
RUN apt-get update && apt-get install -y --no-install-recommends supervisor

# Install pstree (psmisc)
RUN apt-get update && apt-get install psmisc

WORKDIR /var/www

# TODO: I'm not entirely sure as to whether this is still necessary.
COPY internal /internal

ENTRYPOINT [ "./internal/run.sh" ]