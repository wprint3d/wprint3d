# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=docker.io/library/php:8.4.12-fpm-bookworm
ARG DEBIAN_IMAGE=docker.io/library/debian:bookworm-slim
ARG COMPOSER_IMAGE=docker.io/library/composer:2
ARG DOCKER_CLI_IMAGE=docker.io/library/docker:28-cli
ARG NODE_IMAGE=docker.io/library/node:22-alpine

FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${DOCKER_CLI_IMAGE} AS docker-cli

FROM ${PHP_IMAGE} AS php-extension-builder

ARG MONGODB_VERSION=1.21.0
ARG REDIS_VERSION=6.3.0
ARG DIO_VERSION=0.3.0
ARG OPENSWOOLE_VERSION=26.2.0
ARG MEMCACHED_VERSION=3.4.0
ARG YAML_VERSION=2.3.0

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    --mount=type=cache,target=/tmp/pear/cache,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        unzip \
        libcurl4-openssl-dev \
        libevent-dev \
        libicu-dev \
        libmemcached-dev \
        libssl-dev \
        libxml2-dev \
        libyaml-dev \
        libzip-dev \
    && build_pecl_extension() { \
        extension="$1"; \
        version="$2"; \
        pecl install -f --onlyreqdeps --nobuild "${extension}-${version}"; \
        extension_dir="$(pecl config-get temp_dir)/${extension}"; \
        cd "$extension_dir"; \
        phpize; \
        ./configure; \
        make -j"$(nproc)"; \
        make install; \
        cd /; \
        rm -rf "$extension_dir"; \
    }; \
    build_pecl_extension mongodb "$MONGODB_VERSION"; \
    build_pecl_extension redis "$REDIS_VERSION"; \
    build_pecl_extension dio "$DIO_VERSION"; \
    build_pecl_extension openswoole "$OPENSWOOLE_VERSION"; \
    build_pecl_extension memcached "$MEMCACHED_VERSION"; \
    build_pecl_extension yaml "$YAML_VERSION"; \
    docker-php-ext-enable mongodb redis dio openswoole memcached yaml \
    && docker-php-ext-install -j"$(nproc)" curl xml zip dom mysqli pdo_mysql sockets pcntl intl \
    && find /usr/local/lib/php/extensions -type f -name '*.so' -exec strip --strip-unneeded '{}' + \
    && ! find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' \; | grep -q 'not found' \
    && rm -rf /tmp/pear/download /tmp/pear/temp /usr/src/php

FROM php-extension-builder AS composer-vendor

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --no-progress

FROM composer-vendor AS composer-dependencies

COPY . .

RUN APP_ENV=testing composer dump-autoload \
        --no-dev \
        --no-scripts \
        --classmap-authoritative \
        --no-interaction \
    && composer check-platform-reqs --no-dev

FROM ${NODE_IMAGE} AS license-builder

ARG PNPM_VERSION=10.34.5

RUN apk add --no-cache bash \
    && corepack enable \
    && corepack prepare pnpm@${PNPM_VERSION} --activate

WORKDIR /workspace

COPY --from=composer-vendor /var/www/vendor ./vendor
COPY _STATIC_THIRD_PARTY_LICENSES.txt ./
COPY internal/refresh-third-party-licenses.sh ./internal/refresh-third-party-licenses.sh
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml frontend/.npmrc ./frontend/

RUN --mount=type=cache,target=/pnpm/store,sharing=locked \
    install -d /licenses \
    && cd frontend \
    && pnpm config set store-dir /pnpm/store \
    && pnpm fetch --frozen-lockfile \
    && pnpm install --offline --frozen-lockfile --ignore-scripts \
    && cd /workspace \
    && bash internal/refresh-third-party-licenses.sh /workspace /licenses/THIRD_PARTY_LICENSES.txt

FROM ${PHP_IMAGE} AS camera-builder

ARG LIBCAMERA_COMMIT=d83ff0a4ae4503bc56b7ed48cd142c3dd423ad3b
ARG CAMERA_STREAMER_COMMIT=dbdba86ea8ef7faf7d8900c32629510bc3e4c693
ARG USTREAMER_COMMIT=b5e12a841270fedea75aa03b05de8fda15a3745e

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
        git \
        libbsd-dev \
        libevent-dev \
        libjpeg62-turbo-dev \
        pkg-config \
    && install -d /camera-root/usr/local/bin \
    && git init /tmp/ustreamer \
    && git -C /tmp/ustreamer remote add origin https://github.com/pikvm/ustreamer.git \
    && git -C /tmp/ustreamer fetch --depth 1 origin "${USTREAMER_COMMIT}" \
    && git -C /tmp/ustreamer checkout --detach FETCH_HEAD \
    && make -C /tmp/ustreamer -j"$(nproc)" \
    && make -C /tmp/ustreamer DESTDIR=/camera-root install-strip \
    && if [ "$(dpkg --print-architecture)" = 'arm64' ]; then \
        apt-get install -y --no-install-recommends \
            cmake \
            debhelper \
            dh-make \
            libavcodec-dev \
            libavformat-dev \
            libavutil-dev \
            libboost-program-options-dev \
            libcurl4-openssl-dev \
            libdrm-dev \
            libexif-dev \
            libglib2.0-dev \
            libgstreamer-plugins-base1.0-dev \
            libpng-dev \
            libssl-dev \
            libtiff5-dev \
            libv4l-dev \
            meson \
            ninja-build \
            python3-jinja2 \
            python3-ply \
            python3-yaml \
            v4l-utils \
            xxd; \
        git init /tmp/libcamera; \
        git -C /tmp/libcamera remote add origin https://github.com/raspberrypi/libcamera.git; \
        git -C /tmp/libcamera fetch --depth 1 origin "${LIBCAMERA_COMMIT}"; \
        git -C /tmp/libcamera checkout --detach FETCH_HEAD; \
        meson setup /tmp/libcamera/build /tmp/libcamera \
            --buildtype=release \
            -Dpipelines=rpi/vc4 \
            -Dipas=rpi/vc4 \
            -Dv4l2=true \
            -Dgstreamer=enabled \
            -Dtest=false \
            -Dlc-compliance=disabled \
            -Dcam=disabled \
            -Dqcam=disabled \
            -Ddocumentation=disabled \
            -Dpycamera=disabled; \
        ninja -C /tmp/libcamera/build install; \
        DESTDIR=/camera-root ninja -C /tmp/libcamera/build install; \
        git init /tmp/camera-streamer; \
        git -C /tmp/camera-streamer remote add origin https://github.com/wprint3d/camera-streamer.git; \
        git -C /tmp/camera-streamer fetch --depth 1 origin "${CAMERA_STREAMER_COMMIT}"; \
        git -C /tmp/camera-streamer checkout --detach FETCH_HEAD; \
        git -C /tmp/camera-streamer submodule update --init --recursive --depth 1; \
        make -C /tmp/camera-streamer -j"$(nproc)"; \
        make -C /tmp/camera-streamer DESTDIR=/camera-root install; \
        strip --strip-unneeded /camera-root/usr/local/bin/camera-streamer; \
    fi \
    && ldconfig \
    && if ldd /camera-root/usr/local/bin/ustreamer 2>&1 | grep -q 'not found'; then \
        exit 1; \
    fi \
    && if [ "$(dpkg --print-architecture)" = 'arm64' ] \
        && ldd /camera-root/usr/local/bin/camera-streamer 2>&1 | grep -q 'not found'; then \
        exit 1; \
    fi

FROM ${DEBIAN_IMAGE} AS php-runtime

ENV PHP_INI_DIR=/usr/local/etc/php

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        coreutils \
        curl \
        gettext-base \
        libargon2-1 \
        libcurl4 \
        libevent-2.1-7 \
        libicu72 \
        libmemcached11 \
        libonig5 \
        libreadline8 \
        libsasl2-2 \
        libsodium23 \
        libsqlite3-0 \
        libssl3 \
        libstdc++6 \
        libxml2 \
        libyaml-0-2 \
        libzip4 \
        procps \
        redis-tools \
        rsync \
        zlib1g

COPY --from=php-extension-builder /usr/local/bin/php /usr/local/bin/php
COPY --from=php-extension-builder /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm
COPY --from=php-extension-builder /usr/local/lib/php/ /usr/local/lib/php/
COPY --from=php-extension-builder /usr/local/etc/php/ /usr/local/etc/php/
COPY --from=php-extension-builder /usr/local/etc/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY --from=php-extension-builder /usr/local/etc/php-fpm.d/ /usr/local/etc/php-fpm.d/

COPY internal/limits.ini /usr/local/etc/php/conf.d/limits.ini

WORKDIR /var/www

RUN curl -fsSL https://raw.githubusercontent.com/vishnubob/wait-for-it/master/wait-for-it.sh \
        -o /usr/local/bin/wait-for-it \
    && chmod +x /usr/local/bin/wait-for-it

FROM php-runtime AS runtime-base

ARG VCS_REF=unknown

LABEL org.opencontainers.image.revision="${VCS_REF}"

COPY --from=composer-dependencies /var/www /var/www
COPY --from=license-builder /licenses/THIRD_PARTY_LICENSES.txt /var/www/THIRD_PARTY_LICENSES.txt

RUN printf '%s' "${VCS_REF}" | cut -c1-7 > /var/www/internal/app_ver

ENTRYPOINT ["/var/www/internal/run.sh"]

FROM php-runtime AS mapper-runtime

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        fswebcam \
        usbutils \
        udev \
        v4l-utils

FROM mapper-runtime AS mapper

ARG VCS_REF=unknown

LABEL org.opencontainers.image.revision="${VCS_REF}"

COPY --from=runtime-base /var/www /var/www

ENTRYPOINT ["/var/www/internal/run.sh"]

FROM php-runtime AS streamer-runtime

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        inotify-tools \
        libbsd0 \
        libevent-pthreads-2.1-7 \
        libjpeg62-turbo \
        psmisc \
        udev \
        v4l-utils \
    && if [ "$(dpkg --print-architecture)" = 'arm64' ]; then \
        apt-get install -y --no-install-recommends \
            libavcodec59 \
            libavformat59 \
            libavutil57 \
            libboost-program-options1.74.0 \
            libdrm2 \
            libexif12 \
            libglib2.0-0 \
            libgstreamer-plugins-base1.0-0 \
            libgstreamer1.0-0 \
            libpng16-16 \
            libtiff6; \
    fi

COPY --from=camera-builder /camera-root/ /
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

RUN ln -s /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose \
    && ldconfig

FROM streamer-runtime AS streamer

ARG VCS_REF=unknown

LABEL org.opencontainers.image.revision="${VCS_REF}"

COPY --from=runtime-base /var/www /var/www

ENTRYPOINT ["/var/www/internal/run.sh"]

FROM php-runtime AS development

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        cron \
        ffmpeg \
        fswebcam \
        git \
        inotify-tools \
        libevent-pthreads-2.1-7 \
        psmisc \
        supervisor \
        usbutils \
        udev \
        unzip \
        uuid-runtime \
        v4l-utils \
    && if [ "$(dpkg --print-architecture)" = 'arm64' ]; then \
        apt-get install -y --no-install-recommends \
            libboost-program-options1.74.0 \
            libdrm2 \
            libexif12 \
            libgstreamer-plugins-base1.0-0 \
            libgstreamer1.0-0 \
            libpng16-16 \
            libtiff6; \
    fi

COPY --from=camera-builder /camera-root/ /
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

COPY internal/php-fpm-dev-www.conf /usr/local/etc/php-fpm.d/zz-wprint3d-dev.conf
COPY internal/php-opcache.ini.template /var/www/internal/php-opcache.ini.template
COPY internal/setup-php-opcache.sh /var/www/internal/setup-php-opcache.sh
COPY internal/php-opcache-preload.php /var/www/internal/php-opcache-preload.php
COPY internal/detect-hardware.sh /var/www/internal/detect-hardware.sh
COPY internal/ramdisk-setup.sh /var/www/internal/ramdisk-setup.sh
COPY internal/app-cache-setup.sh /var/www/internal/app-cache-setup.sh

RUN ln -s /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose \
    && ldconfig \
    && chmod +x \
        /var/www/internal/setup-php-opcache.sh \
        /var/www/internal/detect-hardware.sh \
        /var/www/internal/ramdisk-setup.sh \
        /var/www/internal/app-cache-setup.sh

ENTRYPOINT ["/var/www/internal/run.sh"]

FROM php-runtime AS backend-runtime

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        cron \
        ffmpeg \
        supervisor \
        unzip \
        uuid-runtime

COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

RUN ln -s /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/bin/docker-compose

FROM backend-runtime AS production

ARG VCS_REF=unknown

LABEL org.opencontainers.image.revision="${VCS_REF}"

COPY --from=runtime-base /var/www /var/www

ENTRYPOINT ["/var/www/internal/run.sh"]

FROM production AS backend
