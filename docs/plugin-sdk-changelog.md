# Plugin SDK Changelog

## Compatibility Policy

- `sdkVersion` changes only for breaking API-level shifts.
- `sdkRevision` changes for additive or contract-tightening changes inside the same API level.
- Older revisions may be marked `supported`, `deprecated`, or `retired`.
- New plugins should target the current SDK pair unless they intentionally need older compatibility.

## Current SDK

- `sdkVersion: 1`
- `sdkRevision: 1`

## Version 1

### Revision 1

Status:

- `current`

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
