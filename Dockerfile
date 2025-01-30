FROM php:8.3

SHELL ["/bin/bash", "-c"]

# Basic dependencies
RUN apt-get update && apt-get install -y --no-install-recommends coreutils git curl gnupg libcurl4-openssl-dev libxml2-dev libzip-dev nodejs npm fswebcam procps build-essential usbutils docker.io libssl-dev pkg-config &&\
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

# PHP extensions: MongoDB
RUN pecl install -f --onlyreqdeps --nobuild mongodb      &&\
    cd "$(pecl config-get temp_dir)/mongodb"             &&\
    phpize                                               &&\
    ./configure                                          &&\
    make -j$(nproc --all) && make install                &&\
    cd / && rm -rf "$(pecl config-get temp_dir)/mongodb"

# PHP extensions: Redis
RUN pecl install -f --onlyreqdeps --nobuild redis        &&\
    cd "$(pecl config-get temp_dir)/redis"               &&\
    phpize                                               &&\
    ./configure                                          &&\
    make -j$(nproc --all) && make install                &&\
    cd / && rm -rf "$(pecl config-get temp_dir)/redis"

# PHP extensions: DIO
RUN pecl install -f --onlyreqdeps --nobuild dio          &&\
    cd "$(pecl config-get temp_dir)/dio"                 &&\
    phpize                                               &&\
    ./configure                                          &&\
    make -j$(nproc --all) && make install                &&\
    cd / && rm -rf "$(pecl config-get temp_dir)/dio"

# PHP extensions: Swoole
RUN pecl install -f --onlyreqdeps --nobuild swoole       &&\
    cd "$(pecl config-get temp_dir)/swoole"              &&\
    phpize                                               &&\
    ./configure                                          &&\
    make -j$(nproc --all) && make install                &&\
    cd / && rm -rf "$(pecl config-get temp_dir)/swoole"

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
RUN apt-get update && apt-get install -y --no-install-recommends build-essential cmake meson python3 python3-pip python3-jinja2 python3-ply python3-yaml libjpeg62-turbo-dev libtiff5-dev libpng-dev libavformat-dev libavutil-dev libavcodec-dev v4l-utils pkg-config xxd build-essential cmake libssl-dev libboost-program-options-dev libdrm-dev libexif-dev libglib2.0-dev libgstreamer-plugins-base1.0-dev &&\
    git clone https://github.com/raspberrypi/libcamera.git --depth 1 -b v0.2.0+rpt20240215 &&\
    cd libcamera &&\
    meson build --buildtype=release -Dpipelines=rpi/vc4 -Dipas=rpi/vc4 -Dv4l2=true -Dgstreamer=enabled -Dtest=false -Dlc-compliance=disabled -Dcam=disabled -Dqcam=disabled -Ddocumentation=disabled -Dpycamera=disabled &&\
    ninja -C build &&\
    ninja -C build install &&\
    cd .. &&\
    git clone https://github.com/raspberrypi/libcamera-apps.git --depth 1 -b v1.4.3 &&\
    cd libcamera-apps &&\
    meson setup build -Denable_libav=true -Denable_drm=true -Denable_egl=false -Denable_qt=false -Denable_opencv=false -Denable_tflite=false &&\
    meson compile -C build -j"$(nproc --all)" &&\
    meson install -C build &&\
    cd .. &&\
    git clone https://github.com/ayufan/camera-streamer.git -b v0.2.8 --depth 1 --recursive --shallow-submodules &&\
    cd camera-streamer &&\
    make -j$(( "$(nproc --all)" * 2 )) && make install &&\
    cd .. &&\
    apt-get purge -y meson python3-pip python3-jinja2 python3-ply python3-yaml build-essential cmake libboost-program-options-dev libdrm-dev libexif-dev libglib2.0-dev libgstreamer-plugins-base1.0-dev &&\
    apt-get autoremove -y &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    rm -rf libcamera libcamera-apps camera-streamer/.git &&\
    ldconfig

# MJPG Streamer
RUN apt-get update && apt-get install -y --no-install-recommends cmake &&\
    git clone https://github.com/ArduCAM/mjpg-streamer.git -b v1.0.2 --depth 1 &&\
    cd mjpg-streamer/mjpg-streamer-experimental &&\
    sed -i 's/add_subdirectory(plugins\/input_raspicam)//g' CMakeLists.txt &&\
    make -j$(( "$(nproc --all)" * 2 )) &&\
    make install &&\
    cd ../.. &&\
    rm -rf mjpg-streamer/.git &&\
    apt-get purge -y cmake &&\
    apt-get autoremove -y &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# ustreamer
RUN apt-get update && apt install -y --no-install-recommends build-essential cmake libevent-dev libjpeg-dev libbsd-dev &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    git clone --depth=1 https://github.com/pikvm/ustreamer &&\
    cd ustreamer &&\
    make -j$(( "$(nproc --all)" * 2 )) &&\
    make install &&\
    cd .. &&\
    rm -rf ustreamer &&\
    apt-get purge -y build-essential cmake &&\
    apt-get autoremove -y &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# ustreamer
RUN apt-get update && apt-get install -y --no-install-recommends build-essential libevent-dev libjpeg-dev libbsd-dev &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/* &&\
    git clone --depth=1 https://github.com/pikvm/ustreamer &&\
    cd ustreamer &&\
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
    pecl install -f --onlyreqdeps --nobuild memcached       &&\
    cd "$(pecl config-get temp_dir)/memcached"              &&\
    phpize                                                  &&\
    ./configure                                             &&\
    make -j$(nproc --all) && make install                   &&\
    cd / && rm -rf "$(pecl config-get temp_dir)/memcached"  &&\
    docker-php-ext-enable memcached

# Install ffmpeg
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# PHP extension: intl
RUN docker-php-ext-install -j$(( $(nproc --all) * 2 )) intl

# Install unzip
RUN apt-get update && apt-get install -y --no-install-recommends unzip &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install supervisor(d)
RUN apt-get update && apt-get install -y --no-install-recommends supervisor &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install pstree (psmisc)
RUN apt-get update && apt-get install -y --no-install-recommends psmisc &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install jq (lightweight JSON processor)
RUN apt-get update && apt-get install -y --no-install-recommends jq &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install gcodestat
RUN git clone https://github.com/wprint3d/gcodestat /root/gcodestat --depth 1 &&\
    cd /root/gcodestat &&\
    sed -i'' 's/CFLAGS := -Wall -Werror/CFLAGS := -Wall/' Makefile &&\
    make STATIC=0 NOCURL=1 -j$( nproc --all ) &&\
    mv gcodestat.exe gcodestat &&\
    find -not -name 'gcodestat' -delete

# Install cron(tab)
RUN apt-get update && apt-get install -y --no-install-recommends cron &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install uuidgen
RUN apt-get update && apt-get install -y --no-install-recommends uuid-runtime &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install cron(tab)
RUN apt-get update && apt-get install -y --no-install-recommends cron &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

# Install uuidgen
RUN apt-get update && apt-get install -y --no-install-recommends uuid-runtime &&\
    apt-get clean &&\
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

ENTRYPOINT [ "./internal/run.sh" ]