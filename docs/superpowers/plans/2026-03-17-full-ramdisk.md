# Full /var/www Ramdisk Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite the ramdisk setup to mirror all of `/var/www` to tmpfs (excluding persistent user data and logs), with self-sizing, role-based eligibility, and mmcblk detection fix.

**Architecture:** Three files modified: `detect-hardware.sh` gets mmcblk detection before the rotational flag check; `run.sh` gets mkdir reordering; `ramdisk-setup.sh` gets a full rewrite with self-sizing logic, role gating, and persistent path bind-back. No new files.

**Tech Stack:** Bash, tmpfs, bind mounts, Linux `/sys/block` and `/proc/meminfo`

**Spec:** `docs/superpowers/specs/2026-03-17-full-ramdisk-design.md`

---

### Task 1: Fix mmcblk detection in detect-hardware.sh

**Files:**
- Modify: `internal/detect-hardware.sh:17-33`
- Modify: `internal/test-hardware-detection.sh:26`

- [ ] **Step 1: Update the test to accept `mmcblk` as a valid storage type**

In `internal/test-hardware-detection.sh`, line 26, add `mmcblk` to the valid cases:

```bash
    hdd|ssd|sdcard|mmcblk|unknown)
```

- [ ] **Step 2: Run the test to verify it still passes with current detection**

Run: `bash internal/test-hardware-detection.sh`
Expected: PASS (current system is not mmcblk, so existing detection still works)

- [ ] **Step 3: Add mmcblk check before the rotational flag in detect-hardware.sh**

In `internal/detect-hardware.sh`, insert after line 21 (after `disk_name` is extracted), before the rotational check on line 24:

```bash
    # Check for mmcblk (microSD/eMMC) BEFORE rotational flag
    # These devices report rotational=0 (flash-based) but are slow I/O
    if [[ -n "$disk_name" ]] && [[ "$disk_name" == mmcblk* ]]; then
        echo "mmcblk"
        return 0
    fi
```

- [ ] **Step 4: Run the test again**

Run: `bash internal/test-hardware-detection.sh`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add internal/detect-hardware.sh internal/test-hardware-detection.sh
git commit -m "fix: detect mmcblk devices before rotational flag check

mmcblk (microSD/eMMC) devices report rotational=0 and were misclassified
as SSD. Check device name first so they get the correct 'mmcblk' type."
```

---

### Task 2: Reorder mkdir in run.sh

**Files:**
- Modify: `internal/run.sh:7-46`

- [ ] **Step 1: Move and expand the mkdir command**

In `internal/run.sh`:

1. Delete line 45-46:
```bash
# Create the base storage directories
mkdir -p /var/www/storage/{app,framework/{cache,data,views},logs};
```

2. Insert the following after line 7 (`source /var/www/internal/service-status.sh;`), before the PHP Performance Optimization section:

```bash
# Create the base storage directories (must happen before ramdisk setup
# so all directories exist on disk before being copied to tmpfs)
mkdir -p /var/www/storage/{app/{gcode,recordings,public,plugins},framework/{cache,data,views},logs};
```

- [ ] **Step 2: Verify the script parses correctly**

Run: `bash -n internal/run.sh`
Expected: No output (no syntax errors)

- [ ] **Step 3: Commit**

```bash
git add internal/run.sh
git commit -m "fix: move mkdir before ramdisk setup and add persistent subdirs

The ramdisk copies /var/www to tmpfs, so all directories must exist on
disk first. Also adds storage/app/{gcode,recordings,public,plugins}
as bind-mount targets for persistent paths."
```

---

### Task 3: Rewrite ramdisk-setup.sh — logging and configuration

**Files:**
- Modify: `internal/ramdisk-setup.sh` (full rewrite, starting with foundation)

- [ ] **Step 1: Write the script header, configuration, and logging functions**

Replace the entire contents of `internal/ramdisk-setup.sh` with:

```bash
#!/bin/bash
# Full /var/www ramdisk with persistent path exclusions
# Copies entire /var/www to tmpfs, then bind-mounts persistent dirs back to disk
# Self-sizes based on actual data; skips if insufficient RAM or ineligible role
# Falls back gracefully — app works fine without ramdisk (OPcache still active)

set -euo pipefail

# Configuration
RAMDISK_MOUNT="/tmp/wprint3d-ramdisk"
APP_ROOT="/var/www"

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
```

- [ ] **Step 2: Verify syntax**

Run: `bash -n internal/ramdisk-setup.sh`
Expected: No output

- [ ] **Step 3: Commit**

```bash
git add internal/ramdisk-setup.sh
git commit -m "refactor: ramdisk-setup.sh foundation with config and logging"
```

---

### Task 4: Ramdisk-setup.sh — gate checks (role, developer mode, storage, RAM)

**Files:**
- Modify: `internal/ramdisk-setup.sh` (append gate check functions)

**Note:** `ROLE` and `DEVELOPER_MODE` are set as container environment variables by the orchestrator (Docker). Since `ramdisk-setup.sh` runs as a subprocess (`bash`, not `source`), these must be exported env vars — which they are in standard container deployments. `WPRINT3D_STORAGE_TYPE` and `WPRINT3D_AVAILABLE_MEMORY_MB` are explicitly exported by `detect-hardware.sh` (which is `source`d by `run.sh`).

- [ ] **Step 1: Add the role eligibility check function**

Append to `internal/ramdisk-setup.sh`:

```bash
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
```

- [ ] **Step 2: Add the pre-flight checks function**

Append to `internal/ramdisk-setup.sh`:

```bash
# Run all pre-flight checks before creating ramdisk
# Returns 0 if ramdisk should proceed, 1 if it should be skipped
preflight_checks() {
    local storage_type="${WPRINT3D_STORAGE_TYPE:-unknown}"
    local available_mem="${WPRINT3D_AVAILABLE_MEMORY_MB:-0}"
    local force_ramdisk="${WPRINT3D_FORCE_RAMDISK:-auto}"

    # Check developer mode
    if [[ "${DEVELOPER_MODE:-}" == "true" ]]; then
        log_info "Developer mode active, skipping ramdisk"
        return 1
    fi

    # Check if explicitly disabled
    if [[ "$force_ramdisk" == "0" ]]; then
        log_info "Ramdisk explicitly disabled via WPRINT3D_FORCE_RAMDISK=0"
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
```

- [ ] **Step 3: Verify syntax**

Run: `bash -n internal/ramdisk-setup.sh`
Expected: No output

- [ ] **Step 4: Commit**

```bash
git add internal/ramdisk-setup.sh
git commit -m "feat: add role eligibility and pre-flight gate checks to ramdisk"
```

---

### Task 5: Ramdisk-setup.sh — measure and copy functions

**Files:**
- Modify: `internal/ramdisk-setup.sh` (append measure and copy functions)

- [ ] **Step 1: Add the data measurement function**

Append to `internal/ramdisk-setup.sh` (bash resolves functions at call time, so order doesn't matter):

```bash
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
```

- [ ] **Step 2: Add the copy-to-ramdisk function**

Append to `internal/ramdisk-setup.sh`:

```bash
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

    rsync -a "${exclude_args[@]}" "$APP_ROOT/" "$RAMDISK_MOUNT/" 2>/dev/null || {
        # Fallback to cp if rsync not available
        log_warn "rsync not available, falling back to cp"

        # Use tar to handle excludes with cp fallback
        local tar_excludes=()
        for dir in "${PERSISTENT_DIRS[@]}"; do
            tar_excludes+=(--exclude="$dir")
        done
        if [[ -f "$APP_ROOT/.env" ]] && [[ ! -L "$APP_ROOT/.env" ]]; then
            tar_excludes+=(--exclude=".env")
        fi

        tar -C "$APP_ROOT" "${tar_excludes[@]}" -cf - . | tar -C "$RAMDISK_MOUNT" -xf -
    }

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
```

- [ ] **Step 3: Verify syntax**

Run: `bash -n internal/ramdisk-setup.sh`
Expected: No output

- [ ] **Step 4: Commit**

```bash
git add internal/ramdisk-setup.sh
git commit -m "feat: add data measurement and copy functions to ramdisk setup"
```

---

### Task 6: Ramdisk-setup.sh — mount, bind-back, and rollback

**Files:**
- Modify: `internal/ramdisk-setup.sh` (append mount and rollback functions)

- [ ] **Step 1: Add the rollback function**

Append to `internal/ramdisk-setup.sh`:

```bash
# Track successful bind mounts for rollback
declare -a COMPLETED_BINDS=()

# Stash location for original persistent dirs (before overlay hides them)
STASH_DIR="/tmp/wprint3d-persistent-stash"

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
```

- [ ] **Step 2: Add the bind-back persistent dirs function**

Append to `internal/ramdisk-setup.sh`:

```bash
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
        # Bind-mount the file (not directory)
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
```

- [ ] **Step 3: Verify syntax**

Run: `bash -n internal/ramdisk-setup.sh`
Expected: No output

- [ ] **Step 4: Commit**

```bash
git add internal/ramdisk-setup.sh
git commit -m "feat: add mount rollback and persistent dir bind-back to ramdisk"
```

---

### Task 7: Ramdisk-setup.sh — main setup function and entry point

**Files:**
- Modify: `internal/ramdisk-setup.sh` (append main function and entry point)

- [ ] **Step 1: Add the main setup_ramdisk function with pre-overlay stashing**

Before overlaying `/var/www`, the script bind-mounts each persistent directory to a temporary stash location. After the overlay hides the originals, it binds the stashed references back on top.

Append to `internal/ramdisk-setup.sh`:

```bash
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
    fi

    log_info "$APP_ROOT is now served from ramdisk"

    # Bind persistent directories back from stash onto the overlay
    if ! bind_persistent_dirs "$STASH_DIR"; then
        return 1
    fi

    # Verify critical mounts
    local failed=0
    for dir in "${PERSISTENT_DIRS[@]}"; do
        if [[ -d "$APP_ROOT/$dir" ]] && ! mountpoint -q "$APP_ROOT/$dir" 2>/dev/null; then
            # Not all persistent dirs will be mountpoints if they didn't exist
            # Only warn if the stash source existed
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
```

- [ ] **Step 2: Verify syntax of the complete script**

Run: `bash -n internal/ramdisk-setup.sh`
Expected: No output

- [ ] **Step 3: Commit**

```bash
git add internal/ramdisk-setup.sh
git commit -m "feat: complete ramdisk-setup.sh with self-sizing and persistent stashing

Full rewrite: measures /var/www, adds 10% headroom, gates on 2x RAM,
checks role eligibility, stashes persistent dirs before overlay,
binds them back after. Includes rollback on failure."
```

---

### Task 8: Update documentation

**Files:**
- Modify: `docs/php-optimization.md`

- [ ] **Step 1: Update the ramdisk documentation section**

Find the "What's Stored on Ramdisk" section in `docs/php-optimization.md` and replace it to reflect the new full-ramdisk approach. Update the section to explain:

- The entire `/var/www` is now mirrored to ramdisk
- Only eligible roles get ramdisk (scheduler, concurrency-scheduler, ws-server)
- Persistent exclusions (storage/app/*, storage/logs, .env, .external-configs)
- Self-sizing behavior (measured + 10%, 2x RAM gate)
- mmcblk detection

Read the file first to find the exact section, then update accordingly.

- [ ] **Step 2: Commit**

```bash
git add docs/php-optimization.md
git commit -m "docs: update ramdisk section for full /var/www approach"
```

---

### Task 9: Integration verification

- [ ] **Step 1: Verify all modified files parse correctly**

Run:
```bash
bash -n internal/ramdisk-setup.sh && echo "ramdisk-setup.sh OK"
bash -n internal/detect-hardware.sh && echo "detect-hardware.sh OK"
bash -n internal/run.sh && echo "run.sh OK"
```
Expected: All three print "OK"

- [ ] **Step 2: Verify the hardware detection test passes**

Run: `bash internal/test-hardware-detection.sh`
Expected: "PASS: All hardware detection tests passed"

- [ ] **Step 3: Verify file permissions**

Run: `ls -la internal/ramdisk-setup.sh internal/detect-hardware.sh`
Expected: Both have executable permissions. If not:
```bash
chmod +x internal/ramdisk-setup.sh internal/detect-hardware.sh
```

- [ ] **Step 4: Verify the ordering in run.sh is correct**

Run:
```bash
grep -n 'mkdir.*storage\|ramdisk-setup\|detect-hardware\|service-status' internal/run.sh | head -10
```
Expected: `mkdir` line number is LESS THAN `ramdisk-setup` line number.

- [ ] **Step 5: Final commit if any fixes were needed**

```bash
git add -A
git commit -m "fix: integration verification fixes for ramdisk setup"
```
(Only if there were issues to fix. Skip if everything passed.)
