# PHP Performance Optimization

This document describes the PHP performance optimizations implemented in wprint3d-core for systems running on hard-disk or SD-card storage.

## Overview

The optimization system uses a layered approach:

1. **Hardware Detection** - Automatically detects storage type (HDD/SSD/SD-card/microSD) and available RAM
2. **PHP OPcache** - Caches compiled bytecode in shared memory with JIT compilation
3. **Full Ramdisk** - Mirrors entire `/var/www` to tmpfs for eligible roles on slow storage
4. **Optional Application Caching** - Primes config and route caches

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `WPRINT3D_FORCE_RAMDISK` | `auto` | Set to `1` to force ramdisk (even on SSD or in dev mode), `0` to disable |
| `WPRINT3D_APP_CACHE_ENABLED` | `false` | Enable application-level caching |
| `DEVELOPER_MODE` | `false` | When `true`, skips ramdisk (unless forced) and enables faster OPcache revalidation |

## Performance Improvements

On hard-disk systems, expect:
- **70-80% faster cold starts** (2-5s → 0.5-1s)
- **70-80% faster subsequent requests** (200-500ms → 50-100ms)
- **70-80% faster scheduler execution** (500ms-1s → 100-200ms)

## Memory Requirements

The ramdisk self-sizes based on the actual size of `/var/www` (excluding persistent data). It adds 10% headroom and requires at least 2x the ramdisk size in available RAM.

| `/var/www` size | Ramdisk size (1.10x) | Min RAM required (2x) |
| --------------- | ------------------- | -------------------- |
| 120MB | 132MB | 264MB |
| 150MB | 165MB | 330MB |
| 200MB | 220MB | 440MB |
| 300MB | 330MB | 660MB |

If available RAM is below the 2x threshold, the ramdisk is skipped and the app runs from disk with OPcache only.

## Development Notes

In development mode (`DEVELOPER_MODE=true`):
- OPcache revalidates files every 2 seconds
- Code changes take effect quickly
- Run `php artisan clear-compiled` if changes don't appear

In production mode:
- OPcache never revalidates (maximum performance)
- Code changes require container restart
- This is the correct pattern for containerized applications

## Ramdisk Behavior

The ramdisk mirrors the **entire `/var/www`** directory to `tmpfs` at container startup. This eliminates all disk I/O for PHP file loading on slow storage (HDD, SD card, microSD). The ramdisk persists for the container's lifetime and is automatically cleaned up by the kernel when the container stops.

### Role-Based Eligibility

Not all container roles benefit from a ramdisk. Roles using long-running resident processes (where classes are loaded once and stay in memory) skip ramdisk setup:

| Role | Ramdisk | Reason |
| ---- | ------- | ------ |
| **server** (Octane) | Skip | Swoole/RoadRunner keeps classes resident |
| **concurrency-scheduler** | Enable | Supervisor respawns `queue:work` workers |
| **ws-server** | Enable | Crash-restart loop spawns fresh PHP |
| **scheduler** | Enable | Each cron job is a fresh PHP process |
| **mapper** | Skip | Long-running udev monitor |
| **streamer** | Skip | Native binaries, not PHP |

### What's on Ramdisk vs. Disk

**On ramdisk (entire `/var/www` minus exclusions):**

- `vendor/` — PHP dependencies (~116MB, the main I/O bottleneck)
- `app/`, `config/`, `routes/`, `lang/` — application code
- `bootstrap/cache/`, `storage/framework/` — ephemeral caches
- `public/`, `internal/` — static assets and scripts

**Excluded (kept on disk for persistence/debugging):**

- `storage/app/gcode/` — user-uploaded G-code files
- `storage/app/recordings/` — camera recordings
- `storage/app/public/` — user-uploaded media
- `storage/app/plugins/` — plugin state
- `storage/logs/` — application logs
- `.env` — application secrets (only when it's a regular file, not a symlink)
- `.external-configs/` — external secret volume mount

### How It Works

1. `run.sh` creates all required directories (including persistent subdirectories)
2. `detect-hardware.sh` identifies storage type and available RAM
3. `ramdisk-setup.sh` measures `/var/www` size, adds 10% headroom
4. If available RAM >= 2x ramdisk size, a `tmpfs` is mounted
5. `/var/www` is copied to the ramdisk (excluding persistent dirs)
6. Persistent directories are stashed via bind mounts before the overlay
7. The ramdisk is bind-mounted over `/var/www`
8. Persistent directories are bound back from the stash onto the overlay

If any step fails, a full rollback restores the original `/var/www`.

### Storage Type Detection

| Storage | Detection | Ramdisk |
| ------- | --------- | ------- |
| HDD | `/sys/block/*/queue/rotational=1` | Enabled |
| SSD | `/sys/block/*/queue/rotational=0` (non-mmcblk) | Skipped (unless forced) |
| microSD/eMMC | Device name starts with `mmcblk` | Enabled |
| SD card | `/proc/mounts` contains `mmcblk` or `sdcard` | Enabled |
| Unknown | Fallback | Enabled |

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
