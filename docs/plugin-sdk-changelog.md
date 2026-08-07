# Plugin SDK Changelog

## Compatibility Policy

- `sdkVersion` changes only for breaking API-level shifts.
- `sdkRevision` changes for additive or contract-tightening changes inside the same API level.
- Older revisions may be marked `supported`, `deprecated`, or `retired`.
- New plugins should target the current SDK pair unless they intentionally need older compatibility.

## Current SDK

- `sdkVersion: 1`
- `sdkRevision: 5`

## Version 1

### Revision 5

Status:

- `current`

Released:

- `2026-08-06`

Summary:

- Managed heavyweight runtimes, authenticated host proxying, durable artifact import, and release-grade package integrity.

Changes:

- Added host-managed bridge authentication, same-origin runtime proxying, and server-to-server artifact imports.
- Restricted managed service storage to private named volumes below `/data`; host paths, Docker sockets, USB devices, and host-selected Docker networks are not accepted.
- Required immutable `@sha256` image references for managed dependencies and enforced resource/security settings through the host runner.
- Added bounded stop grace periods and numeric runtime users for managed services; image healthchecks now run with an explicit entrypoint in an isolated read-only, network-disabled check container.
- Added bounded declarative `/tmp` tmpfs sizing for revision-5 managed services, with a safe fallback when omitted.
- Added fail-closed host maximums for image pull timeouts, service resources, PID counts, and capability drops; declarative `cpuCores`/`pids` aliases normalize to the Docker runner fields.
- Added compact managed-storage normalization with explicit retention metadata and a one-writable-volume invariant.
- Added declarative HTTP proxy and artifact-import normalization, including method/path/size policy checks and workspace presentation validation.
- Added built-in heavyweight package inventory metadata, aggregate asset integrity, compatibility records, and protected release signing handoff.

Migration notes:

- New heavyweight plugins should target `sdkRevision: 5` and use digest-pinned image references.
- Remove any `service.network` field from revision-5 manifests; WPrint selects the plugin-capable network.
- Revisions 0–4 remain supported for existing packages, but they do not receive revision-5 guarantees until migrated.

### Revision 4

Status:

- `current`

Released:

- `2026-03-14`

Summary:

- Expanded the declarative host component registry and added plugin-scoped UI isolation.

Changes:

- Added stable `host.*` aliases for the supported declarative host primitives.
- Expanded the host renderer with rows, stacks, surfaces, headings, captions, chip groups, badges, inputs, switches, progress bars, scroll containers, and spacers.
- Added plugin-scoped error boundaries so broken declarative plugin UI degrades locally instead of crashing the full app.

Migration notes:

- New plugins should target `sdkRevision: 4`.
- Existing manifests using short ids like `section`, `text`, `button`, and `remote_component` continue to work.
- Prefer the namespaced `host.*` ids in new examples and docs.

### Revision 3

Status:

- `supported`

Released:

- `2026-03-14`

Summary:

- OctoPrint compatibility primitives and browser-side host bridge support.

Changes:

- Added `plugin.json -> settings.defaults` as a first-class persisted settings seed.
- Added authenticated plugin settings and state endpoints.
- Added `send_plugin_message` / `publish_state` state persistence support.
- Added the browser compatibility helper at `/api/plugins/sdk/octoprint-compat.js`.
- Added `WPRINT3D_BOOTSTRAP_APP` to the PHP runtime environment for ports that need host models and config.
- Added the host-rendered `data_strip` widget for native OctoPrint-style navbar ports.
- Added the `OctoPrint NavbarTemp Port` example plugin as the reference migration case.

Migration notes:

- New plugins should target `sdkRevision: 3`.
- Plugins already using revision 2 keep working unchanged.
- OctoPrint ports should prefer the new settings/state APIs instead of inventing custom AJAX glue.

### Revision 2

Status:

- `supported`

Released:

- `2026-03-13`

Summary:

- Heavyweight plugin runtime dependencies and interactive scaffolding.

Changes:

- Added manifest-declared `images` with optional `healthcheck` and `service` metadata.
- Added advisory `requirements.memoryMb` and `requirements.cpuCores`.
- Added host-managed bridge sidecar activation through `runtime.managedImageId`.
- Added heavyweight/lightweight dependency metadata in plugin inventory and marketplace payloads.
- Added interactive `plugin:make` scaffolding for runtime/UI shape combinations.

Migration notes:

- New plugins should target `sdkRevision: 2`.
- Bridge plugins can keep using static `runtime.baseUrl`, but self-contained image-backed bridges should prefer `runtime.managedImageId`.

### Revision 1

Status:

- `supported`

Released:

- `2026-03-12`

Summary:

- Asset-backed elevated UI revisions and settings-tab parity release.

Changes:

- Added revisioned SDK metadata with `sdkVersion` plus `sdkRevision`.
- Added authenticated host-served plugin assets for WebView and custom bundle surfaces.
- Standardized dedicated plugin settings tabs and plugin-card `Settings` entry points.
- Added first-party Host Metrics examples for every supported runtime/UI shape combination.

Migration notes:

- WebView and custom-bundle plugins should declare entry assets in `assets` and use `asset://...`.
- New scaffolds should emit `sdkRevision`.

### Revision 0

Status:

- `supported`

Released:

- `2026-03-11`

Summary:

- Initial public plugin SDK release.

Changes:

- Introduced PHP and bridge runtimes.
- Introduced declarative UI surfaces, WebView mode, and custom bundle mode.
- Introduced plugin management, registry integration, and development-mount installs.

Migration notes:

- Revision 0 plugins continue to install, but new samples and docs now target revision 1.

## Planned Deprecation Flow

When a revision is deprecated:

1. The docs will mark the revision as `deprecated`.
2. The changelog will include the replacement revision and migration notes.
3. The scaffold will move to the newer revision.
4. Examples will be updated first.
5. Registry review rules can warn on new submissions targeting older revisions.
