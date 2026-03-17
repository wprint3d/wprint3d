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
- `.env` — application secrets (symlink to `.external-configs/.env` or generated at runtime)
- `.external-configs/` — external secret mount directory (volume-mounted by orchestrator)

## Design

### Self-Sizing Ramdisk

Rather than maintaining a hardcoded size table, the ramdisk sizes itself dynamically:

1. **Measure** the actual size of `/var/www` (excluding persistent directories) using `du -sb`
2. **Add 10% headroom** for runtime-generated files (compiled views, cached configs, temp files)
3. **Gate check**: only proceed if available RAM >= 2x the calculated ramdisk size. The 2x multiplier is conservative — it ensures the OS, PHP-FPM, MongoDB, Redis, and other services retain at least as much RAM as the ramdisk consumes. On a 1GB system with a 132MB ramdisk, 264MB is reserved for the gate check, leaving ~736MB for everything else
4. **Skip on SSD** unless explicitly forced via `WPRINT3D_FORCE_RAMDISK=1`. Storage types `hdd`, `sdcard`, and `unknown` all proceed with ramdisk creation (SD cards are slow like HDDs)
5. **Skip in developer mode** (`DEVELOPER_MODE=true`) since developers need file changes to reflect immediately (this is **new logic**, not present in the current script)

### Assumptions

- The ramdisk setup runs **early** in `run.sh`, before PHP-FPM, Nginx, or any application services start. No other process is reading from `/var/www` during the copy and bind-mount steps.
- `composer install` (line 266 in `run.sh`) runs **after** the ramdisk is in place. This is fine — composer writes to `/var/www/vendor` which is on the ramdisk. The installed dependencies persist until the container stops. On next container start, the image's original `vendor/` is re-copied to a fresh ramdisk. This is correct behavior: the container image contains the canonical `vendor/` state.
- The `.env` file is created/symlinked **after** the ramdisk setup by `generateSecrets()` / `waitForSecrets()`. Since `.env` is excluded from the ramdisk copy and the original disk path is bind-mounted back, `.env` writes go to disk and persist across container restarts.

### Mount Strategy

**Important:** `run.sh` must create all required directories **before** calling `ramdisk-setup.sh`. The ramdisk script copies `/var/www` as-is, so directories must exist on disk first. Specifically, `run.sh` must run:

```bash
mkdir -p /var/www/storage/{app/{gcode,recordings,public,plugins},framework/{cache,data,views},logs}
```

This replaces the existing `mkdir -p /var/www/storage/{app,framework/{cache,data,views},logs}` (currently line 46) and moves it to before the ramdisk call.

```
Step 1: (done by run.sh) All directories pre-created on disk
Step 2: Measure /var/www size (excluding persistent paths)
Step 3: Calculate ramdisk_size = measured_size * 1.10
Step 4: Check available_ram >= ramdisk_size * 2
Step 5: Create tmpfs at /tmp/wprint3d-ramdisk (size = ramdisk_size)
Step 6: Copy /var/www/* into tmpfs (excluding persistent dirs)
        - Persistent dirs get empty placeholder directories in tmpfs
          so they exist as bind-mount targets
Step 7: Bind-mount tmpfs over /var/www
Step 8: Re-mount original persistent directories on top of ramdisk
Step 9: Verify all mounts succeeded
```

### Persistent Path Handling

Before the ramdisk bind-mount, the script saves references to original disk paths for persistent directories. After the bind-mount replaces `/var/www` with the ramdisk copy, the script bind-mounts the original disk paths back on top:

```
Before ramdisk:
  /var/www/storage/app/gcode → disk (original)
  /var/www/.env              → disk (original, may not exist yet)

After ramdisk bind:
  /var/www/* → RAM (tmpfs copy)
  /var/www/storage/app/gcode → disk (re-mounted from original)
  /var/www/.env              → disk (re-mounted from original)
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
| `.external-configs/` | External secret volume mount, must pass through to disk |
| `.env` | Application secrets — only bind-mounted back when it is a **regular file**. When `.external-configs/` exists, `.env` is a symlink to `.external-configs/.env`, so the `.external-configs/` bind-mount already covers it. Binding `.env` separately in the symlink case would mask the symlink and break resolution. |

### Non-Persistent Directories (On Ramdisk)

| Path | Reason |
|------|--------|
| `vendor/` | PHP dependencies, read-only at runtime, canonical copy in image |
| `app/` | Application code, read-only at runtime |
| `config/`, `routes/`, `lang/` | Configuration/routing, read-only at runtime |
| `bootstrap/cache/` | Generated caches, ephemeral |
| `storage/framework/cache/` | Framework cache, ephemeral |
| `storage/framework/views/` | Compiled Blade templates, ephemeral |
| `storage/framework/data/` | Framework data directory (created by `run.sh` mkdir), ephemeral — no code writes persistent data here |
| `public/` | Static web assets, read-only at runtime |
| `internal/` | Startup scripts, read-only after boot |

### Environment Variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `WPRINT3D_FORCE_RAMDISK` | `0` = disable, `1` = force (even on SSD), `auto` = auto-detect | `auto` |
| `WPRINT3D_STORAGE_TYPE` | Set by `detect-hardware.sh`: `hdd`, `ssd`, `sdcard`, `unknown` | `unknown` |
| `WPRINT3D_AVAILABLE_MEMORY_MB` | Set by `detect-hardware.sh`: available RAM in MB | `0` |
| `DEVELOPER_MODE` | When `true`, ramdisk is skipped entirely | unset |

### Developer Mode Behavior

When `DEVELOPER_MODE=true`, the ramdisk is skipped entirely. This is **new logic** not present in the current `ramdisk-setup.sh`. Developers mount code via volume binds and need file changes to reflect immediately without container restarts. OPcache with `validate_timestamps=1` still provides some performance benefit.

### Graceful Fallback

If any condition prevents ramdisk creation (insufficient RAM, mount failure, developer mode), the script logs a warning and exits cleanly. The application runs normally from disk — OPcache still handles bytecode caching.

### Error Recovery

If the ramdisk mounts successfully but a persistent path re-mount fails:

1. Log the error
2. Unmount any bind mounts that succeeded (in **reverse order** to avoid "device busy" errors)
3. Unmount the ramdisk tmpfs to restore original `/var/www`
4. If full rollback fails, log a critical warning — the container should still function but persistent dirs may not write to disk until restart

### tmpfs Mount Options

The tmpfs mount uses `nr_inodes=0` (unlimited) since `vendor/` alone can contain 15,000+ files. The current script's `nr_inodes=100k` is insufficient for the full `/var/www` tree.

## File Changes

### Modified Files

1. **`internal/ramdisk-setup.sh`** — Full rewrite with new self-sizing logic
2. **`internal/run.sh`** — Replace the existing mkdir on line 46:

   ```bash
   # OLD (line 46):
   mkdir -p /var/www/storage/{app,framework/{cache,data,views},logs};

   # NEW (moved to before ramdisk call, ~line 28):
   mkdir -p /var/www/storage/{app/{gcode,recordings,public,plugins},framework/{cache,data,views},logs};
   ```

   This creates all directories (including persistent subdirectories) before the ramdisk script copies `/var/www` to tmpfs.

### No New Files

The rewrite replaces the existing script; no new files are needed.

## Testing Strategy

- Verify ramdisk is created with correct size (measured + 10%)
- Verify persistent directories still write to disk after ramdisk mount
- Verify `.env` file creation/modification persists after ramdisk is active
- Verify developer mode skips ramdisk entirely
- Verify insufficient RAM skips ramdisk with warning
- Verify SSD detection skips ramdisk unless forced
- Verify `composer install` works correctly on ramdisk
- Verify application starts and functions correctly with ramdisk active
- Verify logs persist after container restart
- Verify error rollback correctly unmounts bind mounts in reverse order

## Sizing Examples

| `/var/www` size | Ramdisk size (1.10x) | Min RAM required (2x) |
|-----------------|---------------------|----------------------|
| 120MB | 132MB | 264MB |
| 150MB | 165MB | 330MB |
| 200MB | 220MB | 440MB |
| 300MB | 330MB | 660MB |
