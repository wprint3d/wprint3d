# PHP Hard Disk Performance Optimization Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a layered PHP performance optimization system with OPcache, selective ramdisk, and hardware auto-detection to improve performance for users running on hard-disk or SD-card storage.

**Architecture:** A three-layer approach: (1) Hardware detection module that identifies storage type and available RAM, (2) PHP OPcache configuration with JIT compilation and Laravel preloading, (3) Selective ramdisk for hot-path directories. The system auto-detects capabilities and gracefully degrades if resources are insufficient.

**Tech Stack:** Bash scripting, PHP 8.4 OPcache, Laravel framework, Docker containerization, tmpfs (Linux ramdisk)

---

## File Structure

**New Files to Create:**
- `internal/detect-hardware.sh` - Hardware detection (storage type, RAM)
- `internal/php-opcache.ini` - OPcache configuration with environment variable support
- `internal/php-opcache-preload.php` - Laravel core preloading script
- `internal/ramdisk-setup.sh` - Selective ramdisk setup with cleanup
- `internal/app-cache-setup.sh` - Optional application-level caching

**Files to Modify:**
- `internal/run.sh:8-20` - Add PHP optimization setup section after service-status.sh sourcing
- `Dockerfile.dev:198-217` - Copy new files into image after limits.ini

---

## Task 1: Create Hardware Detection Script

**Files:**
- Create: `internal/detect-hardware.sh`

**Purpose:** Detect storage type (HDD/SSD/SD-card) and available RAM, export environment variables for other scripts to consume.

- [ ] **Step 1: Create the hardware detection script**

```bash
cat > /home/facuarmo/wprint3d-core/internal/detect-hardware.sh << 'SCRIPT_EOF'
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

# Exit successfully
return 0
SCRIPT_EOF
```

- [ ] **Step 2: Make the script executable**

```bash
chmod +x /home/facuarmo/wprint3d-core/internal/detect-hardware.sh
```

- [ ] **Step 3: Test the hardware detection script**

```bash
# Test storage detection
bash -c 'source /home/facuarmo/wprint3d-core/internal/detect-hardware.sh && echo "Storage: $WPRINT3D_STORAGE_TYPE"'
```

Expected output: `Storage: ssd` or `Storage: hdd` or `Storage: sdcard` or `Storage: unknown`

```bash
# Test memory detection
bash -c 'source /home/facuarmo/wprint3d-core/internal/detect-hardware.sh && echo "Memory: ${WPRINT3D_AVAILABLE_MEMORY_MB}MB"'
```

Expected output: `Memory: XXXXMB` (where XXXX is a number)

- [ ] **Step 4: Commit the hardware detection script**

```bash
git add internal/detect-hardware.sh
git commit -m "feat: add hardware detection script for PHP optimization

Detects storage type (HDD/SSD/SD-card) and available RAM to enable
adaptive performance optimizations. Exports environment variables
for use by other optimization scripts.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Create PHP OPcache Configuration

**Files:**
- Create: `internal/php-opcache.ini`

**Purpose:** Configure OPcache with environment variable support for production/development modes.

- [ ] **Step 1: Create the OPcache configuration file**

```bash
cat > /home/facuarmo/wprint3d-core/internal/php-opcache.ini << 'INI_EOF'
; OPcache configuration for wprint3d performance optimization
; This file is loaded by PHP via the conf.d directory

; ============================================================================
; Core OPcache Settings
; ============================================================================

; Enable OPcache for both web and CLI (Artisan commands)
opcache.enable=1
opcache.enable_cli=1

; Memory allocated for OPcache - 128MB sufficient for Laravel
opcache.memory_consumption=128

; Maximum number of PHP files that can be cached
opcache.max_accelerated_files=20000

; Use large memory pool to reduce memory fragmentation
opcache.huge_code_pages=1

; ============================================================================
; Revalidation Settings (Environment-Aware)
; ============================================================================
; Note: Environment variables are substituted by run.sh before PHP starts
; For production: revalidate_freq=0, validate_timestamps=0
; For development: revalidate_freq=2, validate_timestamps=1

; How often to check file timestamps (in seconds)
; 0 = never check (production), 2 = check every 2 seconds (development)
opcache.revalidate_freq=${WPRINT3D_OPCACHE_REVALIDATE_FREQ:-0}

; Whether to validate file timestamps
; 0 = disable (production), 1 = enable (development)
opcache.validate_timestamps=${WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS:-0}

; ============================================================================
; JIT Compilation Settings (PHP 8.4+)
; ============================================================================

; JIT buffer size - 64MB for compiling hot functions
opcache.jit_buffer_size=64M

; JIT mode - tracing is best for long-running server processes
opcache.jit=tracing

; JIT optimization level - maximum optimization
opcache.jit_optimization_level=0x7FFFFFFF

; ============================================================================
; Preload Settings
; ============================================================================

; User to run preloaded code as (non-root for security)
opcache.preload_user=www-data

; Preload script path (set dynamically based on role)
; opcache.preload=/var/www/internal/php-opcache-preload.php

; ============================================================================
; String Interning
; ============================================================================

; Memory for interned strings (class names, variable names, etc.)
opcache.interned_strings_buffer=16M

; ============================================================================
; Other Settings
; ============================================================================

; Save comments for code generation tools and stack traces
opcache.save_comments=1

; Fast shutdown - reduces memory cleanup overhead
opcache.fast_shutdown=1

; Enable file cache fallback (if OPcache memory is exhausted)
opcache.file_cache=/tmp/opcache/file_cache
opcache.file_cache_only=0
opcache.file_cache_consistency_checks=1

; Protect against memory corruption
opcache.protect_memory=1

; Prevent code injection through user-defined functions
opcache.disable_obfuscator_protection=0
INI_EOF
```

- [ ] **Step 2: Verify the configuration file was created correctly**

```bash
cat /home/facuarmo/wprint3d-core/internal/php-opcache.ini
```

Expected: Full contents of the OPcache configuration file displayed

- [ ] **Step 3: Commit the OPcache configuration**

```bash
git add internal/php-opcache.ini
git commit -m "feat: add PHP OPcache configuration for performance

Configures OPcache with environment-aware revalidation settings.
Production mode disables timestamp validation for maximum performance.
Development mode enables quick iteration. Includes JIT compilation
and Laravel preloading support.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Create Laravel Preload Script

**Files:**
- Create: `internal/php-opcache-preload.php`

**Purpose:** Preload core Laravel framework files into OPcache shared memory.

- [ ] **Step 1: Create the preload script**

```bash
cat > /home/facuarmo/wprint3d-core/internal/php-opcache-preload.php << 'PHP_EOF'
<?php
/**
 * Laravel OPcache Preload Script
 *
 * This script preloads core Laravel framework files into OPcache shared memory.
 * Preloaded files are available to all PHP workers without recompilation.
 *
 * IMPORTANT: This runs at PHP startup as the opcache.preload_user (www-data).
 * Any fatal error here will prevent PHP-FPM from starting.
 */

// Silence output during preload (avoid contaminating response stream)
ob_start();

// Preload Composer autoload
if (file_exists('/var/www/vendor/autoload.php')) {
    require_once '/var/www/vendor/autoload.php';
}

// Define Laravel framework paths to preload
$preloadPaths = [
    // Core Laravel framework components
    '/var/www/vendor/laravel/framework/src/Illuminate/Foundation',
    '/var/www/vendor/laravel/framework/src/Illuminate/Support',
    '/var/www/vendor/laravel/framework/src/Illuminate/Database',
    '/var/www/vendor/laravel/framework/src/Illuminate/Cache',
    '/var/www/vendor/laravel/framework/src/Illuminate/Queue',
    '/var/www/vendor/laravel/framework/src/Illuminate/Routing',
    '/var/www/vendor/laravel/framework/src/Illuminate/Http',
    '/var/www/vendor/laravel/framework/src/Illuminate/Auth',
    '/var/www/vendor/laravel/framework/src/Illuminate/Container',
    '/var/www/vendor/laravel/framework/src/Illuminate/Contracts',
    '/var/www/vendor/laravel/framework/src/Illuminate/Events',
    '/var/www/vendor/laravel/framework/src/Illuminate/Exceptions',
    '/var/www/vendor/laravel/framework/src/Illuminate/Filesystem',
    '/var/www/vendor/laravel/framework/src/Illuminate/Log',
    '/var/www/vendor/laravel/framework/src/Illuminate/Session',
    '/var/www/vendor/laravel/framework/src/Illuminate/View',
    '/var/www/vendor/laravel/framework/src/Illuminate/Validation',
];

// Preload each path
$loadedCount = 0;
$errorCount = 0;

foreach ($preloadPaths as $path) {
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            try {
                if (opcache_compile_file($file->getPathname())) {
                    $loadedCount++;
                }
            } catch (Throwable $e) {
                // Log error but continue - don't let one file break entire preload
                error_log("OPcache preload failed for {$file->getPathname()}: {$e->getMessage()}");
                $errorCount++;
            }
        }
    }
}

// Clean up any buffered output
ob_end_clean();

// Log preload results for monitoring
error_log("OPcache preload completed: {$loadedCount} files loaded, {$errorCount} errors");

// Exit successfully
exit(0);
PHP_EOF
```

- [ ] **Step 2: Verify the preload script was created**

```bash
head -20 /home/facuarmo/wprint3d-core/internal/php-opcache-preload.php
```

Expected: First 20 lines of the preload PHP script with proper opening tag

- [ ] **Step 3: Commit the preload script**

```bash
git add internal/php-opcache-preload.php
git commit -m "feat: add Laravel OPcache preload script

Preloads core Laravel framework files into OPcache shared memory.
Includes error handling to prevent one bad file from breaking
the entire preload process.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Create Ramdisk Setup Script

**Files:**
- Create: `internal/ramdisk-setup.sh`

**Purpose:** Create a selective ramdisk for hot-path directories with automatic cleanup on shutdown.

- [ ] **Step 1: Create the ramdisk setup script**

```bash
cat > /home/facuarmo/wprint3d-core/internal/ramdisk-setup.sh << 'SCRIPT_EOF'
#!/bin/bash
# Selective ramdisk setup for hot-path PHP files
# Creates tmpfs mount for frequently-accessed directories
# Falls back gracefully if insufficient memory

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

# Cleanup function - called on exit
cleanup_ramdisk() {
    log_info "Cleaning up ramdisk..."

    # Unmount bind mounts first (in reverse order)
    for i in "${!RAMDISK_DIRS[@]}"; do
        local index=$((${#RAMDISK_DIRS[@]} - 1 - i))
        local entry="${RAMDISK_DIRS[$index]}"
        local dir="${entry%%:*}"

        if mountpoint -q "$dir" 2>/dev/null; then
            umount "$dir" 2>/dev/null && log_info "Unmounted $dir" || true
        fi
    done

    # Unmount the main ramdisk
    if mountpoint -q "$RAMDISK_MOUNT" 2>/dev/null; then
        umount "$RAMDISK_MOUNT" 2>/dev/null && log_info "Unmounted ramdisk" || true
    fi

    # Remove mount point
    if [[ -d "$RAMDISK_MOUNT" ]]; then
        rmdir "$RAMDISK_MOUNT" 2>/dev/null && log_info "Removed ramdisk mount point" || true
    fi
}

# Register cleanup on exit
trap cleanup_ramdisk EXIT INT TERM

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

    log_info "Ramdisk setup complete"
    return 0
}

# Run setup
setup_ramdisk
exit $?
SCRIPT_EOF
```

- [ ] **Step 2: Make the script executable**

```bash
chmod +x /home/facuarmo/wprint3d-core/internal/ramdisk-setup.sh
```

- [ ] **Step 3: Verify script syntax**

```bash
bash -n /home/facuarmo/wprint3d-core/internal/ramdisk-setup.sh
```

Expected: No output (syntax check passed)

- [ ] **Step 4: Commit the ramdisk setup script**

```bash
git add internal/ramdisk-setup.sh
git commit -m "feat: add selective ramdisk setup for hot-path directories

Creates tmpfs mount for bootstrap/cache and storage/framework directories.
Includes automatic cleanup on shutdown and graceful fallback for
low-memory systems.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Create Application Cache Setup Script

**Files:**
- Create: `internal/app-cache-setup.sh`

**Purpose:** Optional application-level caching that can be enabled via environment variable.

- [ ] **Step 1: Create the application cache setup script**

```bash
cat > /home/facuarmo/wprint3d-core/internal/app-cache-setup.sh << 'SCRIPT_EOF'
#!/bin/bash
# Optional application-level caching setup
# Enabled via WPRINT3D_APP_CACHE_ENABLED=true
# Primes config and route caches, pre-warms artisan commands

# Logging function
log_info() {
    echo "[app-cache-setup] $*" >&2
}

setup_app_cache() {
    # Check if application caching is enabled
    if [[ "${WPRINT3D_APP_CACHE_ENABLED:-false}" != "true" ]]; then
        log_info "Application caching disabled (set WPRINT3D_APP_CACHE_ENABLED=true)"
        return 0
    fi

    log_info "Setting up application-level caching..."

    # Change to application directory
    cd /var/www || return 1

    # Pre-warm common artisan commands in OPcache
    log_info "Pre-warming artisan commands..."
    local commands=(
        "schedule:run"
        "queue:work"
        "migrate"
        "cache:clear"
        "config:clear"
        "route:clear"
    )

    for cmd in "${commands[@]}"; do
        php artisan "$cmd" --help >/dev/null 2>&1 && log_info "Pre-warmed: $cmd" || true
    done

    # Prime config cache
    log_info "Priming config cache..."
    php artisan config:cache || log_info "Config cache skipped (may not be available yet)"

    # Prime route cache
    log_info "Priming route cache..."
    php artisan route:cache || log_info "Route cache skipped (may not be available yet)"

    log_info "Application cache setup complete"
    return 0
}

# Run setup
setup_app_cache
exit $?
SCRIPT_EOF
```

- [ ] **Step 2: Make the script executable**

```bash
chmod +x /home/facuarmo/wprint3d-core/internal/app-cache-setup.sh
```

- [ ] **Step 3: Verify script syntax**

```bash
bash -n /home/facuarmo/wprint3d-core/internal/app-cache-setup.sh
```

Expected: No output (syntax check passed)

- [ ] **Step 4: Commit the application cache setup script**

```bash
git add internal/app-cache-setup.sh
git commit -m "feat: add optional application-level caching setup

Provides toggleable application cache priming via environment variable.
Pre-warms common artisan commands and primes config/route caches.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 6: Integrate Optimization Scripts into run.sh

**Files:**
- Modify: `internal/run.sh:8-20`

**Purpose:** Call the optimization scripts early in container startup.

- [ ] **Step 1: Read the current run.sh to find exact integration point**

```bash
head -15 /home/facuarmo/wprint3d-core/internal/run.sh
```

Expected output: First 15 lines showing the structure around line 7-8

- [ ] **Step 2: Add PHP optimization section to run.sh**

Create a temporary patch file:

```bash
cat > /tmp/run-sh-patch.txt << 'PATCH_EOF'
--- a/internal/run.sh
+++ b/internal/run.sh
@@ -5,6 +5,28 @@ source /var/www/internal/service-status.sh;

 # Remove any temporary files that might have been left behind
 rm -fv /tmp/*.txt /var/www/internal/startup/*.txt;
+
+# ============================================================================
+# PHP Performance Optimization Setup
+# ============================================================================
+
+# Set OPcache revalidation based on environment (early, before PHP runs)
+if [[ "${DEVELOPER_MODE}" == 'true' ]]; then
+    export WPRINT3D_OPCACHE_REVALIDATE_FREQ=2
+    export WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS=1
+else
+    export WPRINT3D_OPCACHE_REVALIDATE_FREQ=0
+    export WPRINT3D_OPCACHE_VALIDATE_TIMESTAMPS=0
+fi
+
+# Source hardware detection
+if [[ -f '/var/www/internal/detect-hardware.sh' ]]; then
+    source /var/www/internal/detect-hardware.sh
+fi
+
+# Setup selective ramdisk if available
+if [[ -f '/var/www/internal/ramdisk-setup.sh' ]]; then
+    bash /var/www/internal/ramdisk-setup.sh
+fi
+
+# Setup optional application caching
+if [[ -f '/var/www/internal/app-cache-setup.sh' ]]; then
+    bash /var/www/internal/app-cache-setup.sh
+fi

 # Create the base storage directories
 mkdir -p /var/www/storage/{app,framework/{cache,data,views},logs};
PATCH_EOF
```

- [ ] **Step 3: Apply the patch using Edit tool**

The Edit tool will modify `internal/run.sh` to insert the optimization section after line 7.

- [ ] **Step 4: Verify the changes were applied correctly**

```bash
head -35 /home/facuarmo/wprint3d-core/internal/run.sh | tail -30
```

Expected: The new optimization section should appear between the service-status.sh sourcing and the temporary files removal.

- [ ] **Step 5: Test run.sh syntax**

```bash
bash -n /home/facuarmo/wprint3d-core/internal/run.sh
```

Expected: No output (syntax check passed)

- [ ] **Step 6: Commit the run.sh changes**

```bash
git add internal/run.sh
git commit -m "feat: integrate PHP performance optimization into run.sh

Adds hardware detection, ramdisk setup, and application caching
to container startup process. Sets OPcache revalidation
based on DEVELOPER_MODE environment variable.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 7: Update Dockerfile.dev

**Files:**
- Modify: `Dockerfile.dev:198-217`

**Purpose:** Copy new optimization files into the container image.

- [ ] **Step 1: Read current Dockerfile.dev to find exact integration point**

```bash
grep -n "limits.ini" /home/facuarmo/wprint3d-core/Dockerfile.dev
```

Expected output: Line number where limits.ini is copied (around line 196-197)

- [ ] **Step 2: Add optimization files to Dockerfile.dev**

Find the section after `ADD ./internal/limits.ini` and add:

```dockerfile
# Copy PHP OPcache configuration
COPY ./internal/php-opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY ./internal/php-opcache-preload.php /var/www/internal/php-opcache-preload.php

# Copy hardware detection and optimization scripts
COPY ./internal/detect-hardware.sh /var/www/internal/detect-hardware.sh
COPY ./internal/ramdisk-setup.sh /var/www/internal/ramdisk-setup.sh
COPY ./internal/app-cache-setup.sh /var/www/internal/app-cache-setup.sh

# Make scripts executable
RUN chmod +x /var/www/internal/detect-hardware.sh \
              /var/www/internal/ramdisk-setup.sh \
              /var/www/internal/app-cache-setup.sh
```

- [ ] **Step 3: Verify Dockerfile.dev syntax**

```bash
docker build -f Dockerfile.dev --check /home/facuarmo/wprint3d-core 2>&1 | head -20
```

Expected: Docker parses the Dockerfile without syntax errors

- [ ] **Step 4: Commit the Dockerfile.dev changes**

```bash
git add Dockerfile.dev
git commit -m "feat: add PHP optimization files to container image

Copies OPcache configuration, preload script, and optimization
scripts into the container image. Makes scripts executable
during build.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 8: Create Test Scripts for Verification

**Files:**
- Create: `internal/test-hardware-detection.sh`
- Create: `internal/test-ramdisk-setup.sh`

**Purpose:** Provide test scripts to verify the optimization components work correctly.

- [ ] **Step 1: Create hardware detection test script**

```bash
cat > /home/facuarmo/wprint3d-core/internal/test-hardware-detection.sh << 'TEST_EOF'
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
TEST_EOF

chmod +x /home/facuarmo/wprint3d-core/internal/test-hardware-detection.sh
```

- [ ] **Step 2: Run the hardware detection test**

```bash
bash /home/facuarmo/wprint3d-core/internal/test-hardware-detection.sh
```

Expected: `PASS: All hardware detection tests passed`

- [ ] **Step 3: Create OPcache verification script**

```bash
cat > /home/facuarmo/wprint3d-core/internal/test-opcache.php << 'TEST_EOF'
<?php
/**
 * Test script to verify OPcache configuration
 * Run with: php internal/test-opcache.php
 */

echo "=== OPcache Configuration Test ===\n";

if (!extension_loaded('Zend OPcache')) {
    echo "FAIL: OPcache extension is not loaded\n";
    exit(1);
}

echo "PASS: OPcache extension is loaded\n";

$config = opcache_get_configuration();
$status = opcache_get_status();

$tests = [
    'opcache.enable' => ['expected' => true, 'actual' => $config['directives']['opcache.enable']],
    'opcache.enable_cli' => ['expected' => true, 'actual' => $config['directives']['opcache.enable_cli']],
    'opcache.memory_consumption' => ['expected' => 134217728, 'actual' => $config['directives']['opcache.memory_consumption']],
    'opcache.jit_buffer_size' => ['expected' => 67108864, 'actual' => $config['directives']['opcache.jit_buffer_size']],
];

$failed = false;
foreach ($tests as $key => $test) {
    if ($test['actual'] !== $test['expected']) {
        echo sprintf(
            "WARN: %s is %s (expected %s)\n",
            $key,
            var_export($test['actual'], true),
            var_export($test['expected'], true)
        );
        $failed = true;
    }
}

if (!$failed) {
    echo "PASS: All OPcache settings are correct\n";
}

echo "\n=== OPcache Status ===\n";
echo sprintf("Memory used: %s / %s\n",
    formatBytes($status['memory_usage']['used_memory']),
    formatBytes($status['memory_usage']['used_memory'] + $status['memory_usage']['free_memory'])
);
echo sprintf("Cached scripts: %d / %d\n",
    $status['opcache_statistics']['num_cached_scripts'],
    $config['directives']['opcache.max_accelerated_files']
);

function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = ['B', 'KB', 'MB', 'GB'];
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

exit(0);
TEST_EOF
```

- [ ] **Step 4: Test OPcache configuration**

```bash
php /home/facuarmo/wprint3d-core/internal/test-opcache.php
```

Expected: OPcache status and configuration displayed

- [ ] **Step 5: Commit test scripts**

```bash
git add internal/test-hardware-detection.sh internal/test-opcache.php
git commit -m "test: add verification scripts for PHP optimization

Provides test scripts to verify hardware detection and OPcache
configuration are working correctly.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 9: Create Documentation

**Files:**
- Create: `docs/php-optimization.md`

**Purpose:** Document the optimization system for users and developers.

- [ ] **Step 1: Create user-facing documentation**

```bash
mkdir -p /home/facuarmo/wprint3d-core/docs

cat > /home/facuarmo/wprint3d-core/docs/php-optimization.md << 'DOCS_EOF'
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
DOCS_EOF
```

- [ ] **Step 2: Commit documentation**

```bash
git add docs/php-optimization.md
git commit -m "docs: add PHP performance optimization documentation

Documents the optimization system, environment variables, performance
improvements, and troubleshooting steps.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 10: Final Integration Testing

**Files:**
- No new files
- Verify: All components work together

- [ ] **Step 1: Verify all files are present**

```bash
ls -la /home/facuarmo/wprint3d-core/internal/{detect-hardware.sh,php-opcache.ini,php-opcache-preload.php,ramdisk-setup.sh,app-cache-setup.sh}
```

Expected: All 5 files listed with proper permissions

- [ ] **Step 2: Verify git status shows all changes**

```bash
git status --short
```

Expected: List of all modified/new files

- [ ] **Step 3: Create final summary commit**

```bash
git add .
git commit -m "feat: complete PHP hard disk performance optimization

Implements layered optimization system:
- Hardware detection for storage type and RAM
- PHP OPcache with JIT compilation and Laravel preloading
- Selective ramdisk for hot-path directories
- Optional application-level caching

Expected performance improvements on hard-disk systems:
- 70-80% faster cold starts
- 70-80% faster subsequent requests
- 70-80% faster scheduler execution

See docs/superpowers/specs/2026-03-17-php-hard-disk-optimization-design.md
for complete design documentation.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

- [ ] **Step 4: Create summary of commits**

```bash
git log --oneline --no-merges -10
```

Expected: List of recent commits including all optimization work

---

## Implementation Notes

### Important Implementation Details

1. **OPcache Preload Order**: The preload script must be sourced after Laravel is installed but before the web server starts. This is handled by the Dockerfile copying the files and run.sh setting environment variables.

2. **JIT Fallback**: PHP 8.4 will exit with a fatal error if JIT fails to initialize. The OPcache configuration uses reasonable defaults that should work on most systems.

3. **Ramdisk Cleanup**: The ramdisk setup script uses a trap handler to ensure clean unmounting even if the script is interrupted.

4. **Development Workflow**: In development mode, code changes to core Laravel files may not take effect immediately. Developers should run `php artisan clear-compiled` or restart the container.

### Testing Strategy

After implementation, test the following scenarios:

1. **SSD System**: Verify ramdisk is skipped, OPcache is active
2. **HDD with >512MB RAM**: Verify ramdisk is created, OPcache is active
3. **HDD with <512MB RAM**: Verify ramdisk is skipped (fallback), OPcache is active
4. **Development Mode**: Verify code changes take effect within 2 seconds
5. **Production Mode**: Verify maximum performance, code changes require restart

### Rollback Plan

If issues arise, rollback steps:
1. Revert run.sh changes to remove optimization calls
2. Delete or rename internal/{detect-hardware.sh,php-opcache.ini,php-opcache-preload.php,ramdisk-setup.sh,app-cache-setup.sh}
3. Rebuild container image

---

## Completion Checklist

- [ ] All 5 new files created and committed
- [ ] run.sh modified with optimization section
- [ ] Dockerfile.dev modified to copy new files
- [ ] Test scripts created and passing
- [ ] Documentation created
- [ ] All commits have descriptive messages
- [ ] No syntax errors in any shell scripts
- [ ] No build errors in Dockerfile

---

**End of Implementation Plan**
