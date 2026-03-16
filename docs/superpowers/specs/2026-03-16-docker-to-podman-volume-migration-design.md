# Docker → Podman Volume Migration Design

**Date:** 2026-03-16
**Status:** Approved

## Overview

Add a migration layer to `run.sh` that copies data from previously Docker-based named volumes into their equivalent Podman volumes before bringing up services. If the target Podman volume already exists the migration for that volume is skipped entirely.

---

## Architecture & Scope

### New function

`migrate_docker_volumes_to_podman()` is added to `internal/container-runtime.sh` (keeping all runtime concerns co-located) and called from `run.sh` in both the dev and production code paths. In the production path it is called immediately after `init_container_runtime`. In the dev path it is called after `ensure_podman_development_ports_supported` (so that a port-check failure aborts before spending time on migration). It receives `$ENV` as its sole argument to filter the volume scope.

`$ENV` is a variable set earlier in `run.sh` to either `dev` or `production` based on the `-e` / `--environment` flag (defaulting to `production`). It is guaranteed to be set before either call site is reached.

`SCRIPT_PATH` — defined at the top of `run.sh` as the absolute directory of the script — is a global variable available to the function via the sourcing context.

### Guard conditions — evaluated in this order as the very first logic in the function body

The following checks must run before any call to `run_host_container_cli`. The function returns 0 immediately on the first matching condition:

1. `HOST_CONTAINER_RUNTIME` is not `podman`
2. `docker` CLI is not available in `PATH`
3. `timeout 5 docker info > /dev/null 2>&1` fails (Docker daemon not running, socket dead, or unresponsive after 5 seconds)

### Volume map

| Source (Docker) | Target (Podman) | Environments |
| --- | --- | --- |
| `<src_prefix>_mongo` | `<dst_prefix>_mongo` | dev + production |
| `<src_prefix>_storage` | `<dst_prefix>_storage` | production only |
| `<src_prefix>_proxy` | `<dst_prefix>_proxy` | production only |
| `<src_prefix>_startup` | `<dst_prefix>_startup` | production only |

**Source prefix (`<src_prefix>`):** Resolved inside the function as `${WPRINT3D_DOCKER_PROJECT_NAME:-wprint3d}`. Defaults to `wprint3d` — historically the project was deployed from a directory named `wprint3d`, producing volumes like `wprint3d_mongo`.

**Target prefix (`<dst_prefix>`):** Resolved inside the function in this priority order:
1. `$COMPOSE_PROJECT_NAME` if set in the environment (matches what Podman Compose will use)
2. `basename "$SCRIPT_PATH"` otherwise (resolves to `wprint3d-core` for this repository)

**Collision guard:** If the two prefixes resolve to the same value, the function prints the following warning to stderr (interpolating the actual resolved prefix) and returns 0:

```
Warning: Docker and Podman volume prefixes are identical ('<resolved_prefix>').
Volume migration skipped to avoid self-copy.
```

**`$ENV` argument validation:** The function accepts `dev` or `production`. Any other value is treated as `production` (all four volumes in scope) and a warning is printed to stderr:

```
Warning: Unknown environment '<value>' passed to migrate_docker_volumes_to_podman. Treating as 'production'.
```

**Concurrency:** Concurrent invocations of `run.sh` during migration are not protected by a lock. If two instances race past step 1 simultaneously, both will pipe data into the same target volume, producing a corrupt result. This is an accepted known limitation — the script is not designed for concurrent execution.

---

## Migration Logic (per volume)

For each volume in scope, the function executes the following steps directly in the function body (not inside a subshell or command substitution), so that `PIPESTATUS` is accessible after the pipe:

1. **Check target Podman volume** — `run_host_container_cli volume inspect <target> > /dev/null 2>&1`. If it exits 0 (volume exists) → skip silently and continue to the next volume.
2. **Check source Docker volume** — `docker volume inspect <source> > /dev/null 2>&1`. If it exits non-zero (volume does not exist) → skip silently and continue.
3. **Create target Podman volume** — `run_host_container_cli volume create <target> > /dev/null` (stdout suppressed with `> /dev/null` only — `2>&1` is intentionally absent so stderr is surfaced; the engine's own error message will appear before the spec's error message). If this exits non-zero → print the following to stderr and return 1:

```
ERROR: Failed to create Podman volume '<target>'.
```

4. **Pipe the data** via throwaway containers. The image is pinned to `docker.io/library/busybox:1.36` (a stable version tag) rather than `latest` to ensure both sides of the pipe use the same `tar` implementation. The pipeline runs directly in the function body:

```bash
docker run --rm -v <source>:/src docker.io/library/busybox:1.36 tar -cC /src . \
  | run_host_container_cli run --rm -i -v <target>:/dst docker.io/library/busybox:1.36 tar -xC /dst
pipe_status=("${PIPESTATUS[@]}")
```

Both sides are checked via `PIPESTATUS` (not `set -o pipefail`). If `pipe_status[0]` or `pipe_status[1]` is non-zero, step 5 triggers.

**`PIPESTATUS[1]` reliability:** `run_host_container_cli` is a shell function. Its exact body (from `internal/container-runtime.sh`) is:

```bash
run_host_container_cli() {
    if [[ "${HOST_CONTAINER_RUNTIME:-}" == 'podman' ]] && [[ "${HOST_PODMAN_ROOTFUL:-0}" == '1' ]]; then
        run_podman_rootful_command "$@";
        return $?;
    fi;
    "${HOST_CONTAINER_RUNTIME}" "$@";
}
```

The non-rootful path ends with `"${HOST_CONTAINER_RUNTIME}" "$@"` as its last statement. The rootful path ends with `return $?` immediately after `run_podman_rootful_command`, which itself ends with the podman CLI call as its last statement. In both paths the underlying CLI call is the last executed statement, so the function's exit code equals the container engine's exit code. `PIPESTATUS[1]` therefore correctly captures the Podman-side result. This guarantee requires that neither function gains any trailing statements after the runtime invocation.

5. **On pipe failure** — attempt to remove the target volume via `run_host_container_cli volume rm <target> > /dev/null 2>&1`. Two sub-cases:

   **If `volume rm` succeeds:** the target volume is gone; the next run will retry from step 2. Print to stderr:

   ```
   ERROR: Migration of Docker volume '<source>' to Podman volume '<target>' failed.
   The incomplete target volume has been removed. Re-run the script to retry.
   ```

   **If `volume rm` fails:** the corrupt volume still exists. Because step 1 skips on volume existence alone, re-running will silently skip this volume and bring up services against corrupt data. The error message must make this unambiguous:

   ```
   ERROR: Migration of Docker volume '<source>' to Podman volume '<target>' failed,
   and the incomplete target volume could not be removed automatically.
   You must remove it manually before re-running:
     podman volume rm <target>
   ```

   In both sub-cases the function returns 1, and the `|| exit 1` guard in `run.sh` halts startup.

6. **On success** — print: `Migrated Docker volume '<source>' to Podman volume '<target>'.`

### Return value contract

The function returns non-zero (1) if and only if:
- `volume create` fails (step 3), or
- either side of the tar pipe fails (step 4)

It returns 0 in all guard/skip cases (runtime not podman, docker CLI absent, daemon not running, volume already exists, source volume absent, collision guard, unknown ENV).

---

## Error Handling & Edge Cases

| Scenario | Behaviour |
| --- | --- |
| Target Podman volume already exists | Skip silently (`volume inspect` stdout+stderr suppressed) |
| Source Docker volume does not exist | Skip silently (`volume inspect` stdout+stderr suppressed) |
| `docker` CLI not in PATH | Return 0 immediately |
| Docker daemon not running or unresponsive | Return 0 immediately (`timeout 5 docker info` guard) |
| Source and target prefixes are identical | Print collision warning (with resolved prefix) to stderr, return 0 |
| Unknown `$ENV` value | Print warning, migrate all four volumes |
| `volume create` fails | Surface engine stderr + print spec error, return 1 |
| Pipe fails (either side) | Attempt `volume rm <target>`, print recovery message, return 1 |
| `volume rm` cleanup fails after pipe failure | Print error message explicitly instructing manual volume removal. Return 1. Step 1 will silently skip the corrupt volume on re-run — manual removal is the only recovery path. |
| `busybox` pull fails | Pipe exits non-zero; cleanup and halt triggered as for any pipe failure |
| Partial migration, `volume rm` succeeded | Target volume removed; next run retries from scratch |
| Partial migration, `volume rm` failed | Corrupt volume persists; error message explicitly tells user to remove it manually before retrying |
| Rootful Podman | Podman side routes through `run_host_container_cli` → `run_podman_rootful_command`. Docker side runs as current user. File ownership (UID/GID) matches the source Docker volume's numeric IDs — acceptable because all service containers set their own user context at runtime |
| Dev environment | Only `mongo` volume is in scope; others silently skipped |
| `COMPOSE_PROJECT_NAME` is set | Used as `<dst_prefix>` to match what Podman Compose will actually name the volumes |
| Concurrent `run.sh` invocations | Not protected; accepted known limitation |
| Mid-pipe interruption (SIGKILL, OOM) | Target volume exists but is partially written. Step 1 will silently skip it on re-run. Covered by the same known limitation as `volume rm` failure — user must manually remove the volume before retrying. |
| `busybox` image retained | `--rm` removes containers but not image layers; accepted operational trade-off |

---

## Call Sites in `run.sh`

**Production path:**

```bash
init_container_runtime || exit 1;
migrate_docker_volumes_to_podman "$ENV" || exit 1;
run_host_compose pull || exit 1;
```

**Dev path** (`migrate_docker_volumes_to_podman` placed after port check so a port failure aborts before migration work begins):

```bash
init_container_runtime || exit 1;
offer_frontend_node_modules_reownership || exit 1;
ensure_podman_development_ports_supported || exit 1;
migrate_docker_volumes_to_podman "$ENV" || exit 1;
run_host_compose -f docker-compose-development.yml pull || exit 1;
```

---

## Implementation Files

| File | Change |
| --- | --- |
| `internal/container-runtime.sh` | Add `migrate_docker_volumes_to_podman()` function |
| `run.sh` | Call `migrate_docker_volumes_to_podman "$ENV"` in both dev and production paths |
