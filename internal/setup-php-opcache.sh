#!/bin/bash
# Generates php-opcache.ini from template using environment variable substitution
# PHP INI files don't support ${VAR} syntax, so we use envsubst

TEMPLATE_FILE="/var/www/internal/php-opcache.ini.template"
OUTPUT_FILE="/usr/local/etc/php/conf.d/opcache.ini"

# Default values if not set
export WPRINT3D_OPCACHE_REVALIDATE_FREQ="${WPRINT3D_OPCACHE_REVALIDATE_FREQ:-0}"
export WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS="${WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS:-0}"

# Disable OPcache preload for roles that don't benefit from it:
# - server: Octane/Swoole keeps classes resident, preload conflicts with Swoole
# - mapper: Long-running udev monitor, not PHP
# - streamer: Native binaries, not PHP
case "${ROLE:-}" in
    server|mapper|streamer)
        export WPRINT3D_OPCACHE_PRELOAD=""
        ;;
    *)
        export WPRINT3D_OPCACHE_PRELOAD="/var/www/internal/php-opcache-preload.php"
        ;;
esac

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
