#!/bin/bash
# Optional application-level caching setup
# Enabled via WPRINT3D_APP_CACHE_ENABLED=true
# Primes config and route caches, pre-warms artisan commands

# Logging function
log_info() {
    echo "[app-cache-setup] $*" >&2
}

setup_app_cache() {
    # Check if application caching is enabled
    if [[ "${WPRINT3D_APP_CACHE_ENABLED:-false}" != "true" ]]; then
        log_info "Application caching disabled (set WPRINT3D_APP_CACHE_ENABLED=true)"
        return 0
    fi

    log_info "Setting up application-level caching..."

    # Change to application directory
    cd /var/www || return 1

    # Pre-warm common artisan commands in OPcache
    log_info "Pre-warming artisan commands..."
    local commands=(
        "schedule:run"
        "queue:work"
        "migrate"
        "cache:clear"
        "config:clear"
        "route:clear"
    )

    for cmd in "${commands[@]}"; do
        php artisan "$cmd" --help >/dev/null 2>&1 && log_info "Pre-warmed: $cmd" || true
    done

    # Prime config cache
    log_info "Priming config cache..."
    php artisan config:cache || log_info "Config cache skipped (may not be available yet)"

    # Prime route cache
    log_info "Priming route cache..."
    php artisan route:cache || log_info "Route cache skipped (may not be available yet)"

    log_info "Application cache setup complete"
    return 0
}

# Run setup
setup_app_cache
exit $?
