FROM php:8.2

# Node.js
RUN curl -sL https://deb.nodesource.com/setup_16.x -o /tmp/nodesource_setup.sh &&\
    bash /tmp/nodesource_setup.sh;

# Basic dependencies
RUN apt-get install -y coreutils git curl libcurl4-openssl-dev libxml2-dev libzip-dev nodejs fswebcam procps cmake libjpeg62-turbo-dev v4l-utils ffmpeg usbutils docker.io

# Build and install MJPG Streamer
RUN git clone https://github.com/jacksonliam/mjpg-streamer &&\
    cd mjpg-streamer/mjpg-streamer-experimental &&\
    make -j$(( $(nproc --all) * 2 )) &&\
    make install

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

# Install several officially supported PHP extensions: cURL, XML, ZIP, DOM, MySQLi and PDO MySQL.
RUN docker-php-ext-install -j$(( $(nproc --all) * 2 )) curl xml zip dom mysqli pdo_mysql

# Install the Composer PHP package manager
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install udev
RUN apt-get update &&\
    apt-get install -y udev

WORKDIR /var/www

ENTRYPOINT [ "./internal/run.sh" ]