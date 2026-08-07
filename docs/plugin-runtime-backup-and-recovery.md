# Heavyweight Plugin Backup and Recovery

This runbook applies to managed heavyweight plugins, including the Cura Web UI
gateway. It deliberately separates WPrint's normal application backup from the
private Docker volume owned by a plugin.

## What is backed up

- G-code files explicitly imported into WPrint are covered by the existing
  WPrint file-storage backup policy.
- Plugin manifests, built-in inventory metadata, lifecycle logs, and database
  records are included when the normal WPrint application backup includes the
  corresponding application data.
- The sidecar's uploaded meshes, SQLite job database, previews, logs, and
  unimported G-code remain in its private named volume.

The current release does **not** automatically export plugin volumes as part of
the normal WPrint backup. A successful WPrint backup therefore does not imply
that queued sidecar jobs or sidecar-only projects can be restored.

## Collect diagnostics before recovery

Run the following as an administrator or from the backend container:

```bash
php artisan plugin:doctor
```

The administrator API equivalent is `GET /api/plugins/doctor`. Its runtime
diagnostics contain only the desired/resolved image, container health and spec
state, retained-volume presence, lifecycle timestamps, and bounded log data.
They do not expose Docker environment values, mountpoints, bridge tokens, or
host paths.

Save the plugin manifest, built-in inventory entry, doctor response, and
relevant lifecycle logs with the incident record. Do not copy model bytes or
secrets into a support bundle.

## Safe recovery sequence

1. Stop new work and disable the affected plugin from the administrator UI or
   with `php artisan plugin:disable <plugin-id>`.
2. Confirm that the managed container is gone and the named volume is still
   present. Disabling a plugin intentionally retains its volume.
3. Take a Docker-volume snapshot or host-level backup using the platform's
   approved backup tooling. Do not enter the plugin container and do not mount
   the host Docker socket into a helper.
4. If the database is corrupt, preserve the original volume and copy it aside
   before attempting any repair. Never auto-delete or reinitialize production
   data.
5. Restore the snapshot to the same WPrint-managed volume identity, or attach
   a disposable copy for inspection. Keep the original snapshot immutable.
6. Verify the installed package, image digest, and schema compatibility. A
   gateway image that refuses a newer SQLite schema must remain stopped until
   the matching release is available.
7. Re-enable the plugin and wait for the authenticated readiness healthcheck.
   Confirm that queued jobs recover once, terminal artifacts remain owner-scoped,
   and WPrint's imported files are still visible.

If the volume cannot be recovered, keep it retained for forensic review and
offer users a fresh retry. Do not silently fall back to a host path or delete
the volume as part of startup.

## Deliberate data deletion

Uninstall and disable retain managed volumes by default. Permanent deletion is
an administrator-only action and requires the exact deterministic volume name
reported by diagnostics:

```text
DELETE /api/plugins/{pluginId}/runtime-storage?expectedVolume=<managed-volume>
```

The host derives the allowed names from the signed manifest and rejects enabled
plugins, malformed names, and volumes that do not belong to that plugin. The UI
confirmation states that sliced projects and pending artifacts become
unrecoverable. Use this only after a verified backup or explicit data-loss
approval.

## Release and rollback notes

- Preserve the old gateway image digest and signed `.w3dp` while testing an
  update. Runtime rollback restores the prior package/database state and leaves
  the retained volume untouched.
- If a candidate fails its healthcheck, discard the candidate and keep the
  previously healthy container active.
- Re-run `plugin:doctor` after every update, rollback, restore, or volume
  operation. Record the command output with the release evidence.
- Volume export/import automation is intentionally a follow-up feature. When
  it is introduced, it must use a host-managed helper with an allowlisted
  volume identity and bounded archive size; arbitrary plugin shell access is
  not an acceptable backup mechanism.
