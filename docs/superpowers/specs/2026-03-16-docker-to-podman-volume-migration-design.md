# Docker → Podman Volume Migration Design

**Date:** 2026-03-16
**Status:** Approved

## Overview

Add a migration layer to `run.sh` that copies data from previously Docker-based named volumes into their equivalent Podman volumes before bringing up services. If the target Podman volume already exists the migration for that volume is skipped entirely.

---

## Architecture & Scope

### New function

`migrate_docker_volumes_to_podman()` is added to `internal/container-runtime.sh` (keeping all runtime concerns co-located) and called from `run.sh` in both the dev and production code paths, immediately after `init_container_runtime` succeeds and before any `pull` / `build` / `up` commands.

### Guard conditions — the function is a no-op when

- `HOST_CONTAINER_RUNTIME` is not `podman`
- `docker` CLI is not available in `PATH`

### Volume map

| Source (Docker) | Target (Podman) | Environments |
|---|---|---|
| `wprint3d_mongo` | `wprint3d-core_mongo` | dev + production |
| `wprint3d_storage` | `wprint3d-core_storage` | production only |
| `wprint3d_proxy` | `wprint3d-core_proxy` | production only |
| `wprint3d_startup` | `wprint3d-core_startup` | production only |

The source prefix (`wprint3d`) defaults to `wprint3d` and is configurable via the `WPRINT3D_DOCKER_PROJECT_NAME` environment variable to accommodate users who previously ran Docker from a differently-named directory.

---

## Migration Logic (per volume)

For each volume in scope, the function executes the following steps in order:

1. **Check target Podman volume** — `run_host_container_cli volume inspect <target>`. If it exists → skip silently and continue to the next volume.
2. **Check source Docker volume** — `docker volume inspect <source>`. If it does not exist → skip silently (Docker was never used).
3. **Create target Podman volume** — `run_host_container_cli volume create <target>`.
4. **Pipe the data** via throwaway busybox containers:

```bash
docker run --rm -v <source>:/src busybox tar -cC /src . \
  | run_host_container_cli run --rm -i -v <target>:/dst busybox tar -xC /dst
```

5. **On pipe failure** — print an error to stderr, return non-zero. The `|| exit 1` guard in `run.sh` halts startup so the user is not silently running against an empty volume.
6. **On success** — print: `Migrated Docker volume '<source>' to Podman volume '<target>'.`

---

## Error Handling & Edge Cases

| Scenario | Behaviour |
|---|---|
| Target Podman volume already exists | Skip silently (volume existence = already migrated) |
| Source Docker volume does not exist | Skip silently (Docker was never used) |
| `docker` CLI not in PATH | Return immediately without error |
| `busybox` not cached | Docker/Podman pull it automatically; pipe failure halts startup |
| Partial migration (pipe interrupted) | Target volume exists on re-run → skipped; user can `podman volume rm <target>` to retry |
| Rootful Podman | All Podman operations route through `run_host_container_cli`, which already handles rootful mode via `run_podman_rootful_command` |
| Dev environment | Only `mongo` volume is in scope; others are silently skipped |

---

## Call Sites in `run.sh`

**Production path** (after `init_container_runtime`):

```bash
init_container_runtime || exit 1;
migrate_docker_volumes_to_podman 'production' || exit 1;
run_host_compose pull || exit 1;
```

**Dev path** (after `init_container_runtime`):

```bash
init_container_runtime || exit 1;
offer_frontend_node_modules_reownership || exit 1;
ensure_podman_development_ports_supported || exit 1;
migrate_docker_volumes_to_podman 'dev' || exit 1;
run_host_compose -f docker-compose-development.yml pull || exit 1;
```

---

## Implementation Files

| File | Change |
|---|---|
| `internal/container-runtime.sh` | Add `migrate_docker_volumes_to_podman()` function |
| `run.sh` | Call `migrate_docker_volumes_to_podman` in both dev and production paths |
