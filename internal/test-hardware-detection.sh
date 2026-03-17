#!/bin/bash
# Test script for hardware detection functionality

echo "=== Testing Hardware Detection Script ==="

# Source the detection script
source /home/facuarmo/wprint3d-core/internal/detect-hardware.sh

# Test environment variables are set
echo "Storage Type: ${WPRINT3D_STORAGE_TYPE:-NOT_SET}"
echo "Available Memory: ${WPRINT3D_AVAILABLE_MEMORY_MB:-NOT_SET}MB"

# Validate values
if [[ -z "$WPRINT3D_STORAGE_TYPE" ]]; then
    echo "FAIL: WPRINT3D_STORAGE_TYPE not set"
    exit 1
fi

if [[ -z "$WPRINT3D_AVAILABLE_MEMORY_MB" ]]; then
    echo "FAIL: WPRINT3D_AVAILABLE_MEMORY_MB not set"
    exit 1
fi

# Validate storage type is one of expected values
case "$WPRINT3D_STORAGE_TYPE" in
    hdd|ssd|sdcard|unknown)
        echo "PASS: Storage type is valid"
        ;;
    *)
        echo "FAIL: Unexpected storage type: $WPRINT3D_STORAGE_TYPE"
        exit 1
        ;;
esac

# Validate memory is a number
if ! [[ "$WPRINT3D_AVAILABLE_MEMORY_MB" =~ ^[0-9]+$ ]]; then
    echo "FAIL: Available memory is not a number"
    exit 1
fi

echo "PASS: All hardware detection tests passed"
exit 0
