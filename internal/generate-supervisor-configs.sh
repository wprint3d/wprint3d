#!/bin/bash
# Generates supervisord program .conf files based on active roles
# Usage: generate-supervisor-configs.sh role1 [role2 ...]
# Writes to /tmp/supervisor/*.conf (included by supervisord.conf)

set -euo pipefail

SUPERVISOR_CONF_DIR="/tmp/supervisor"
RUNTIME_LOG_DIR="${WPRINT3D_RUNTIME_LOG_DIR:-/tmp/supervisor/logs}"
SUPERVISOR_LOG_DIR="${RUNTIME_LOG_DIR%/}/supervisor"

mkdir -p "$SUPERVISOR_CONF_DIR" "$SUPERVISOR_LOG_DIR"

# Clean stale configs from prior runs to prevent loading leftover programs
rm -f "$SUPERVISOR_CONF_DIR"/*.conf

supervisor_log_options() {
    local logfile="$1"

    cat <<EOF
redirect_stderr=true
stdout_logfile=${logfile}
stdout_logfile_maxbytes=512KB
stdout_logfile_backups=1
EOF
}

for role in "$@"; do
    case "$role" in
        scheduler)
            cat > "$SUPERVISOR_CONF_DIR/cron.conf" <<EOF
[program:cron]
command=cron -f
autorestart=true
$(supervisor_log_options "$SUPERVISOR_LOG_DIR/cron.log")
EOF
            ;;

        concurrency-scheduler)
            cat > "$SUPERVISOR_CONF_DIR/poll-serial-connections.conf" <<EOF
[program:poll-serial-connections]
command=php artisan concurrent:run-indefinitely --services=PollSerialConnections
directory=/var/www
autorestart=true
$(supervisor_log_options "$SUPERVISOR_LOG_DIR/poll-serial-connections.log")
EOF

            cat > "$SUPERVISOR_CONF_DIR/refresh-printer-workers.conf" <<EOF
[program:refresh-printer-workers]
command=php artisan concurrent:run-indefinitely --services=RefreshPrinterWorkers
directory=/var/www
autorestart=true
$(supervisor_log_options "$SUPERVISOR_LOG_DIR/refresh-printer-workers.log")
EOF
            ;;

        server)
            # Determine serve command based on the selected server driver.
            if [[ "${PHP_SERVER_DRIVER:-}" == 'fpm' ]]; then
                SERVE_CMD="php-fpm -F -R"
            elif [[ "${WPRINT3D_OCTANE_ENABLED:-}" == 'true' ]]; then
                SERVE_CMD="php artisan octane:start --host 0.0.0.0 --port 80"
            else
                SERVE_CMD="php artisan serve --host 0.0.0.0 --port 80"
            fi

            cat > "$SUPERVISOR_CONF_DIR/server.conf" <<EOF
[program:server]
command=${SERVE_CMD}
directory=/var/www
autorestart=true
$(supervisor_log_options "$SUPERVISOR_LOG_DIR/server.log")
EOF
            ;;

        ws-server)
            cat > "$SUPERVISOR_CONF_DIR/reverb.conf" <<EOF
[program:reverb]
command=php artisan reverb:start --host 0.0.0.0 --port 6001
directory=/var/www
autorestart=true
$(supervisor_log_options "$SUPERVISOR_LOG_DIR/reverb.log")
EOF
            ;;

        *)
            echo "[generate-supervisor-configs] Unknown role: $role, skipping" >&2
            ;;
    esac
done

echo "[generate-supervisor-configs] Generated configs for: $*" >&2
