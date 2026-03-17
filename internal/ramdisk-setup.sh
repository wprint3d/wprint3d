#!/bin/bash
# Full /var/www ramdisk with persistent path exclusions
# Copies entire /var/www to tmpfs, then bind-mounts persistent dirs back to disk
# Self-sizes based on actual data; skips if insufficient RAM or ineligible role
# Falls back gracefully — app works fine without ramdisk (OPcache still active)

set -euo pipefail

# Configuration
RAMDISK_MOUNT="/tmp/wprint3d-ramdisk"
APP_ROOT="/var/www"
STASH_DIR="/tmp/wprint3d-persistent-stash"

# Persistent directories (excluded from ramdisk, bind-mounted back to disk)
# These paths are relative to APP_ROOT
declare -a PERSISTENT_DIRS=(
    "storage/app/gcode"
    "storage/app/recordings"
    "storage/app/public"
    "storage/app/plugins"
    "storage/logs"
    ".external-configs"
)

# Roles eligible for ramdisk (processes that respawn fresh PHP)
declare -a ELIGIBLE_ROLES=(
    "scheduler"
    "concurrency-scheduler"
    "ws-server"
)

# Track successful bind mounts for rollback
declare -a COMPLETED_BINDS=()

# Logging
log_info() {
    echo "[ramdisk-setup] $*" >&2
}

log_warn() {
    echo "[ramdisk-setup WARNING] $*" >&2
}

log_error() {
    echo "[ramdisk-setup ERROR] $*" >&2
}

# Check if current role is eligible for ramdisk
check_role_eligible() {
    local role="${ROLE:-}"

    if [[ -z "$role" ]]; then
        log_info "No ROLE set, skipping ramdisk"
        return 1
    fi

    for eligible in "${ELIGIBLE_ROLES[@]}"; do
        if [[ "$role" == "$eligible" ]]; then
            return 0
        fi
    done

    log_info "Role '$role' uses resident processes, ramdisk not needed"
    return 1
}

# Measure the size of /var/www excluding persistent directories
# NOTE: du --exclude uses fnmatch against the relative path from the
# traversal root. We must cd into APP_ROOT and use '.' so that exclude
# patterns like "storage/app/gcode" match correctly.
measure_data_size() {
    local exclude_args=()

    for dir in "${PERSISTENT_DIRS[@]}"; do
        exclude_args+=(--exclude="./$dir")
    done

    # Also exclude .env if it's a regular file (not a symlink)
    if [[ -f "$APP_ROOT/.env" ]] && [[ ! -L "$APP_ROOT/.env" ]]; then
        exclude_args+=(--exclude="./.env")
    fi

    local size_bytes
    size_bytes=$(cd "$APP_ROOT" && du -sb "${exclude_args[@]}" . 2>/dev/null | tail -1 | awk '{print $1}')

    echo "${size_bytes:-0}"
}

# Run all pre-flight checks before creating ramdisk
# Returns 0 if ramdisk should proceed, 1 if it should be skipped
preflight_checks() {
    local storage_type="${WPRINT3D_STORAGE_TYPE:-unknown}"
    local available_mem="${WPRINT3D_AVAILABLE_MEMORY_MB:-0}"
    local force_ramdisk="${WPRINT3D_FORCE_RAMDISK:-auto}"

    # Explicit force takes precedence over everything (except insufficient RAM)
    if [[ "$force_ramdisk" == "0" ]]; then
        log_info "Ramdisk explicitly disabled via WPRINT3D_FORCE_RAMDISK=0"
        return 1
    fi

    # Check developer mode (skipped when force=1)
    if [[ "${DEVELOPER_MODE:-}" == "true" ]] && [[ "$force_ramdisk" != "1" ]]; then
        log_info "Developer mode active, skipping ramdisk (set WPRINT3D_FORCE_RAMDISK=1 to override)"
        return 1
    fi

    # Check role eligibility
    if ! check_role_eligible; then
        return 1
    fi

    # Skip on SSD unless forced
    if [[ "$storage_type" == "ssd" ]] && [[ "$force_ramdisk" != "1" ]]; then
        log_info "SSD detected, skipping ramdisk (set WPRINT3D_FORCE_RAMDISK=1 to enable)"
        return 1
    fi

    # Calculate required ramdisk size
    local data_size_bytes
    data_size_bytes=$(measure_data_size)

    if [[ "$data_size_bytes" -eq 0 ]]; then
        log_error "Failed to measure $APP_ROOT size"
        return 1
    fi

    # Add 10% headroom
    local ramdisk_size_bytes=$(( data_size_bytes + data_size_bytes / 10 ))
    local ramdisk_size_mb=$(( ramdisk_size_bytes / 1024 / 1024 ))

    # Ensure at least 1MB (avoid zero-size tmpfs)
    if [[ "$ramdisk_size_mb" -lt 1 ]]; then
        ramdisk_size_mb=1
    fi

    # Gate: available RAM must be >= 2x ramdisk size
    local required_mb=$(( ramdisk_size_mb * 2 ))

    if [[ "$available_mem" -lt "$required_mb" ]]; then
        log_warn "Insufficient RAM: ${available_mem}MB available, ${required_mb}MB required (2x ${ramdisk_size_mb}MB ramdisk)"
        return 1
    fi

    log_info "Pre-flight passed: storage=$storage_type, RAM=${available_mem}MB, ramdisk=${ramdisk_size_mb}MB"

    # Export size for use by setup function
    export RAMDISK_SIZE_MB="$ramdisk_size_mb"
    return 0
}

# Copy /var/www to ramdisk, excluding persistent directories
copy_to_ramdisk() {
    local exclude_args=()

    for dir in "${PERSISTENT_DIRS[@]}"; do
        exclude_args+=(--exclude="$dir")
    done

    # Exclude .env if it's a regular file
    if [[ -f "$APP_ROOT/.env" ]] && [[ ! -L "$APP_ROOT/.env" ]]; then
        exclude_args+=(--exclude=".env")
    fi

    log_info "Copying $APP_ROOT to ramdisk (excluding ${#PERSISTENT_DIRS[@]} persistent dirs)..."

    if command -v rsync &>/dev/null; then
        rsync -a "${exclude_args[@]}" "$APP_ROOT/" "$RAMDISK_MOUNT/"
    else
        # Fallback to tar if rsync not available
        log_warn "rsync not available, falling back to tar"

        local tar_excludes=()
        for dir in "${PERSISTENT_DIRS[@]}"; do
            tar_excludes+=(--exclude="$dir")
        done
        if [[ -f "$APP_ROOT/.env" ]] && [[ ! -L "$APP_ROOT/.env" ]]; then
            tar_excludes+=(--exclude=".env")
        fi

        tar -C "$APP_ROOT" "${tar_excludes[@]}" -cf - . | tar -C "$RAMDISK_MOUNT" -xf -
    fi

    # Create empty placeholder directories for persistent paths
    # These are needed as bind-mount targets after /var/www is overlaid
    for dir in "${PERSISTENT_DIRS[@]}"; do
        mkdir -p "$RAMDISK_MOUNT/$dir"
    done

    # Create .env placeholder if it's a regular file
    if [[ -f "$APP_ROOT/.env" ]] && [[ ! -L "$APP_ROOT/.env" ]]; then
        touch "$RAMDISK_MOUNT/.env"
    fi

    log_info "Copy complete"
}

# Rollback all mounts in reverse order
rollback_mounts() {
    log_warn "Rolling back ramdisk mounts..."

    # Unmount persistent dir bind mounts in reverse order
    for (( i=${#COMPLETED_BINDS[@]}-1; i>=0; i-- )); do
        local mount_path="${COMPLETED_BINDS[$i]}"
        if mountpoint -q "$mount_path" 2>/dev/null; then
            umount "$mount_path" 2>/dev/null && \
                log_info "Unmounted $mount_path" || \
                log_error "Failed to unmount $mount_path"
        fi
    done

    # Unmount the main /var/www overlay
    if mountpoint -q "$APP_ROOT" 2>/dev/null; then
        umount "$APP_ROOT" 2>/dev/null && \
            log_info "Unmounted $APP_ROOT overlay" || \
            log_error "Failed to unmount $APP_ROOT overlay"
    fi

    # Unmount stash bind mounts
    for dir in "${PERSISTENT_DIRS[@]}"; do
        if mountpoint -q "$STASH_DIR/$dir" 2>/dev/null; then
            umount "$STASH_DIR/$dir" 2>/dev/null || true
        fi
    done
    if mountpoint -q "$STASH_DIR/.env" 2>/dev/null; then
        umount "$STASH_DIR/.env" 2>/dev/null || true
    fi
    rm -rf "$STASH_DIR" 2>/dev/null || true

    # Unmount the tmpfs
    if mountpoint -q "$RAMDISK_MOUNT" 2>/dev/null; then
        umount "$RAMDISK_MOUNT" 2>/dev/null && \
            log_info "Unmounted tmpfs at $RAMDISK_MOUNT" || \
            log_error "Failed to unmount tmpfs at $RAMDISK_MOUNT"
    fi

    rmdir "$RAMDISK_MOUNT" 2>/dev/null || true
}

# Bind-mount persistent directories back from disk to ramdisk overlay
bind_persistent_dirs() {
    local saved_dir="$1"  # Where originals were saved before overlay

    for dir in "${PERSISTENT_DIRS[@]}"; do
        local source="$saved_dir/$dir"
        local target="$APP_ROOT/$dir"

        if [[ ! -d "$source" ]]; then
            log_warn "Persistent source $source does not exist, skipping"
            continue
        fi

        # Ensure mount target exists in ramdisk
        mkdir -p "$target"

        mount --bind "$source" "$target" && {
            COMPLETED_BINDS+=("$target")
            log_info "Bound persistent dir: $target -> disk"
        } || {
            log_error "Failed to bind $target"
            rollback_mounts
            return 1
        }
    done

    # Handle .env — only bind back if it's a regular file (not symlink)
    local env_source="$saved_dir/.env"
    local env_target="$APP_ROOT/.env"

    if [[ -f "$env_source" ]] && [[ ! -L "$env_source" ]]; then
        touch "$env_target" 2>/dev/null || true
        mount --bind "$env_source" "$env_target" && {
            COMPLETED_BINDS+=("$env_target")
            log_info "Bound persistent file: .env -> disk"
        } || {
            log_warn "Failed to bind .env, secrets may write to ramdisk"
        }
    fi

    return 0
}

# Main setup function
setup_ramdisk() {
    log_info "Storage: ${WPRINT3D_STORAGE_TYPE:-unknown}, RAM: ${WPRINT3D_AVAILABLE_MEMORY_MB:-0}MB, Role: ${ROLE:-unset}"

    # Run all gate checks
    if ! preflight_checks; then
        return 0
    fi

    local ramdisk_size_mb="${RAMDISK_SIZE_MB}"

    log_info "Creating ${ramdisk_size_mb}MB ramdisk at $RAMDISK_MOUNT"

    # Create and mount tmpfs
    mkdir -p "$RAMDISK_MOUNT" || {
        log_error "Failed to create mount point $RAMDISK_MOUNT"
        return 1
    }

    mount -t tmpfs -o "size=${ramdisk_size_mb}M,mode=0755,nr_inodes=0" tmpfs "$RAMDISK_MOUNT" || {
        log_error "Failed to mount tmpfs (${ramdisk_size_mb}MB)"
        rmdir "$RAMDISK_MOUNT" 2>/dev/null || true
        return 1
    }

    # Copy /var/www to ramdisk (excluding persistent dirs)
    copy_to_ramdisk || {
        log_error "Failed to copy $APP_ROOT to ramdisk"
        umount "$RAMDISK_MOUNT" 2>/dev/null || true
        rmdir "$RAMDISK_MOUNT" 2>/dev/null || true
        return 1
    }

    # Stash persistent directories before the overlay hides them
    mkdir -p "$STASH_DIR"
    for dir in "${PERSISTENT_DIRS[@]}"; do
        local source="$APP_ROOT/$dir"
        local stash_target="$STASH_DIR/$dir"

        if [[ ! -d "$source" ]]; then
            continue
        fi

        mkdir -p "$stash_target"
        mount --bind "$source" "$stash_target" || {
            log_warn "Failed to stash $source, persistent data may be lost for this dir"
        }
    done

    # Stash .env if it's a regular file
    if [[ -f "$APP_ROOT/.env" ]] && [[ ! -L "$APP_ROOT/.env" ]]; then
        touch "$STASH_DIR/.env"
        mount --bind "$APP_ROOT/.env" "$STASH_DIR/.env" || {
            log_warn "Failed to stash .env"
        }
    fi

    # Overlay /var/www with the ramdisk copy
    mount --bind "$RAMDISK_MOUNT" "$APP_ROOT" || {
        log_error "Failed to overlay $APP_ROOT with ramdisk"
        # Cleanup stash mounts
        for dir in "${PERSISTENT_DIRS[@]}"; do
            umount "$STASH_DIR/$dir" 2>/dev/null || true
        done
        umount "$STASH_DIR/.env" 2>/dev/null || true
        umount "$RAMDISK_MOUNT" 2>/dev/null || true
        rmdir "$RAMDISK_MOUNT" 2>/dev/null || true
        return 1
    }

    log_info "$APP_ROOT is now served from ramdisk"

    # Bind persistent directories back from stash onto the overlay
    if ! bind_persistent_dirs "$STASH_DIR"; then
        return 1
    fi

    # Verify critical mounts
    local failed=0
    for dir in "${PERSISTENT_DIRS[@]}"; do
        if [[ -d "$APP_ROOT/$dir" ]] && ! mountpoint -q "$APP_ROOT/$dir" 2>/dev/null; then
            if mountpoint -q "$STASH_DIR/$dir" 2>/dev/null; then
                log_warn "$APP_ROOT/$dir is NOT a mountpoint — writes go to ramdisk"
                failed=1
            fi
        fi
    done

    if [[ "$failed" -eq 1 ]]; then
        log_warn "Some persistent dirs may not be writing to disk"
    fi

    log_info "Ramdisk setup complete — ${ramdisk_size_mb}MB tmpfs, ${#COMPLETED_BINDS[@]} persistent bind mounts"
    return 0
}

# Entry point
setup_ramdisk
exit $?
