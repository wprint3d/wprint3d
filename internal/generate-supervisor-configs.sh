#!/bin/bash
# Generates supervisord program .conf files based on active roles
# Usage: generate-supervisor-configs.sh role1 [role2 ...]
# Writes to /tmp/supervisor/*.conf (included by supervisord.conf)

set -euo pipefail

SUPERVISOR_CONF_DIR="/tmp/supervisor"
SUPERVISOR_LOG_DIR="/tmp/supervisor/logs"

mkdir -p "$SUPERVISOR_CONF_DIR" "$SUPERVISOR_LOG_DIR"

# Clean stale configs from prior runs to prevent loading leftover programs
rm -f "$SUPERVISOR_CONF_DIR"/*.conf

for role in "$@"; do
    case "$role" in
        scheduler)
            cat > "$SUPERVISOR_CONF_DIR/cron.conf" <<'EOF'
[program:cron]
command=cron -f
autorestart=true
stdout_logfile=/tmp/supervisor/logs/cron.log
stderr_logfile=/tmp/supervisor/logs/cron.log
EOF
            ;;

        concurrency-scheduler)
            cat > "$SUPERVISOR_CONF_DIR/concurrent-run.conf" <<'EOF'
[program:concurrent-run]
command=php artisan concurrent:run-indefinitely
directory=/var/www
autorestart=true
stdout_logfile=/tmp/supervisor/logs/concurrent-run.log
stderr_logfile=/tmp/supervisor/logs/concurrent-run.log
EOF

            cat > "$SUPERVISOR_CONF_DIR/log-rotator.conf" <<'EOF'
[program:log-rotator]
command=bash -c 'while true; do for log in /tmp/supervisor/logs/*.log; do truncate --size 512K "$log"; done; sleep 60; done'
autorestart=true
stdout_logfile=/dev/null
stderr_logfile=/dev/null
EOF
            ;;

        ws-server)
            cat > "$SUPERVISOR_CONF_DIR/reverb.conf" <<'EOF'
[program:reverb]
command=php artisan reverb:start --host 0.0.0.0 --port 6001
directory=/var/www
autorestart=true
stdout_logfile=/tmp/supervisor/logs/reverb.log
stderr_logfile=/tmp/supervisor/logs/reverb.log
EOF
            ;;

        *)
            echo "[generate-supervisor-configs] Unknown role: $role, skipping" >&2
            ;;
    esac
done

echo "[generate-supervisor-configs] Generated configs for: $*" >&2
