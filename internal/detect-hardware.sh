#!/bin/bash
# Hardware detection for PHP performance optimization
# Detects storage type and available memory to optimize caching strategy

detect_storage_type() {
    local root_mount="/"
    local device=""

    # Get the root filesystem device
    device=$(df "$root_mount" 2>/dev/null | tail -1 | awk '{print $1}')

    # If df didn't give us a device, try from /proc/mounts
    if [[ -z "$device" ]] || [[ "$device" == "tmpfs" ]] || [[ "$device" == "overlay" ]]; then
        device=$(grep " / " /proc/mounts 2>/dev/null | awk '{print $1}')
    fi

    # Extract the disk name (remove partition number, /dev/ prefix, etc.)
    local disk_name=""
    if [[ -n "$device" ]]; then
        disk_name=$(basename "$device" 2>/dev/null | sed 's/[0-9]*$//g' | sed 's/p[0-9]*$//g')
    fi

    # Check rotational flag to distinguish HDD from SSD
    if [[ -n "$disk_name" ]] && [[ -f "/sys/block/$disk_name/queue/rotational" ]]; then
        local rotational=$(cat "/sys/block/$disk_name/queue/rotational" 2>/dev/null)
        if [[ "$rotational" == "0" ]]; then
            echo "ssd"
            return 0
        elif [[ "$rotational" == "1" ]]; then
            echo "hdd"
            return 0
        fi
    fi

    # Fallback: check for SD card indicators in /proc/mounts
    if grep -q -E "mmcblk|sdcard" /proc/mounts 2>/dev/null; then
        echo "sdcard"
        return 0
    fi

    echo "unknown"
    return 0
}

detect_available_memory() {
    local mem_avail_mb=""

    # Try MemAvailable first (kernel 3.14+)
    if [[ -f /proc/meminfo ]]; then
        mem_avail_kb=$(grep "^MemAvailable:" /proc/meminfo 2>/dev/null | awk '{print $2}')
        if [[ -n "$mem_avail_kb" ]] && [[ "$mem_avail_kb" != "0" ]]; then
            echo $(( mem_avail_kb / 1024 ))
            return 0
        fi

        # Fallback: calculate from MemFree + Cached + Buffers
        local mem_free=$(grep "^MemFree:" /proc/meminfo 2>/dev/null | awk '{print $2}')
        local mem_cached=$(grep "^Cached:" /proc/meminfo 2>/dev/null | awk '{print $2}' | head -1)
        local mem_buffers=$(grep "^Buffers:" /proc/meminfo 2>/dev/null | awk '{print $2}')

        if [[ -n "$mem_free" ]]; then
            local total_kb=$((mem_free + ${mem_cached:-0} + ${mem_buffers:-0}))
            echo $(( total_kb / 1024 ))
            return 0
        fi
    fi

    echo "0"
    return 1
}

# Main execution - run detection and export variables
STORAGE_TYPE=$(detect_storage_type)
AVAILABLE_MEMORY_MB=$(detect_available_memory)

# Export for use by other scripts
export WPRINT3D_STORAGE_TYPE="${STORAGE_TYPE:-unknown}"
export WPRINT3D_AVAILABLE_MEMORY_MB="${AVAILABLE_MEMORY_MB:-0}"

# Log results for debugging
echo "Hardware Detection: Storage=${WPRINT3D_STORAGE_TYPE}, Available RAM=${WPRINT3D_AVAILABLE_MEMORY_MB}MB" >&2

# Exit successfully (use return 0 since this may be sourced)
return 0
