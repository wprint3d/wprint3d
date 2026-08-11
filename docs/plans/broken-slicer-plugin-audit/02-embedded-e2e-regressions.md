# Plan 02: Embedded E2E Regressions

**Repositories:** `cura-web-ui` and `wprint3d-core`.

**Objective:** Turn every audit discovery into durable browser coverage and distinguish standalone, host-embedded, sidecar, and physical-device claims.

## 1. Repair the stale 760 px material-picker test

### Files

- Modify `../cura-web-ui/apps/web/e2e/cura-flow.spec.ts`.

At widths below 768 px, desktop `.quick-settings` and `.extruder-cell` controls are intentionally hidden. The current test sets 760 × 560 but still clicks those desktop selectors.

Update the test to use accessible responsive controls:

- open settings through the visible button named `Open print settings`;
- open material selection through `.mobile-setup-material` or an equivalent accessible label;
- calculate popover anchoring relative to the visible mobile material control;
- select `Generic PETG` and verify the mobile summary changes;
- verify the settings panel closes and the popover stays within viewport bounds;
- verify no horizontal overflow.

Do not raise the viewport to 768 merely to make the old selector visible; the 760 px regression is valuable.

## 2. Add permanent WPrint authentication helpers

### Files

- Create `../cura-web-ui/apps/web/e2e/support/wprintHost.ts` or another clearly named helper.
- Modify `../cura-web-ui/apps/web/e2e/wprint-embedded-responsive.spec.ts` to reuse it.

The helper should:

- require `WPRINT3D_E2E_BASE_URL`;
- read credentials only from environment variables;
- acquire the XSRF cookie and submit login through WPrint's real endpoint;
- open the Slicer navigation entry using the manifest label fallback;
- tolerate one iframe remount during responsive navigation changes;
- return a `FrameLocator` only after `cura-viewport` is ready;
- never print credentials, cookies, or full authenticated iframe URLs.

Keep the existing environment-based skip for generic standalone CI where WPrint is not available.

## 3. Add the installed-plugin slicing specification

### File

- Create `../cura-web-ui/apps/web/e2e/wprint-embedded-slicing.spec.ts`.

### Required test flow

1. Log in to the real WPrint host.
2. Open the installed Slicer plugin.
3. Upload a generated watertight STL with a unique filename.
4. Observe `POST .../runtime/api/v1/uploads` return `201`.
5. Verify the object list replaces the bundled calibration cube with the uploaded filename.
6. Change at least one slicing parameter, such as infill percentage.
7. Click Slice and verify immediate busy/Preview state.
8. Wait for `job.completed` through the polling transport.
9. Verify `viewport-render-status` reports real toolpath segments.
10. Move the layer slider and verify cumulative visible layers.
11. Read G-code and assert `Generated with Cura_SteamEngine` plus `;LAYER:0`.
12. Assert the G-code does not contain mock or BasicFdm adapter markers.
13. Open Monitor and invoke `Save to WPrint 3D`.
14. Observe the runtime-artifact import response succeed.
15. Verify a WPrint-visible success message and, where practical, the imported file in WPrint's file library.

Use generated in-memory STL bytes. Do not depend on a developer's downloads folder or an absolute path.

### Failure diagnostics

On failure retain:

- Playwright trace;
- browser console errors;
- failed request URL path and status without cookies/query secrets;
- sidecar logs for the matching time window;
- host and iframe screenshots.

Clean traces after a successful local run. CI may retain them as failure artifacts under its normal retention policy.

## 4. Keep responsive coverage independent

The existing responsive test must continue to cover:

- 375 × 812;
- 812 × 375;
- 768 × 1024;
- 1024 × 768;
- 1440 × 900;
- light and dark themes;
- actual iframe bounds and Slice button bounds;
- WPrint mobile bottom navigation height;
- Preview and Slicer destinations remaining reachable.

Do not repeat real slicing ten times in the responsive matrix. Run one complete real embedded slice at desktop or tablet size, then use the focused responsive smoke matrix for layout and primary-interaction reachability.

## 5. Standalone coverage gate

From `cura-web-ui`:

```bash
corepack pnpm verify
corepack pnpm test:e2e
```

The suite must cover:

- STL, OBJ, 3MF, G-code, workspace JSON, and unsupported CAD messaging;
- select/move/scale/rotate/mirror/duplicate/delete/arrange/reset/undo/redo;
- per-object settings and support blockers;
- printer/material/profile catalogs and custom settings;
- real CuraEngine parameter propagation;
- Preview layers, simulation, X-Ray diagnostics, and cameras;
- post-processing, marketplace, backups, artifacts, and output-device API flows;
- phone, tablet, desktop, landscape, light, and dark layouts.

## 6. Installed-plugin gate

Run Playwright in the repository-pinned browser version. If the host cannot launch Chromium due to missing system libraries, use the matching official Playwright container and a temporary config that:

- trusts the local self-signed WPrint certificate only for the test;
- connects to WPrint through `host.docker.internal`;
- does not embed credentials in source or reports;
- does not start an unnecessary second WPrint stack;
- is removed after the run.

Required environment variables:

```text
WPRINT3D_E2E_BASE_URL
WPRINT3D_E2E_EMAIL
WPRINT3D_E2E_PASSWORD
```

### Gate 02

- Standalone suite passes without the 760 px timeout.
- Installed upload/slice/import test passes with a user-provided STL.
- Responsive host+iframe matrix passes in both themes.
- No browser trace containing authenticated cookies remains in the working tree.
- Physical-printer behavior is reported separately from gateway output-device behavior.
