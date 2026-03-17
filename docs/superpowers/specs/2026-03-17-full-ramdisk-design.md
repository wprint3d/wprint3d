# Full /var/www Ramdisk with Persistent Exclusions

**Date:** 2026-03-17
**Status:** Draft
**Scope:** Rewrite `internal/ramdisk-setup.sh` and adjust `internal/run.sh` ordering

## Problem

The current ramdisk implementation only mirrors three small cache directories to tmpfs (`bootstrap/cache`, `storage/framework/cache`, `storage/framework/views`). On HDD-based systems, the real I/O bottleneck is the hundreds of `require`/`include` calls loading files from `vendor/` (~116MB). OPcache mitigates this after warmup, but cold-start reads and `stat()` calls (in dev mode) still hit disk.

## Solution

Mirror the **entire `/var/www`** directory to a tmpfs ramdisk, excluding directories that need disk persistence:

- `storage/app/gcode/` — user-uploaded G-code files
- `storage/app/recordings/` — camera recordings
- `storage/app/public/` — user-uploaded media
- `storage/app/plugins/` — plugin state and data
- `storage/logs/` — application logs (needed for crash debugging)

## Design

### Self-Sizing Ramdisk

Rather than maintaining a hardcoded size table, the ramdisk sizes itself dynamically:

1. **Measure** the actual size of `/var/www` (excluding persistent directories) using `du -sb`
2. **Add 10% headroom** for runtime-generated files (compiled views, cached configs, temp files)
3. **Gate check**: only proceed if available RAM >= 2x the calculated ramdisk size
4. **Skip on SSD** unless explicitly forced via `WPRINT3D_FORCE_RAMDISK=1`
5. **Skip in developer mode** (`DEVELOPER_MODE=true`) since developers need file changes to reflect immediately

### Mount Strategy

```
Step 1: Measure /var/www size (excluding persistent paths)
Step 2: Calculate ramdisk_size = measured_size * 1.10
Step 3: Check available_ram >= ramdisk_size * 2
Step 4: Create tmpfs at /tmp/wprint3d-ramdisk (size = ramdisk_size)
Step 5: Save original persistent dir paths (before bind mount)
Step 6: Copy /var/www/* into tmpfs (excluding persistent dirs)
Step 7: Bind-mount tmpfs over /var/www
Step 8: Re-mount original persistent directories on top of ramdisk
Step 9: Verify all mounts succeeded
```

### Persistent Path Handling

Before the ramdisk bind-mount, the script saves references to original disk paths for persistent directories. After the bind-mount replaces `/var/www` with the ramdisk copy, the script bind-mounts the original disk paths back on top:

```
Before ramdisk:
  /var/www/storage/app/gcode → disk (original)

After ramdisk bind:
  /var/www/* → RAM (tmpfs copy)
  /var/www/storage/app/gcode → disk (re-mounted from original)
```

This ensures writes to persistent directories always go to disk, while everything else is served from RAM.

### Persistent Directories (Excluded from Ramdisk)

| Path | Reason |
|------|--------|
| `storage/app/gcode/` | User-uploaded G-code files, must survive restarts |
| `storage/app/recordings/` | Camera recordings, must survive restarts |
| `storage/app/public/` | User-uploaded media, must survive restarts |
| `storage/app/plugins/` | Plugin state and registry data |
| `storage/logs/` | Application logs needed for crash/issue debugging |

### Environment Variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `WPRINT3D_FORCE_RAMDISK` | `0` = disable, `1` = force (even on SSD), `auto` = auto-detect | `auto` |
| `WPRINT3D_STORAGE_TYPE` | Set by `detect-hardware.sh`: `hdd`, `ssd`, `sdcard`, `unknown` | `unknown` |
| `WPRINT3D_AVAILABLE_MEMORY_MB` | Set by `detect-hardware.sh`: available RAM in MB | `0` |
| `DEVELOPER_MODE` | When `true`, ramdisk is skipped entirely | unset |

### Developer Mode Behavior

When `DEVELOPER_MODE=true`, the ramdisk is skipped entirely. Developers mount code via volume binds and need changes to reflect immediately without container restarts. OPcache with `validate_timestamps=1` still provides some performance benefit.

### Graceful Fallback

If any condition prevents ramdisk creation (insufficient RAM, mount failure, developer mode), the script logs a warning and exits cleanly. The application runs normally from disk — OPcache still handles bytecode caching.

### Error Recovery

If the ramdisk mounts successfully but a persistent path re-mount fails:
- Log the error
- Attempt to unmount the ramdisk to restore original `/var/www`
- If unmount fails, the container should still function (persistent dirs just won't write to disk until restart)

## File Changes

### Modified Files

1. **`internal/ramdisk-setup.sh`** — Full rewrite with new self-sizing logic
2. **`internal/run.sh`** — Move `mkdir -p storage/...` (line 46) to before the ramdisk call (line 33), so directories exist before copying

### No New Files

The rewrite replaces the existing script; no new files are needed.

## Testing Strategy

- Verify ramdisk is created with correct size (measured + 10%)
- Verify persistent directories still write to disk after ramdisk mount
- Verify developer mode skips ramdisk entirely
- Verify insufficient RAM skips ramdisk with warning
- Verify SSD detection skips ramdisk unless forced
- Verify application starts and functions correctly with ramdisk active
- Verify logs persist after container restart

## Sizing Examples

| `/var/www` size | Ramdisk size (1.10x) | Min RAM required (2x) |
|-----------------|---------------------|----------------------|
| 120MB | 132MB | 264MB |
| 150MB | 165MB | 330MB |
| 200MB | 220MB | 440MB |
| 300MB | 330MB | 660MB |
