# Plan 03: Package, Install, and Release Acceptance

**Repositories:** `cura-web-ui` and `wprint3d-core`.

**Objective:** Prove the fixed code through the package boundary users actually install, not only through source development servers.

## 1. Pre-package checks

In `cura-web-ui`:

```bash
corepack pnpm verify
corepack pnpm package:wprint3d
```

Verify the staged plugin contains:

- the current compiled browser UI;
- `plugin.json` with SDK revision 5;
- the `cube-scan` navigation icon and compact label;
- runtime proxy `POST` permission and `/api/v1` path;
- `maxUploadMb` consistent with WPrint's host maximum;
- immutable sidecar image reference/digest used by the local candidate;
- notices and integrity metadata required by the package verifier.

Do not change the OCI image merely for a host-side proxy fix unless the Cura UI or gateway also changed.

## 2. Build the local candidate safely

Use the official WPrint packager. Stage the package source into an exact temporary directory under the WPrint checkout only when required by container path visibility.

Recommended sequence:

1. Create a uniquely named temporary staging directory.
2. Copy the generated plugin tree into it without modifying the source tree.
3. Run `./plugin.sh pack <staged-plugin-path>`.
4. Run `./plugin.sh verify <candidate.w3dp>`.
5. Record package SHA-256, manifest version, UI integrity value, and image digest.
6. Remove only the exact temporary staging directory after installation evidence is captured.

Unsigned local sideload status is acceptable only for this development run. Do not describe it as production release evidence.

## 3. Reinstall without losing sidecar data

Before installation, record:

- installed plugin version;
- enabled state;
- current sidecar container ID/image digest;
- named volume identity;
- plugin runtime health.

Install the candidate through the normal plugin installer and enable it. If replacement triggers a sidecar restart, verify:

- the old container is stopped/removed;
- exactly one candidate container remains;
- the named persistent volume is reused;
- health reaches ready;
- the runtime bearer token remains host-only;
- WPrint remains responsive throughout failure/retry handling.

Do not delete retained runtime storage as part of this repair.

## 4. End-to-end acceptance flow

Run this exact sequence against the installed candidate:

1. Authenticate to WPrint in a clean browser context.
2. Open Slicer.
3. Upload a unique STL through the embedded UI.
4. Confirm sidecar upload `201` and capture `uploadId` indirectly through the accepted job payload/log evidence.
5. Slice with real CuraEngine.
6. Inspect Preview and at least one non-initial layer.
7. Read authentic G-code.
8. Save G-code into WPrint.
9. Verify the imported file is readable through WPrint's normal file API/UI.
10. Disable and re-enable the plugin.
11. Reopen Slicer and verify the runtime recovers.

Use a clearly test-prefixed model and G-code filename. Remove test files only after recording exact targets and confirming they are not user data.

## 5. Negative acceptance cases

Exercise at least:

- unsupported STEP/IGES browser message;
- oversized upload rejected with `413` before sidecar persistence;
- invalid multipart request produces finite `4xx`, never a 60-second `502`;
- disabled plugin rejects runtime access;
- sidecar unavailable produces an actionable error while WPrint remains usable;
- repeated Slice click is disabled while a job is active;
- browser cancellation does not corrupt a previously accepted job;
- artifact import rejects a non-G-code path/content type.

## 6. Observability evidence

Collect without secrets:

- WPrint runtime-proxy status and duration;
- sidecar access line for upload and job creation;
- job ID/status and CuraEngine completion marker;
- package checksum and installed manifest version;
- responsive screenshots from the existing matrix;
- focused test command summaries.

Do not archive browser cookies, XSRF values, runtime bearer tokens, Docker environment dumps, or full request headers.

## 7. Release decision

### Ready for local acceptance

- Focused proxy tests green.
- Full Cura verification green.
- Local candidate installed and healthy.
- Embedded uploaded-user-model flow green.
- No critical/major findings remain.

### Ready for production packaging

Additionally require:

- signed `.w3dp` from the protected signing workflow;
- immutable OCI digest and compatibility record alignment;
- trusted-key verification;
- supported architecture smoke evidence;
- rollback artifact retained;
- no unsigned-development exception in release metadata.

### Not covered by this program

Actual extrusion, thermal behavior, USB transport, and physical printer pause/resume/abort require hardware acceptance. Gateway output-device state tests are not a substitute.

## 8. Cleanup and handoff

- Remove temporary Playwright configs/specs and credential-bearing traces.
- Remove candidate containers only if they are not the active installed runtime.
- Preserve the active healthy plugin and its named volume.
- Revert unrelated generated screenshot churn.
- Run `git diff --check` in both repositories.
- Update `04-implementation-tracker.md` with exact commands, results, and any remaining limitation.
