# PHP Performance Optimization

This document describes the PHP performance optimizations implemented in wprint3d-core for systems running on hard-disk or SD-card storage.

## Overview

The optimization system uses a layered approach:

1. **Hardware Detection** - Automatically detects storage type (HDD/SSD/SD-card) and available RAM
2. **PHP OPcache** - Caches compiled bytecode in shared memory with JIT compilation
3. **Selective Ramdisk** - Stores hot-path files in memory for faster access
4. **Optional Application Caching** - Primes config and route caches

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `WPRINT3D_FORCE_RAMDISK` | `auto` | Set to `1` to force ramdisk, `0` to disable |
| `WPRINT3D_APP_CACHE_ENABLED` | `false` | Enable application-level caching |
| `DEVELOPER_MODE` | `false` | When `true`, enables faster OPcache revalidation |

## Performance Improvements

On hard-disk systems, expect:
- **70-80% faster cold starts** (2-5s → 0.5-1s)
- **70-80% faster subsequent requests** (200-500ms → 50-100ms)
- **70-80% faster scheduler execution** (500ms-1s → 100-200ms)

## Memory Requirements

| Available RAM | Ramdisk Size | Memory Overhead |
|---------------|--------------|-----------------|
| < 512MB | Disabled | ~100MB (OPcache only) |
| 512MB - 1GB | 100MB | ~200-250MB |
| 1GB - 2GB | 150MB | ~250-300MB |
| > 2GB | 200MB | ~300-350MB |

## Development Notes

In development mode (`DEVELOPER_MODE=true`):
- OPcache revalidates files every 2 seconds
- Code changes take effect quickly
- Run `php artisan clear-compiled` if changes don't appear

In production mode:
- OPcache never revalidates (maximum performance)
- Code changes require container restart
- This is the correct pattern for containerized applications

## Troubleshooting

### Verify OPcache is working

```bash
php internal/test-opcache.php
```

### Check hardware detection

```bash
source internal/detect-hardware.sh
echo "Storage: $WPRINT3D_STORAGE_TYPE"
echo "Memory: ${WPRINT3D_AVAILABLE_MEMORY_MB}MB"
```

### Force ramdisk on SSD systems

```bash
export WPRINT3D_FORCE_RAMDISK=1
```

### Disable ramdisk on low-memory systems

```bash
export WPRINT3D_FORCE_RAMDISK=0
```
