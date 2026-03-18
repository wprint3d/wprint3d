#!/bin/bash
# Generates php-opcache.ini from template using environment variable substitution
# PHP INI files don't support ${VAR} syntax, so we use envsubst

TEMPLATE_FILE="/var/www/internal/php-opcache.ini.template"
OUTPUT_FILE="/usr/local/etc/php/conf.d/opcache.ini"

# Default values if not set
export WPRINT3D_OPCACHE_REVALIDATE_FREQ="${WPRINT3D_OPCACHE_REVALIDATE_FREQ:-0}"
export WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS="${WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS:-0}"

# Disable OPcache preload for all roles:
# - server: Octane/Swoole keeps classes resident, preload conflicts with Swoole
# - worker roles: preload fires on every CLI invocation (queue:work restart,
#   scheduler cron, artisan commands), adding startup overhead and log spam
#   with no benefit — OPcache bytecode caching is sufficient
export WPRINT3D_OPCACHE_PRELOAD=""

# Disable JIT for server role — JIT tracing mode causes OpenSwoole request hangs.
# Bytecode caching (OPcache core) still provides the main performance benefit.
# JIT is fine for CLI workers (scheduler, ws-server) since they don't use Swoole.
if [[ "${ROLE:-}" == 'server' ]] || [[ "${ROLE:-}" == *server* && "${OCTANE_ENABLED:-}" == 'true' ]]; then
    export WPRINT3D_OPCACHE_JIT="off"
    export WPRINT3D_OPCACHE_JIT_BUFFER_SIZE="0"
else
    export WPRINT3D_OPCACHE_JIT="tracing"
    export WPRINT3D_OPCACHE_JIT_BUFFER_SIZE="64M"
fi

# Create file cache directory
mkdir -p /tmp/opcache

# Generate actual INI file from template
if [[ -f "$TEMPLATE_FILE" ]]; then
    envsubst < "$TEMPLATE_FILE" > "$OUTPUT_FILE"
    echo "OPcache configuration generated at $OUTPUT_FILE" >&2
else
    echo "ERROR: Template file not found: $TEMPLATE_FILE" >&2
    exit 1
fi

exit 0
