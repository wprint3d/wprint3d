FROM php:8.2

# Node.js
RUN curl -sL https://deb.nodesource.com/setup_16.x -o /tmp/nodesource_setup.sh &&\
    bash /tmp/nodesource_setup.sh;

# Basic dependencies
RUN apt-get install -y coreutils git curl gnupg libcurl4-openssl-dev libxml2-dev libzip-dev nodejs fswebcam procps build-essential cmake usbutils docker.io libssl-dev pkg-config

# MySQL/MariaDB CLI client
RUN apt-get install -y mariadb-client

# Redis CLI client
RUN apt-get install -y redis-tools

# Install inotify-tools
RUN apt-get install -y inotify-tools

# PHP extensions: MongoDB + Redis + DIO (Direct I/O) + Swoole
RUN pecl install -f mongodb redis dio swoole

# Enable PECL-based extensions: MongoDB + Redis + DIO (Direct I/O)
RUN docker-php-ext-enable mongodb redis dio swoole

# Install several officially supported PHP extensions: cURL, XML, ZIP, DOM, MySQLi, PDO MySQL, Sockets and PCNTL.
RUN docker-php-ext-install -j$(( $(nproc --all) * 2 )) curl xml zip dom mysqli pdo_mysql sockets pcntl

COPY internal /internal

# Build and install Camera Streamer and MJPG Streamer
#
# In the two blocks shown below, we check for the existence of the file
# /opt/vc/LICENCE, if it does exist, the RPi camera dependencies are bundled
# with the image.
#
# Note that we're manually removing "input_raspicam" as it's broken on 64-bit
# builds of Raspberry Pi OS and it's terribly slow too, so we'll just build and
# use "input_libcamera" instead. As a side note, even though we're actually
# building "input_libcamera" into MJPG Streamer, we'll be using Camera Streamer
# instead whenever an RPi camera is found, as it's faster and more reliable.
#
# The regex substitution on the first block works by automatically removing any
# option that contains --virtual-address and everything afterwards when running
# libcamera_camera.sh as we need that data in order to track whether the
# process is still alive.
RUN curl -O 'https://archive.raspberrypi.org/debian/pool/main/r/raspberrypi-archive-keyring/raspberrypi-archive-keyring_2016.10.31_all.deb' &&\
    dpkg -i ./raspberrypi-archive-keyring_2016.10.31_all.deb &&\
    if [ -e /internal/vc/LICENCE ]; then \
        cp -rfv /internal/vc /opt/vc; \
        echo 'deb http://archive.raspberrypi.org/debian/ bullseye main' >> /etc/apt/sources.list.d/raspi.list; \
    fi &&\
    apt-get update &&\
    if [ -e /internal/vc/LICENCE ]; then \
        apt-get update && \
        apt-get install -y libcamera-apps-lite libjpeg62-turbo-dev libavformat-dev libavutil-dev libavcodec-dev libcamera-dev liblivemedia-dev v4l-utils pkg-config xxd build-essential cmake libssl-dev && \
        git clone https://github.com/ayufan-research/camera-streamer.git --recursive --shallow-submodules && \
        cd camera-streamer && \
        make && \
        make install && \
        sed -i 's/"$@"/$(echo -n "$@" | sed "s\/--virtual-address.*\/\/")/' ./tools/libcamera_camera.sh; \
    else \
        apt-get install -y libjpeg62-turbo-dev v4l-utils; \
    fi;

RUN git clone https://github.com/ArduCAM/mjpg-streamer.git &&\
    cd mjpg-streamer/mjpg-streamer-experimental &&\
    sed -i 's/add_subdirectory(plugins\/input_raspicam)//g' CMakeLists.txt &&\
    make -j$(( $(nproc --all) * 2 )) &&\
    make install

# Install the ping tool
RUN apt-get update && apt-get install -y inetutils-ping

# Install the Composer PHP package manager
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install udev
RUN apt-get update &&\
    apt-get install -y udev

WORKDIR /var/www

ENTRYPOINT [ "./internal/run.sh" ]