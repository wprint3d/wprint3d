#!/bin/bash

set -euo pipefail

registry="${REGISTRY:-docker.io/wprint3d}"
tag="${IMAGE_TAG:-latest}"

backend_image="${registry}/wprint3d:${tag}"
mapper_image="${registry}/wprint3d-mapper:${tag}"
streamer_image="${registry}/wprint3d-streamer:${tag}"
proxy_image="${registry}/wprint3d-proxy:${tag}"
frontend_image="${registry}/wprint3d-frontend:${tag}"

assert_php_runtime() {
    local image="$1"

    docker run --rm \
        --env PUSHER_APP_ID=smoke \
        --env PUSHER_APP_KEY=smoke \
        --env PUSHER_APP_SECRET=smoke \
        --entrypoint bash \
        "$image" \
        -lc '
        php -r "require \"/var/www/vendor/autoload.php\";"
        APP_ENV=testing php artisan --version
        php-fpm -tt > /dev/null
        php -r "foreach ([\"mongodb\", \"redis\", \"dio\", \"openswoole\", \"memcached\", \"yaml\", \"curl\", \"dom\", \"intl\", \"mysqli\", \"pcntl\", \"pdo_mysql\", \"sockets\", \"xml\", \"zip\"] as \$extension) { if (!extension_loaded(\$extension)) { fwrite(STDERR, \"Missing PHP extension: {\$extension}\\n\"); exit(1); } }"
        if find /usr/local/lib/php/extensions -type f -name "*.so" -exec ldd {} \; | grep -q "not found"; then
            echo "A PHP extension has an unresolved shared library." >&2
            exit 1
        fi
        test -s /var/www/THIRD_PARTY_LICENSES.txt
        test ! -e /var/www/.git
        test ! -d /var/www/vendor/phpunit
        test ! -d /var/www/vendor/laravel/pint
        ! command -v composer
        ! command -v gcc
        ! command -v make
    '
}

assert_php_runtime "$backend_image"
assert_php_runtime "$mapper_image"
assert_php_runtime "$streamer_image"

docker run --rm --entrypoint bash "$backend_image" -lc '
    command -v cron
    command -v docker
    command -v docker-compose
    docker compose version
    command -v ffmpeg
    command -v supervisord
    command -v uuidgen
'

docker run --rm --entrypoint bash "$mapper_image" -lc '
    command -v fswebcam
    command -v lsusb
    command -v udevadm
    command -v v4l2-ctl
    ! command -v docker
    ! command -v ffmpeg
'

docker run --rm --entrypoint bash "$streamer_image" -lc '
    command -v docker
    command -v docker-compose
    docker compose version
    command -v inotifywait
    command -v pstree
    command -v ustreamer
    if ldd "$(command -v ustreamer)" | grep -q "not found"; then
        exit 1
    fi
'

streamer_arch="$(docker image inspect "$streamer_image" --format '{{.Architecture}}')"

if [[ "$streamer_arch" == 'arm64' ]]; then
    docker run --rm --entrypoint bash "$streamer_image" -lc '
        command -v camera-streamer
        if ldd "$(command -v camera-streamer)" | grep -q "not found"; then
            exit 1
        fi
    '
fi

docker run --rm \
    --add-host backend:127.0.0.1 \
    --add-host web:127.0.0.1 \
    --tmpfs /var/log/wprint3d \
    --entrypoint nginx \
    "$proxy_image" \
    -t

frontend_container="wprint3d-frontend-smoke-${RANDOM}-${RANDOM}"

cleanup_frontend() {
    docker rm -f "$frontend_container" > /dev/null 2>&1 || true
}

trap cleanup_frontend EXIT

docker run --detach --name "$frontend_container" --publish 127.0.0.1::8081 "$frontend_image" > /dev/null
frontend_port="$(docker port "$frontend_container" 8081/tcp | sed 's/.*://')"

for _ in $(seq 1 20); do
    if curl --fail --silent "http://127.0.0.1:${frontend_port}/" > /dev/null; then
        exit 0
    fi

    sleep 1
done

docker logs "$frontend_container" >&2
echo 'Frontend image did not become ready.' >&2
exit 1
