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

# Build and install MJPG Streamer
#
# In this block, we check for the existence of the file /opt/vc/LICENCE, if it
# does exist, the RPi camera dependencies are bundled with the image.
RUN curl -O 'https://archive.raspberrypi.org/debian/pool/main/r/raspberrypi-archive-keyring/raspberrypi-archive-keyring_2016.10.31_all.deb' &&\
    dpkg -i ./raspberrypi-archive-keyring_2016.10.31_all.deb &&\
    if [ -e /opt/vc/LICENCE ]; then \
        echo 'deb http://archive.raspberrypi.org/debian/ bullseye main' >> /etc/apt/sources.list.d/raspi.list; \
    fi &&\
    apt-get update &&\
    if [ -e /opt/vc/LICENCE ]; then \
        apt-get install -y libjpeg62-turbo-dev libcamera-dev liblivemedia-dev v4l-utils; \
    else \
        apt-get install -y libjpeg62-turbo-dev v4l-utils; \
    fi &&\
    git clone https://github.com/ArduCAM/mjpg-streamer.git &&\
    cd mjpg-streamer/mjpg-streamer-experimental &&\
    make -j$(( $(nproc --all) * 2 )) &&\
    make install

# Install the Composer PHP package manager
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install udev
RUN apt-get update &&\
    apt-get install -y udev

WORKDIR /var/www

ENTRYPOINT [ "./internal/run.sh" ]