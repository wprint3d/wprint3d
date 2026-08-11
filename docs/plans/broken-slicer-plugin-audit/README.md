# Broken Slicer Plugin Audit Remediation

**Status:** Implemented and locally accepted on 2026-08-07; production signing and physical-printer acceptance remain separate release gates.

**Observed:** 2026-08-07 against the local WPrint development stack, the installed `cura-web-ui` 0.1.0 plugin, and CuraEngine 5.12.1.

**Goal:** Restore the complete user-model workflow inside the WPrint-hosted slicer without weakening runtime isolation, upload limits, authentication, or artifact handling.

## Executive finding

The standalone slicer and real CuraEngine workflow were healthy at audit start. The installed WPrint plugin could slice the bundled calibration model, render real layers, read generated G-code, and import that G-code into WPrint, but it could not import a new user model through the host runtime proxy.

The failing request is a `multipart/form-data` upload to:

```text
/backend/api/plugins/cura-web-ui/runtime/api/v1/uploads
```

A 1.7 KB STL waits for exactly 60 seconds and returns `502`. The managed Cura sidecar receives no upload request. The failure is therefore between nginx, PHP-FPM/Laravel, and the outbound runtime proxy.

The immediate defect is in `PluginRuntimeProxyController::boundedRequestBody()`: its `PumpStream` callback returns an empty string at EOF. Guzzle's `PumpStream` contract requires `false` or `null` at EOF. Returning an empty string leaves the pump loop with the same remaining byte count forever.

Correcting only that return value was insufficient for production. PHP may parse multipart requests into temporary `UploadedFile` objects before controller execution, and raw `php://input` behavior differs across SAPIs/configurations. The implemented fix reconstructs a standards-compliant multipart stream from parsed form fields and temporary file streams, applies a fresh boundary, and enforces the signed/host byte limits on the reconstructed stream.

Installed-package acceptance then exposed three additional host defects. The runtime limiter classified every JSON `POST` as an upload/job operation, so ordinary `settings/resolve` calls exhausted the 10-per-minute heavy-operation bucket. A first correction still classified `GET /jobs/{id}/layers/{layer}` as heavy by path alone. The final classifier uses both method and path: multipart and mutations of `/uploads` or `/jobs` use the heavy bucket, while catalog, resolve, polling, and layer reads use the metadata bucket. Reinstallation also reused a running container whose configuration fingerprint omitted the bridge credential, producing `401` after token rotation. The managed-service fingerprint now includes a non-reversible hash of the encrypted token, failed candidates are discarded from persisted dependency state, and bridge readiness allows 60 seconds for retained slicer data to load.

## Scope

This program covers:

- WPrint runtime-proxy multipart forwarding;
- filename, MIME type, repeated/nested field, and non-file form-field preservation;
- bounded-memory behavior and upload-size enforcement;
- error mapping and timeout diagnostics;
- runtime rate-limit classification for resolve, job creation, polling, and layer reads;
- bridge-token-aware managed-container replacement, readiness, and failed-candidate cleanup;
- the stale 760 px material-picker E2E selector;
- a permanent embedded upload → slice → preview → G-code → WPrint-import regression;
- repackaging and reinstalling the local `.w3dp` candidate;
- standalone and embedded responsive verification.

It does not redesign the slicer UI, change CuraEngine internals, add WebSocket proxying, or claim physical-printer validation.

## Repository ownership

| Concern | Repository | Primary files |
| --- | --- | --- |
| Authenticated runtime proxy | `wprint3d-core` | `app/Http/Controllers/PluginRuntimeProxyController.php`, plugin feature tests |
| Embedded upload behavior | `cura-web-ui` | `apps/web/src/App.tsx`, `apps/web/e2e/` |
| Durable upload endpoint | `cura-web-ui` | `services/slicer-gateway/app/routers/uploads.py` |
| Package/release assembly | `cura-web-ui` plus WPrint packager | `packages/wprint3d-plugin`, packaging scripts |

## Plan order

Execute these documents in order:

1. [`01-runtime-proxy-multipart-remediation.md`](01-runtime-proxy-multipart-remediation.md)
2. [`02-embedded-e2e-regressions.md`](02-embedded-e2e-regressions.md)
3. [`03-package-install-and-release-acceptance.md`](03-package-install-and-release-acceptance.md)
4. [`05-runtime-rate-limit-and-lifecycle-remediation.md`](05-runtime-rate-limit-and-lifecycle-remediation.md)
5. [`04-implementation-tracker.md`](04-implementation-tracker.md)

Do not reinstall the plugin until the focused WPrint proxy tests pass. Do not call the repair complete until an uploaded user STL—not the bundled cube—finishes a real slice through the installed plugin.

## Required invariants

1. The browser continues to use the same-origin WPrint runtime path.
2. The browser never receives the sidecar URL or bearer token.
3. The proxy forwards only signed allowed methods and path prefixes.
4. Upload limits are enforced with and without `Content-Length`.
5. File contents are streamed from temporary storage; no full-file PHP string is introduced.
6. Filenames and MIME types are treated as untrusted metadata and never become host paths.
7. Multipart boundaries are generated by the outbound stream and match its `Content-Type` header.
8. JSON and binary-response proxy behavior remains unchanged.
9. Artifact import remains server-to-server and atomic.
10. WPrint remains usable if the slicer sidecar or upload request fails.
11. Read-only job polling and layer retrieval cannot consume the heavy mutation bucket.
12. A bridge token rotation changes the managed-container fingerprint without exposing the token.
13. Failed candidates are removed while the retained runtime volume remains intact.

## Global definition of done

- A browser-authenticated user opens the installed Cura plugin in WPrint.
- A new STL is selected through the hidden file input and appears in the object list.
- The sidecar returns `201` from `/api/v1/uploads` and persists the uploaded bytes.
- The subsequent job references the returned `uploadId` and completes with real CuraEngine.
- Preview reports real toolpath segments and G-code contains CuraEngine markers.
- `Save to WPrint 3D` imports the generated artifact successfully.
- The 375 × 812, 812 × 375, 768 × 1024, 1024 × 768, and 1440 × 900 embedded matrix passes in light and dark themes.
- The standalone E2E suite has no stale responsive-selector failure.
- Focused PHP tests, Pint, Cura verification, package verification, and `git diff --check` pass.
- Temporary test packages, credentials, browser traces, and test users are removed.
