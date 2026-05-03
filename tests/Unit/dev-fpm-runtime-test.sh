#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 && pwd -P)"

cd "$ROOT_DIR"

if ! rg -n '^FROM docker\.io/library/php:8\.4\.12-fpm-bookworm$' Dockerfile.dev > /dev/null; then
    echo 'Dockerfile.dev no longer uses the php-fpm base image.'
    exit 1
fi

if ! rg -n 'php-fpm -F -R' internal/generate-supervisor-configs.sh > /dev/null; then
    echo 'Supervisor generation no longer starts php-fpm for the server role.'
    exit 1
fi

if ! rg -n 'php-fpm-dev-www\.conf' Dockerfile.dev > /dev/null; then
    echo 'Dockerfile.dev no longer installs the dev php-fpm pool override.'
    exit 1
fi

if ! awk '
    /^# Basic dependencies$/ { in_basic_deps = 1; next }
    in_basic_deps && /apt-get clean/ { exit found ? 0 : 1 }
    in_basic_deps && /v4l-utils/ { found = 1 }
    END { if (in_basic_deps && ! found) exit 1 }
' Dockerfile.dev; then
    echo 'Dockerfile.dev basic dependencies no longer install v4l-utils, which hardware camera detection needs for v4l2-ctl.'
    exit 1
fi

if ! rg -n '^user = root$|^group = root$' internal/php-fpm-dev-www.conf > /dev/null; then
    echo 'The dev php-fpm pool override no longer runs workers as root.'
    exit 1
fi

if ! rg -n 'include\s+/etc/nginx/backend-upstream\.conf;' proxy/conf.d/default.conf > /dev/null; then
    echo 'Proxy default config no longer delegates backend upstream handling to a shared include.'
    exit 1
fi

if ! rg -n 'proxy_pass\s+http://backend:80;' proxy/backend-upstream-http.conf > /dev/null; then
    echo 'The production backend upstream include no longer proxies HTTP traffic to backend:80.'
    exit 1
fi

if ! rg -n 'fastcgi_pass\s+backend:9000;' proxy/backend-upstream-fastcgi-dev.conf > /dev/null; then
    echo 'The development backend upstream include no longer proxies FastCGI traffic to backend:9000.'
    exit 1
fi

if ! rg -n '\- \.:/var/www:ro' docker-compose-development.yml > /dev/null; then
    echo 'Development proxy no longer mounts the application source read-only for FastCGI script resolution.'
    exit 1
fi

if ! rg -n 'backend-upstream-fastcgi-dev\.conf:/etc/nginx/backend-upstream\.conf:ro' docker-compose-development.yml > /dev/null; then
    echo 'Development proxy no longer overrides backend-upstream.conf with the FastCGI variant.'
    exit 1
fi

if ! awk '
    $0 == "  proxy:" { in_proxy = 1; next }
    in_proxy && /^  [A-Za-z0-9_-]+:/ { exit }
    in_proxy && /\/var\/log\/wprint3d:size=20m,mode=1777/ { found = 1 }
    END { exit found ? 0 : 1 }
' docker-compose-development.yml; then
    echo 'Development proxy no longer mounts the runtime log tmpfs required by nginx error_log.'
    exit 1
fi

if ! rg -n 'internal/php-fpm-dev-www\.conf:/usr/local/etc/php-fpm\.d/zz-wprint3d-dev\.conf:ro' docker-compose-development.yml > /dev/null; then
    echo 'Development backend no longer mounts the dev php-fpm pool override into the container.'
    exit 1
fi

echo 'development php-fpm runtime wiring checks passed'
