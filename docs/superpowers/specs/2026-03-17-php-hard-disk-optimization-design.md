# PHP Hard Disk Performance Optimization Design

**Date**: 2026-03-17
**Status**: Design
**Author**: Claude (wprint3d-core optimization)

## Problem Statement

Users running wprint3d on hard-disk or SD-card storage experience significant performance degradation because PHP scripts are read from disk and recompiled on every execution. This affects:

1. **Cold-start latency** - First request to a service is slow (2-5 seconds)
2. **Consistent slowness** - Every request suffers from disk I/O overhead
3. **Scheduler performance** - The scheduled task runner executes PHP scripts repeatedly without caching

## Solution Overview

A hybrid optimization approach that combines three complementary strategies:

1. **PHP OPcache** - Cache compiled bytecode in shared memory
2. **Selective Ramdisk** - Store hot-path files in a tmpfs mount
3. **Hardware Auto-Detection** - Adapt strategy based on storage type and available RAM

## Architecture

### Components

```
┌─────────────────────────────────────────────────────────────────┐
│                        Container Startup                        │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Hardware Detection Module                    │
│  detect-hardware.sh                                            │
│  - Detects storage type (HDD/SSD/SD card)                      │
│  - Measures available RAM                                      │
│  - Sets environment variables                                  │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Selective Ramdisk Manager                     │
│  ramdisk-setup.sh                                              │
│  - Creates tmpfs mount if sufficient RAM                       │
│  - Copies hot-path directories                                 │
│  - Falls back gracefully if low memory                         │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                    PHP OPcache Configuration                    │
│  php-opcache.ini + php-opcache-preload.php                     │
│  - Aggressive bytecode caching                                  │
│  - JIT compilation for hot functions                           │
│  - Laravel core preloading                                     │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                  Optional Application Caching                   │
│  app-cache-setup.sh (toggleable via env var)                   │
│  - Primes common artisan commands                              │
│  - Config/route caching                                        │
│  - Redis-backed fast cache store                               │
└─────────────────────────────────────────────────────────────────┘
```

## File Changes

### New Files

| File | Purpose |
|------|---------|
| `internal/detect-hardware.sh` | Hardware detection (storage type, RAM) |
| `internal/php-opcache.ini` | OPcache configuration |
| `internal/php-opcache-preload.php` | Laravel core preloading script |
| `internal/ramdisk-setup.sh` | Selective ramdisk setup |
| `internal/app-cache-setup.sh` | Optional app-level caching |

### Modified Files

| File | Changes |
|------|---------|
| `internal/run.sh` | Call detection and setup scripts early |
| `Dockerfile.dev` | Copy new files into image |

## Detailed Specifications

### 1. Hardware Detection Module (`detect-hardware.sh`)

**Detection Methods**:
- **Storage Type**: Reads `/sys/block/*/queue/rotational` to distinguish HDD from SSD
- **SD Card Detection**: Checks `/proc/mounts` for MMC devices
- **Available Memory**: Calculates from `/proc/meminfo` (MemAvailable or MemFree + Cached)

**Output Variables**:
```bash
WPRINT3D_STORAGE_TYPE=hdd|ssd|sdcard|unknown
WPRINT3D_AVAILABLE_MEMORY_MB=<integer>
```

### 2. PHP OPcache Configuration (`php-opcache.ini`)

**Key Settings**:

| Setting | Production | Development | Rationale |
|---------|------------|-------------|-----------|
| `opcache.enable` | 1 | 1 | Enable OPcache |
| `opcache.enable_cli` | 1 | 1 | Cache Artisan commands |
| `opcache.memory_consumption` | 128M | 128M | Sufficient for Laravel |
| `opcache.max_accelerated_files` | 20000 | 20000 | Large project |
| `opcache.revalidate_freq` | 0 | 2 | Prod: never revalidate; Dev: quick iteration |
| `opcache.validate_timestamps` | 0 | 1 | Prod: disable; Dev: enable |
| `opcache.jit_buffer_size` | 64M | 64M | JIT compilation |
| `opcache.jit` | tracing | tracing | Best for long-running processes |
| `opcache.preload_user` | www-data | www-data | Security: run as non-root |
| `opcache.interned_strings_buffer` | 16M | 16M | Cache common strings |

**Production vs Development Behavior**:

In production, OPcache never revalidates file timestamps. Code changes require a container restart, which is the correct deployment pattern for containerized applications.

**Important Notes**:
- **PHP 8.4 JIT Behavior**: If JIT compilation fails to initialize, PHP will exit with a fatal error on startup. The setup script should catch this and fall back to JIT disabled mode.
- **Development Workflow**: In development mode with `enable_cli=1`, code changes to core Laravel files may not take effect immediately. Developers should run `php artisan clear-compiled` or restart the container when making significant changes.

### 3. Laravel Core Preloading (`php-opcache-preload.php`)

**Configuration in `php-opcache.ini`**:

```ini
opcache.preload_user=www-data
opcache.preload=/var/www/internal/php-opcache-preload.php
```

**Preloaded Components**:

Preloads the following Laravel framework components:
- Illuminate\Foundation
- Illuminate\Support
- Illuminate\Database
- Illuminate\Cache
- Illuminate\Queue
- Illuminate\Routing
- Illuminate\Http
- Composer autoload files

### 4. Selective Ramdisk Manager (`ramdisk-setup.sh`)

**Important**: The ramdisk is **ephemeral** - all data stored on it is lost when the container restarts. This is acceptable for cache and compiled views as they can be regenerated, but no critical application state should be stored in these directories.

**Ramdisk Contents**:

| Directory | Size | Reason | Persistence |
|-----------|------|--------|-------------|
| `bootstrap/cache/` | 1-5MB | Compiled services.php, routes.php | Regenerated on startup |
| `storage/framework/cache/` | 5-20MB | Application cache data | Ephemeral by design |
| `storage/framework/views/` | 5-50MB | Compiled Blade templates | Regenerated on first use |

**Memory Sizing**:

| Available RAM | Ramdisk Size | Action |
|---------------|--------------|--------|
| < 512MB | Disabled | Fallback to OPcache only |
| 512MB - 1GB | 100MB | Selective ramdisk |
| 1GB - 2GB | 150MB | Selective ramdisk |
| > 2GB | 200MB | Selective ramdisk |

**Storage Type Behavior**:

| Storage Type | Ramdisk | Rationale |
|--------------|---------|-----------|
| SSD/NVMe | Disabled | Fast enough without |
| HDD | Enabled | Significant benefit |
| SD card | Enabled | Critical benefit |

### 5. Optional Application Caching (`app-cache-setup.sh`)

**Toggle**: `WPRINT3D_APP_CACHE_ENABLED=true`

**Actions**:
1. Pre-warm common artisan commands in OPcache
2. Prime config cache (`php artisan config:cache`)
3. Prime route cache (`php artisan route:cache`)

**Redis Fast Store**:
Adds a 'fast' cache store in `config/cache.php` that uses Redis for frequently-accessed data.

## Integration Points

### `internal/run.sh` Changes

Add after line 7:
```bash
# ============================================================================
# PHP Performance Optimization Setup
# ============================================================================

# Set OPcache revalidation based on environment
if [[ "${DEVELOPER_MODE}" == 'true' ]]; then
    export WPRINT3D_OPCACHE_REVALIDATE_FREQ=2
    export WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS=1
else
    export WPRINT3D_OPCACHE_REVALIDATE_FREQ=0
    export WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS=0
fi

# Source hardware detection and optimization scripts
if [[ -f '/var/www/internal/detect-hardware.sh' ]]; then
    source /var/www/internal/detect-hardware.sh
fi

# Setup selective ramdisk if available
if [[ -f '/var/www/internal/ramdisk-setup.sh' ]]; then
    bash /var/www/internal/ramdisk-setup.sh
fi

# Setup optional application caching
if [[ -f '/var/www/internal/app-cache-setup.sh' ]]; then
    bash /var/www/internal/app-cache-setup.sh
fi
```

### `Dockerfile.dev` Changes

Add after line 196 (after copying limits.ini):
```dockerfile
# Copy PHP OPcache configuration
COPY ./internal/php-opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY ./internal/php-opcache-preload.php /var/www/internal/php-opcache-preload.php

# Copy hardware detection and ramdisk setup scripts
COPY ./internal/detect-hardware.sh /var/www/internal/detect-hardware.sh
COPY ./internal/ramdisk-setup.sh /var/www/internal/ramdisk-setup.sh
COPY ./internal/app-cache-setup.sh /var/www/internal/app-cache-setup.sh

# Make scripts executable
RUN chmod +x /var/www/internal/detect-hardware.sh \
              /var/www/internal/ramdisk-setup.sh \
              /var/www/internal/app-cache-setup.sh
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `WPRINT3D_FORCE_RAMDISK` | `auto` | `1` = force on, `0` = force off |
| `WPRINT3D_APP_CACHE_ENABLED` | `false` | Enable application-level caching |
| `WPRINT3D_OPCACHE_REVALIDATE_FREQ` | `0` (prod) / `2` (dev) | OPcache revalidation frequency |
| `WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS` | `0` (prod) / `1` (dev) | Check file timestamps |

## Performance Expectations

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Cold-start latency | 2-5s | 0.5-1s | 70-80% faster |
| Subsequent requests | 200-500ms | 50-100ms | 70-80% faster |
| Scheduler execution | 500ms-1s | 100-200ms | 70-80% faster |
| Memory overhead | ~50MB | 150-300MB | +100-250MB |

## Decision Flow

```
Container Start
     │
     ▼
Hardware Detection
     │
     ├─→ SSD detected → OPcache only (no ramdisk needed)
     │
     ├─→ HDD/SD-card + RAM ≥ 512MB → OPcache + Selective Ramdisk
     │
     └─→ HDD/SD-card + RAM < 512MB → OPcache only (fallback)
```

## Graceful Degradation

The layered approach ensures the system works even if some components fail:

1. **Ramdisk setup fails** → OPcache still provides benefits
2. **OPcache preload fails** → Regular OPcache still works
3. **Insufficient RAM** → Falls back to OPcache-only mode
4. **Detection fails** → Defaults to safe OPcache-only configuration

## Security Considerations

1. **OPcache preload user**: Set to `www-data` (non-root)
2. **Ramdisk permissions**: Mode 0755, owned by root
3. **File validation**: Disabled in production (code changes require restart)

## Testing Strategy

1. **Unit tests**: Test hardware detection logic with mocked `/sys` files
2. **Integration tests**: Test OPcache configuration loading
3. **Performance tests**: Benchmark cold-start and steady-state performance
4. **Fallback tests**: Test behavior with low memory conditions

## Future Enhancements

1. **Dynamic ramdisk sizing**: Adjust based on actual usage patterns
2. **Hot file detection**: Profile and auto-identify frequently-accessed files
3. **Multi-level caching**: Add LRU cache for database queries
