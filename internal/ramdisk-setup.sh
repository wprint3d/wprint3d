#!/bin/bash
# Selective ramdisk setup for hot-path PHP files
# Creates tmpfs mount for frequently-accessed directories
# Falls back gracefully if insufficient memory
# NOTE: In container environment, ramdisk persists until container stops

# Configuration
RAMDISK_MOUNT="/tmp/wprint3d-ramdisk"
RAMDISK_SIZE_MB=100

# Directories to mirror on ramdisk (hot paths)
# Format: "source_directory:mount_mode"
declare -a RAMDISK_DIRS=(
    "/var/www/bootstrap/cache:0755"
    "/var/www/storage/framework/cache:0755"
    "/var/www/storage/framework/views:0755"
)

# Logging function
log_info() {
    echo "[ramdisk-setup] $*" >&2
}

log_error() {
    echo "[ramdisk-setup ERROR] $*" >&2
}

# Cleanup function - only for error scenarios
# In normal container operation, ramdisk persists until container stops
cleanup_on_error() {
    # Only cleanup if setup failed (ramdisk exists but mounts failed)
    if mountpoint -q "$RAMDISK_MOUNT" 2>/dev/null; then
        local has_bind_mounts=0

        # Check if any bind mounts succeeded
        for entry in "${RAMDISK_DIRS[@]}"; do
            local dir="${entry%%:*}"
            if mountpoint -q "$dir" 2>/dev/null; then
                has_bind_mounts=1
                break
            fi
        done

        # Only cleanup if no bind mounts succeeded (setup failed)
        if [[ "$has_bind_mounts" -eq 0 ]]; then
            log_info "Setup failed, cleaning up partial ramdisk..."
            umount "$RAMDISK_MOUNT" 2>/dev/null || true
            rmdir "$RAMDISK_MOUNT" 2>/dev/null || true
        fi
    fi
}

# Register cleanup only on interrupt/error (not normal exit)
trap cleanup_on_error INT TERM

# Main setup function
setup_ramdisk() {
    local storage_type="${WPRINT3D_STORAGE_TYPE:-unknown}"
    local available_mem="${WPRINT3D_AVAILABLE_MEMORY_MB:-0}"
    local force_ramdisk="${WPRINT3D_FORCE_RAMDISK:-auto}"

    log_info "Storage type: $storage_type, Available RAM: ${available_mem}MB"

    # Check if ramdisk is explicitly disabled
    if [[ "$force_ramdisk" == "0" ]]; then
        log_info "Ramdisk explicitly disabled via WPRINT3D_FORCE_RAMDISK=0"
        return 0
    fi

    # Skip ramdisk on SSD unless explicitly forced
    if [[ "$storage_type" == "ssd" ]] && [[ "$force_ramdisk" != "1" ]]; then
        log_info "SSD detected, skipping ramdisk (set WPRINT3D_FORCE_RAMDISK=1 to enable)"
        return 0
    fi

    # Require at least 512MB available RAM
    if [[ "$available_mem" -lt 512 ]]; then
        log_info "Insufficient RAM (${available_mem}MB < 512MB), skipping ramdisk"
        return 0
    fi

    # Adjust size based on available memory
    if [[ "$available_mem" -gt 2048 ]]; then
        RAMDISK_SIZE_MB=200
    elif [[ "$available_mem" -gt 1024 ]]; then
        RAMDISK_SIZE_MB=150
    fi

    log_info "Setting up ${RAMDISK_SIZE_MB}MB ramdisk at ${RAMDISK_MOUNT}"

    # Create mount point
    mkdir -p "$RAMDISK_MOUNT" || {
        log_error "Failed to create mount point"
        return 1
    }

    # Mount tmpfs
    mount -t tmpfs -o "size=${RAMDISK_SIZE_MB}M,mode=0755,nr_inodes=100k" tmpfs "$RAMDISK_MOUNT" || {
        log_error "Failed to mount ramdisk"
        return 1
    }

    log_info "Ramdisk mounted successfully"

    # Create directory structure and populate
    for entry in "${RAMDISK_DIRS[@]}"; do
        local dir="${entry%%:*}"
        local mode="${entry##*:}"
        local target_dir="${RAMDISK_MOUNT}/${dir#/var/www/}"

        # Create target directory
        mkdir -p "$target_dir" || {
            log_error "Failed to create $target_dir"
            continue
        }
        chmod "$mode" "$target_dir"

        # Copy existing contents if any
        if [[ -d "$dir" ]] && [[ -n "$(ls -A $dir 2>/dev/null)" ]]; then
            log_info "Copying contents of $dir to ramdisk"
            cp -r "$dir"/* "$target_dir/" 2>/dev/null || true
        fi

        # Create bind mount
        if ! mountpoint -q "$dir" 2>/dev/null; then
            mount --bind "$target_dir" "$dir" && log_info "Bind mounted $dir" || {
                log_error "Failed to bind mount $dir"
            }
        fi
    done

    log_info "Ramdisk setup complete - persisting until container stops"
    return 0
}

# Run setup
setup_ramdisk
exit $?
