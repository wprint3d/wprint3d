# Consolidated Worker Service Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable comma-separated `ROLE` values so multiple roles run under supervisord in a single container, sharing one ramdisk.

**Architecture:** Parse `ROLE` into an array. When multiple roles are present, generate supervisord program configs for each role and run supervisord in foreground. Single-role deployments use the existing `if/elif` chain unchanged (except `concurrency-scheduler`, which is simplified to also use the config generator). The ramdisk eligibility check is updated to match any role in a comma-separated list.

**Tech Stack:** Bash, supervisord, Docker Compose, Laravel Artisan

---

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `internal/generate-supervisor-configs.sh` | Create | Generates supervisord `.conf` files in `/tmp/supervisor/` based on role list |
| `internal/run.sh` | Modify | Parse ROLE into array, add multi-role branch before existing `if/elif` chain, simplify concurrency-scheduler, remove dead short-schedule code |
| `internal/ramdisk-setup.sh` | Modify | Update `check_role_eligible()` to handle comma-separated ROLE |
| `internal/supervisor/supervisord.conf` | Modify | Add `nodaemon=true` (done in same commit as run.sh changes since they're tightly coupled) |
| `docker-compose.yml` | Modify | Replace scheduler + concurrency-scheduler + ws-server with single worker service |

---

### Task 1: Create supervisor config generator script

**Files:**
- Create: `internal/generate-supervisor-configs.sh`

**Context:** This script is called with role names as arguments. It generates supervisord `.conf` files in `/tmp/supervisor/` for each role's processes. Queue worker configs are NOT handled here — they're dynamically managed at runtime by `app/Console/Services/Concurrent/RefreshPrinterWorkers.php`. The script cleans stale configs before generating new ones to prevent leftover configs from prior runs.

Note: The `scheduler` role only generates a `cron` program. The `short-schedule:run` artisan command does not exist in this codebase — the `KIND=short` branch in `run.sh` is dead code that will be removed in Task 3.

- [ ] **Step 1: Create the script**

```bash
#!/bin/bash
# Generates supervisord program .conf files based on active roles
# Usage: generate-supervisor-configs.sh role1 [role2 ...]
# Writes to /tmp/supervisor/*.conf (included by supervisord.conf)

set -euo pipefail

SUPERVISOR_CONF_DIR="/tmp/supervisor"
SUPERVISOR_LOG_DIR="/tmp/supervisor/logs"

mkdir -p "$SUPERVISOR_CONF_DIR" "$SUPERVISOR_LOG_DIR"

# Clean stale configs from prior runs to prevent loading leftover programs
rm -f "$SUPERVISOR_CONF_DIR"/*.conf

for role in "$@"; do
    case "$role" in
        scheduler)
            cat > "$SUPERVISOR_CONF_DIR/cron.conf" <<'EOF'
[program:cron]
command=cron -f
autorestart=true
stdout_logfile=/tmp/supervisor/logs/cron.log
stderr_logfile=/tmp/supervisor/logs/cron.log
EOF
            ;;

        concurrency-scheduler)
            cat > "$SUPERVISOR_CONF_DIR/concurrent-run.conf" <<'EOF'
[program:concurrent-run]
command=php artisan concurrent:run-indefinitely
directory=/var/www
autorestart=true
stdout_logfile=/tmp/supervisor/logs/concurrent-run.log
stderr_logfile=/tmp/supervisor/logs/concurrent-run.log
EOF

            cat > "$SUPERVISOR_CONF_DIR/log-rotator.conf" <<'EOF'
[program:log-rotator]
command=bash -c 'while true; do for log in /tmp/supervisor/logs/*.log; do truncate --size 512K "$log"; done; sleep 60; done'
autorestart=true
stdout_logfile=/dev/null
stderr_logfile=/dev/null
EOF
            ;;

        ws-server)
            cat > "$SUPERVISOR_CONF_DIR/reverb.conf" <<'EOF'
[program:reverb]
command=php artisan reverb:start --host 0.0.0.0 --port 6001
directory=/var/www
autorestart=true
stdout_logfile=/tmp/supervisor/logs/reverb.log
stderr_logfile=/tmp/supervisor/logs/reverb.log
EOF
            ;;

        *)
            echo "[generate-supervisor-configs] Unknown role: $role, skipping" >&2
            ;;
    esac
done

echo "[generate-supervisor-configs] Generated configs for: $*" >&2
```

- [ ] **Step 2: Verify syntax**

Run: `bash -n internal/generate-supervisor-configs.sh`
Expected: No output (valid syntax)

- [ ] **Step 3: Make executable and commit**

```bash
chmod +x internal/generate-supervisor-configs.sh
git add internal/generate-supervisor-configs.sh
git commit -m "feat: add supervisor config generator for multi-role containers"
```

---

### Task 2: Update ramdisk eligibility check for comma-separated ROLE

**Files:**
- Modify: `internal/ramdisk-setup.sh:48-65`

**Context:** `check_role_eligible()` currently compares `$ROLE` as a single string against the eligible roles array. It needs to split `$ROLE` on commas and check if ANY role in the list is eligible.

- [ ] **Step 1: Replace check_role_eligible function**

Replace `internal/ramdisk-setup.sh` lines 48-65 (the entire `check_role_eligible` function) with:

```bash
# Check if current role (or any role in comma-separated list) is eligible for ramdisk
check_role_eligible() {
    local role_str="${ROLE:-}"

    if [[ -z "$role_str" ]]; then
        log_info "No ROLE set, skipping ramdisk"
        return 1
    fi

    local IFS=','
    read -ra roles <<< "$role_str"

    for role in "${roles[@]}"; do
        for eligible in "${ELIGIBLE_ROLES[@]}"; do
            if [[ "$role" == "$eligible" ]]; then
                return 0
            fi
        done
    done

    log_info "No eligible role found in '$role_str', ramdisk not needed"
    return 1
}
```

- [ ] **Step 2: Verify syntax**

Run: `bash -n internal/ramdisk-setup.sh`
Expected: No output (valid syntax)

- [ ] **Step 3: Commit**

```bash
git add internal/ramdisk-setup.sh
git commit -m "feat: support comma-separated ROLE in ramdisk eligibility check"
```

---

### Task 3: Add multi-role support to run.sh and set supervisord to foreground

**Files:**
- Modify: `internal/run.sh:226-499`
- Modify: `internal/supervisor/supervisord.conf:7-9`

**Context:** This is the core change. These two files MUST be modified together because adding `nodaemon=true` to `supervisord.conf` would break the existing `concurrency-scheduler` single-role path if `run.sh` isn't updated simultaneously.

Changes to `run.sh`:
1. Parse `ROLE` into an array early (after line 225, before the role-handling block)
2. Add a multi-role branch BEFORE the existing `if/elif` chain (inside the `while true` loop, after the MACHINE_UUID wait logic at line 253)
3. Update the single-role `concurrency-scheduler` path to use config generation + foreground supervisord (since `nodaemon=true` is now the default)
4. Remove the dead `KIND=short` branch from the `scheduler` role (`short-schedule:run` artisan command does not exist)
5. Keep all other single-role paths unchanged

- [ ] **Step 1: Add `nodaemon=true` to supervisord.conf**

Change `internal/supervisor/supervisord.conf` lines 7-9 from:

```ini
[supervisord]
logfile=/tmp/supervisord.log ; (main log file;default $CWD/supervisord.log)
pidfile=/tmp/supervisord.pid ; (supervisord pidfile;default supervisord.pid)
```

to:

```ini
[supervisord]
nodaemon=true
logfile=/tmp/supervisord.log ; (main log file;default $CWD/supervisord.log)
pidfile=/tmp/supervisord.pid ; (supervisord pidfile;default supervisord.pid)
```

- [ ] **Step 2: Add ROLE parsing and has_role helper to run.sh**

After line 225 (the `redis-cli` wait loop `done;`), before line 226 (`if [[ -z $ROLE ]]`), add:

```bash
# Parse ROLE into array (supports comma-separated multi-role)
IFS=',' read -ra ROLES <<< "${ROLE:-}"

# Check if a specific role is in the ROLES array
has_role() {
    local target="$1"
    for r in "${ROLES[@]}"; do
        if [[ "$r" == "$target" ]]; then
            return 0
        fi
    done
    return 1
}
```

- [ ] **Step 3: Add multi-role branch**

Inside the `while true` loop, after the MACHINE_UUID wait logic (line 253, after `fi;`), add a new `if` block BEFORE the existing `if [[ "$ROLE" == 'server' ]]` at line 255. Change `if [[ "$ROLE" == 'server' ]]` to `elif [[ "$ROLE" == 'server' ]]`:

```bash
        if [[ ${#ROLES[@]} -gt 1 ]]; then
            # Multi-role mode: shared init + supervisord
            echo "Multi-role mode: ${ROLE}"

            # Shared housekeeping (runs once)
            php artisan cache:clear
            php artisan queue:flush
            php artisan queue:restart

            # Install crontab if scheduler is in the role list
            if has_role "scheduler"; then
                crontab /var/www/internal/cron/crontab
            fi

            # Generate supervisor configs for all roles
            bash /var/www/internal/generate-supervisor-configs.sh "${ROLES[@]}"

            # Start supervisord in foreground (blocks, keeps container alive)
            echo "Starting supervisord with roles: ${ROLE}"
            supervisord -c /var/www/internal/supervisor/supervisord.conf

        elif [[ "$ROLE" == 'server' ]]; then
```

- [ ] **Step 4: Update single-role concurrency-scheduler to use config generation**

Replace `internal/run.sh` lines 347-365 (the `concurrency-scheduler` elif block) with:

```bash
        elif [[ "$ROLE" == 'concurrency-scheduler' ]]; then
            php artisan cache:clear;
            php artisan queue:flush;
            php artisan queue:restart;

            # Generate supervisor configs and run in foreground
            bash /var/www/internal/generate-supervisor-configs.sh concurrency-scheduler

            echo 'Starting supervisord...';
            supervisord -c /var/www/internal/supervisor/supervisord.conf;
```

- [ ] **Step 5: Remove dead short-schedule code from scheduler role**

Replace `internal/run.sh` lines 490-499 (the `scheduler` elif block) with:

```bash
        elif [[ "$ROLE" == 'scheduler' ]]; then
            crontab /var/www/internal/cron/crontab;

            cron -f;
```

This removes the dead `KIND=short` branch. The `short-schedule:run` artisan command does not exist in this codebase.

- [ ] **Step 6: Verify syntax**

Run: `bash -n internal/run.sh && bash -n internal/supervisor/supervisord.conf; echo "OK"`
Expected: `OK` (supervisord.conf is INI, not bash — the bash check will fail but that's expected; just verify run.sh passes)

Actually, just run:
```bash
bash -n internal/run.sh && echo "run.sh OK"
```
Expected: `run.sh OK`

- [ ] **Step 7: Commit both files together**

```bash
git add internal/run.sh internal/supervisor/supervisord.conf
git commit -m "feat: add multi-role support with supervisord process management

- Parse ROLE as comma-separated list for multi-role containers
- Add supervisord foreground mode (nodaemon=true)
- Simplify concurrency-scheduler to use config generator
- Remove dead short-schedule:run code from scheduler role"
```

---

### Task 4: Update docker-compose.yml

**Files:**
- Modify: `docker-compose.yml:59-156`

**Context:** Replace three separate services (`scheduler` lines 59-81, `concurrency-scheduler` lines 108-132, `ws-server` lines 133-156) with a single `worker` service. Update `proxy` service's `depends_on` from `ws-server` to `worker`.

- [ ] **Step 1: Replace scheduler, concurrency-scheduler, and ws-server services**

Remove the three service blocks (`scheduler:` at line 59, `concurrency-scheduler:` at line 108, `ws-server:` at line 133) and replace them with a single `worker` service. Place it after `mapper:` (line 107):

```yaml
  worker:
    image: docker.io/wprint3d/wprint3d:latest
    restart: always
    logging:
      driver: ${CONTAINER_LOG_DRIVER:-local}
      options:
        max-size: "1m"
        mode: non-blocking
    privileged: true
    environment:
      - ROLE=scheduler,concurrency-scheduler,ws-server
      - COMPOSE_DIR=${PWD}
      - QUEUES=default:1,recordings:1,broadcasts:2,prints,previews,snapshots
      - SLEEP=5
    volumes:
      - /dev/bus/usb:/dev/bus/usb
      - /dev:/dev
      - /var/run/docker.sock:/var/run/docker.sock
      - /proc:/proc
      - storage:/var/www/storage
      - proxy:/var/www/proxy/internal
      - startup:/var/www/internal/startup
      - ./wprint3d-config:/var/www/.external-configs
      - ${PWD}:${PWD}
    depends_on:
      - redis
      - mongo
```

- [ ] **Step 2: Update proxy depends_on**

Change `proxy` service's `depends_on` at line 21 from:

```yaml
      - ws-server
```

to:

```yaml
      - worker
```

- [ ] **Step 3: Verify compose syntax**

Run: `docker compose config --quiet 2>&1; echo "exit: $?"`
Expected: `exit: 0` (or if docker compose isn't available, just verify the YAML is valid)

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: consolidate scheduler, concurrency-scheduler, ws-server into worker service"
```

---

### Task 5: Update documentation

**Files:**
- Modify: `docs/php-optimization.md`

**Context:** The ramdisk documentation references individual roles. Update to mention the consolidated `worker` service and multi-role support.

- [ ] **Step 1: Update role-based eligibility table**

In `docs/php-optimization.md`, find the role-based eligibility table (around line 62-69) and add a note after the table:

```markdown
**Multi-role mode:** When `ROLE` contains a comma-separated list (e.g., `ROLE=scheduler,concurrency-scheduler,ws-server`), all processes run under supervisord in a single container sharing one ramdisk. The default `docker-compose.yml` uses this mode via the `worker` service, reducing RAM usage by ~260MB compared to running three separate containers with individual ramdisks.
```

- [ ] **Step 2: Commit**

```bash
git add docs/php-optimization.md
git commit -m "docs: add multi-role ramdisk documentation"
```

---

### Task 6: Integration verification

**Context:** Verify all scripts have valid syntax, the compose file parses, and the pieces fit together.

- [ ] **Step 1: Verify all bash scripts**

Run:
```bash
bash -n internal/run.sh && \
bash -n internal/ramdisk-setup.sh && \
bash -n internal/generate-supervisor-configs.sh && \
echo "All scripts OK"
```
Expected: `All scripts OK`

- [ ] **Step 2: Verify compose file**

Run: `docker compose config --quiet 2>&1 || echo "docker compose not available, skipping"`
Expected: No errors or "docker compose not available"

- [ ] **Step 3: Verify generate-supervisor-configs.sh produces correct output**

Run:
```bash
bash internal/generate-supervisor-configs.sh scheduler concurrency-scheduler ws-server && \
ls /tmp/supervisor/*.conf
```
Expected: `cron.conf`, `concurrent-run.conf`, `log-rotator.conf`, `reverb.conf`

- [ ] **Step 4: Clean up test artifacts**

```bash
rm -f /tmp/supervisor/*.conf
rmdir /tmp/supervisor/logs /tmp/supervisor 2>/dev/null || true
```

- [ ] **Step 5: Commit (if any fixes were needed)**

Only if issues were found and fixed during verification.
