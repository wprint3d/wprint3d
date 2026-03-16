# Docker → Podman Volume Migration Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `migrate_docker_volumes_to_podman()` function that automatically copies Docker named volumes into Podman volumes when switching container runtimes, running before services start up.

**Architecture:** A single new bash function is added to `internal/container-runtime.sh` (alongside all other runtime helpers). Two call sites are added to `run.sh` — one in the production path (after `init_container_runtime`) and one in the dev path (after `ensure_podman_development_ports_supported`). The function is a no-op on Docker and when Docker volumes don't exist.

**Tech Stack:** Bash, Docker CLI, Podman CLI, `docker.io/library/busybox:1.36` (tar pipe for data transfer)

**Spec:** `docs/superpowers/specs/2026-03-16-docker-to-podman-volume-migration-design.md`

---

## Chunk 1: Add `migrate_docker_volumes_to_podman` to `internal/container-runtime.sh`

**Files:**
- Modify: `internal/container-runtime.sh` (append new function after the last function in the file — currently `run_podman_rootful_command` at line ~574)

### Task 1: Write the function

The function must be appended after the last existing function in `internal/container-runtime.sh`. The last top-level function is `run_podman_rootful_command`.

- [ ] **Step 1: Append `migrate_docker_volumes_to_podman` to `internal/container-runtime.sh`**

Add the following after the closing `}` of `run_podman_rootful_command`:

```bash
migrate_docker_volumes_to_podman() {
    local env="${1:-production}";

    # Guard 1: only run when Podman is the active runtime.
    if [[ "${HOST_CONTAINER_RUNTIME:-}" != 'podman' ]]; then
        return 0;
    fi;

    # Guard 2: Docker CLI must be available.
    if ! command -v docker > /dev/null 2>&1; then
        return 0;
    fi;

    # Guard 3: Docker daemon must be reachable (5-second timeout).
    if ! timeout 5 docker info > /dev/null 2>&1; then
        return 0;
    fi;

    # Resolve volume name prefixes.
    local src_prefix="${WPRINT3D_DOCKER_PROJECT_NAME:-wprint3d}";
    local dst_prefix="${COMPOSE_PROJECT_NAME:-$(basename "$SCRIPT_PATH")}";

    # Collision guard: refuse to self-copy.
    if [[ "$src_prefix" == "$dst_prefix" ]]; then
        echo "Warning: Docker and Podman volume prefixes are identical ('${src_prefix}')." >&2;
        echo 'Volume migration skipped to avoid self-copy.' >&2;

        return 0;
    fi;

    # Validate / normalise $env argument.
    case "$env" in
        dev|production)
            ;;
        *)
            echo "Warning: Unknown environment '${env}' passed to migrate_docker_volumes_to_podman. Treating as 'production'." >&2;
            env='production';
            ;;
    esac;

    # Build the list of volume base names to migrate.
    local volumes=('mongo');

    if [[ "$env" == 'production' ]]; then
        volumes+=('storage' 'proxy' 'startup');
    fi;

    local vol src dst;

    for vol in "${volumes[@]}"; do
        src="${src_prefix}_${vol}";
        dst="${dst_prefix}_${vol}";

        # Skip if target Podman volume already exists.
        if run_host_container_cli volume inspect "$dst" > /dev/null 2>&1; then
            continue;
        fi;

        # Skip if source Docker volume does not exist.
        if ! docker volume inspect "$src" > /dev/null 2>&1; then
            continue;
        fi;

        # Create the target Podman volume (stdout suppressed; stderr intentionally left open).
        if ! run_host_container_cli volume create "$dst" > /dev/null; then
            echo "ERROR: Failed to create Podman volume '${dst}'." >&2;

            return 1;
        fi;

        # Pipe data via throwaway busybox containers.
        # PIPESTATUS[1] is reliable here because run_host_container_cli ends
        # with the underlying CLI call as its last executed statement.
        docker run --rm \
            -v "${src}:/src" \
            docker.io/library/busybox:1.36 \
            tar -cC /src . \
          | run_host_container_cli run --rm -i \
            -v "${dst}:/dst" \
            docker.io/library/busybox:1.36 \
            tar -xC /dst;

        local pipe_status=("${PIPESTATUS[@]}");

        if [[ "${pipe_status[0]}" -ne 0 ]] || [[ "${pipe_status[1]}" -ne 0 ]]; then
            # Attempt cleanup of the partially-written target volume.
            if run_host_container_cli volume rm "$dst" > /dev/null 2>&1; then
                echo "ERROR: Migration of Docker volume '${src}' to Podman volume '${dst}' failed." >&2;
                echo 'The incomplete target volume has been removed. Re-run the script to retry.' >&2;
            else
                echo "ERROR: Migration of Docker volume '${src}' to Podman volume '${dst}' failed," >&2;
                echo 'and the incomplete target volume could not be removed automatically.' >&2;
                echo 'You must remove it manually before re-running:' >&2;
                echo "  podman volume rm ${dst}" >&2;
            fi;

            return 1;
        fi;

        echo "Migrated Docker volume '${src}' to Podman volume '${dst}'.";
    done;
}
```

- [ ] **Step 2: Verify the function was added correctly**

Run:
```bash
grep -n 'migrate_docker_volumes_to_podman' internal/container-runtime.sh
```
Expected output: two lines — the function definition and nothing else unexpected.

- [ ] **Step 3: Syntax-check the file**

Run:
```bash
bash -n internal/container-runtime.sh && echo 'OK'
```
Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add internal/container-runtime.sh
git commit -m "feat(migration): add migrate_docker_volumes_to_podman helper"
```

---

## Chunk 2: Wire call sites into `run.sh`

**Files:**
- Modify: `run.sh`

The current structure of the relevant sections in `run.sh` is:

**Production path (around line 234):**
```bash
elif [[ "$ENV" == 'production' ]]; then
    init_container_runtime || exit 1;

    run_host_compose pull || exit 1;
fi;
```

**Dev path (around line 222):**
```bash
    init_container_runtime || exit 1;
    offer_frontend_node_modules_reownership || exit 1;
    ensure_podman_development_ports_supported || exit 1;
    run_host_compose -f docker-compose-development.yml pull || exit 1;
```

### Task 2: Add call sites

- [ ] **Step 1: Insert migration call in the production path**

Find the block:
```bash
elif [[ "$ENV" == 'production' ]]; then
    init_container_runtime || exit 1;

    run_host_compose pull || exit 1;
```

Replace with:
```bash
elif [[ "$ENV" == 'production' ]]; then
    init_container_runtime || exit 1;
    migrate_docker_volumes_to_podman "$ENV" || exit 1;

    run_host_compose pull || exit 1;
```

- [ ] **Step 2: Insert migration call in the dev path**

Find the block:
```bash
    init_container_runtime || exit 1;
    offer_frontend_node_modules_reownership || exit 1;
    ensure_podman_development_ports_supported || exit 1;
    run_host_compose -f docker-compose-development.yml pull || exit 1;
```

Replace with:
```bash
    init_container_runtime || exit 1;
    offer_frontend_node_modules_reownership || exit 1;
    ensure_podman_development_ports_supported || exit 1;
    migrate_docker_volumes_to_podman "$ENV" || exit 1;
    run_host_compose -f docker-compose-development.yml pull || exit 1;
```

- [ ] **Step 3: Syntax-check `run.sh`**

Run:
```bash
bash -n run.sh && echo 'OK'
```
Expected: `OK`

- [ ] **Step 4: Verify both call sites are present**

Run:
```bash
grep -n 'migrate_docker_volumes_to_podman' run.sh
```
Expected: exactly two lines, one in each environment branch.

- [ ] **Step 5: Commit**

```bash
git add run.sh
git commit -m "feat(migration): wire Docker-to-Podman volume migration into run.sh"
```

---

## Chunk 3: Smoke-test the guard conditions

No automated test harness exists for these shell scripts. Verify the most important guard paths manually.

- [ ] **Step 1: Verify no-op on Docker runtime**

Source the file and simulate a Docker runtime:

```bash
bash -c '
  source internal/container-runtime.sh
  HOST_CONTAINER_RUNTIME=docker
  SCRIPT_PATH="$(pwd)"
  migrate_docker_volumes_to_podman production
  echo "exit: $?"
'
```
Expected: `exit: 0` with no output.

- [ ] **Step 2: Verify no-op when Docker CLI is absent**

```bash
bash -c '
  source internal/container-runtime.sh
  HOST_CONTAINER_RUNTIME=podman
  SCRIPT_PATH="$(pwd)"
  PATH=/nonexistent
  migrate_docker_volumes_to_podman production
  echo "exit: $?"
'
```
Expected: `exit: 0` with no output.

- [ ] **Step 3: Verify collision guard**

```bash
bash -c '
  source internal/container-runtime.sh
  HOST_CONTAINER_RUNTIME=podman
  SCRIPT_PATH="$(pwd)"
  WPRINT3D_DOCKER_PROJECT_NAME=wprint3d-core
  migrate_docker_volumes_to_podman production
  echo "exit: $?"
' 2>&1
```
Expected: warning about identical prefixes printed to stderr, `exit: 0`.

- [ ] **Step 4: Verify unknown ENV warning**

```bash
bash -c '
  source internal/container-runtime.sh
  HOST_CONTAINER_RUNTIME=podman
  WPRINT3D_DOCKER_PROJECT_NAME=nonexistent-src
  SCRIPT_PATH="$(pwd)"
  migrate_docker_volumes_to_podman staging
  echo "exit: $?"
' 2>&1
```
Expected: warning about unknown environment, `exit: 0` (no Docker volumes named `nonexistent-src_*` exist so all volumes skip silently after the warning).

- [ ] **Step 5: Commit smoke-test results note (if all pass)**

```bash
git commit --allow-empty -m "test(migration): manual smoke tests passed for guard conditions"
```
