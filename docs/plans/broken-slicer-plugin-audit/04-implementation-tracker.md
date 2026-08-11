# Plan 04: Implementation Tracker

Update this file as work completes. A checked task requires evidence, not only code presence.

## A. Audit baseline

- [x] **A01** Run the complete standalone Playwright suite.
- [x] **A02** Confirm real CuraEngine output and parameter sensitivity.
- [x] **A03** Run installed-plugin responsive matrix in light and dark themes.
- [x] **A04** Confirm bundled-model slice, Preview, G-code read, and WPrint artifact import.
- [x] **A05** Reproduce embedded new-STL upload failure with a 1.7 KB payload.
- [x] **A06** Establish that the request returns `502` after 60 seconds and never reaches the sidecar.
- [x] **A07** Identify the violated Guzzle `PumpStream` EOF contract.
- [x] **A08** Revalidate the 760 px material flow through the correct mobile control.

## B. WPrint proxy repair

- [x] **B01** Add real `UploadedFile` multipart-forwarding regression.
- [x] **B02** Add nested/repeated form-field and file-part coverage.
- [x] **B03** Add reconstructed-body aggregate-size coverage.
- [x] **B04** Reconstruct multipart from parsed fields/files using PSR-7 streams.
- [x] **B05** Generate and forward the matching multipart boundary.
- [x] **B06** Return `false`/`null` at raw `PumpStream` EOF.
- [x] **B07** Preserve JSON and response-stream behavior.
- [x] **B08** Map payload, temporary-file, upstream validation, and timeout errors safely.
- [x] **B09** Run focused PHP tests.
- [x] **B10** Run Pint on touched PHP files.

## C. Cura browser regressions

- [x] **C01** Fix the 760 px test to use visible responsive controls.
- [x] **C02** Select a material and assert the mobile summary updates.
- [x] **C03** Extract reusable WPrint authentication/iframe helpers.
- [x] **C04** Add permanent embedded upload/slice/preview/G-code/import test.
- [x] **C05** Keep responsive matrix independent from expensive slicing.
- [x] **C06** Run full standalone E2E suite.
- [x] **C07** Run `corepack pnpm verify`.

## D. Installed candidate

- [x] **D01** Build and verify staged WPrint plugin output.
- [x] **D02** Pack the local `.w3dp` candidate through WPrint tooling.
- [x] **D03** Record installed runtime state before replacement.
- [x] **D04** Install/enable candidate and verify exactly one healthy sidecar.
- [x] **D05** Upload a unique user STL through the installed iframe.
- [x] **D06** Complete a real CuraEngine job using returned durable upload.
- [x] **D07** Inspect real layer preview and authentic G-code.
- [x] **D08** Import generated G-code into WPrint.
- [x] **D09** Run the ten-case embedded responsive/theme matrix.
- [x] **D10** Confirm disable/re-enable recovery without deleting the volume.

## E. Negative and operational checks

- [x] **E01** Oversized upload returns `413`.
- [x] **E02** Invalid multipart returns finite `4xx`, not timeout/`502`.
- [x] **E03** Disabled plugin runtime request is rejected.
- [x] **E04** Sidecar outage does not break WPrint core flows.
- [x] **E05** Artifact import path/content-type restrictions remain enforced.
- [x] **E06** No secrets appear in logs, traces, diffs, or documentation.
- [x] **E07** Physical-printer validation is explicitly reported as not run unless hardware is exercised.

## F. Final hygiene

- [x] **F01** Remove temporary package staging directories.
- [x] **F02** Remove credential-bearing Playwright traces and temporary configs.
- [x] **F03** Revert unrelated generated screenshot churn.
- [x] **F04** Run `git diff --check` in both repositories.
- [x] **F05** Record remaining risks and exact verification commands below.

## G. Runtime budget and lifecycle remediation

- [x] **G01** Classify only multipart and mutating upload/job requests as heavy operations.
- [x] **G02** Keep settings resolution, polling, and layer retrieval in the metadata bucket.
- [x] **G03** Include a non-reversible encrypted-token hash in the managed-service fingerprint.
- [x] **G04** Increase the default authenticated bridge-readiness budget to 60 seconds.
- [x] **G05** Discard the exact persisted candidate when plugin enablement fails.
- [x] **G06** Confirm one healthy canonical sidecar and retained named volume after disable/re-enable.

## Evidence log

### Audit run: 2026-08-07

- Standalone Playwright: 33 passed, one WPrint-only test skipped, one stale responsive selector timed out; the corresponding product flow passed when driven through the visible mobile controls.
- Real slicing validation: five completed CuraEngine cases plus completed open-boundary case.
- Installed responsive test: ten host/theme combinations passed.
- Installed bundled-model workflow: slice, real Preview, G-code read, and WPrint artifact import passed.
- Installed user-model upload: 1.7 KB multipart request returned `502` after 60 seconds; no upload access line appeared in the Cura sidecar.

### Implementation evidence

### Acceptance run: 2026-08-07

- **Worktree state:** Both repositories contained unrelated pre-existing changes. This implementation was verified in place and was not committed as part of the audit.
- **Focused WPrint tests:** `php artisan test tests/Feature/Plugins/PluginManagementApiTest.php tests/Unit/Plugins/PluginManagerServiceTest.php tests/Unit/Plugins/PluginDependencyServiceTest.php tests/Unit/PluginRuntimeRateLimiterTest.php tests/Unit/Plugins/PluginRuntimeHttpClientTest.php` completed with 68 passing tests and 246 assertions.
- **PHP formatting:** Pint passed for all nine touched WPrint PHP files.
- **Cura verification:** `SLICER_GATEWAY_STORAGE_PATH=/tmp/wprint-codex-slicer-verify-20260807.sqlite corepack pnpm verify` passed contracts, all TypeScript packages, the web build, 36 web tests, 25 engine API tests, three plugin-host tests, three WPrint-plugin tests, and 40 gateway tests. The isolated SQLite path prevented unrelated retained local jobs from changing the result.
- **Standalone browser suite:** Playwright completed 36 tests: 34 passed and the two WPrint-host-only cases were skipped as designed.
- **Responsive installed-plugin suite:** Ten combinations passed: 375 x 812, 812 x 375, 768 x 1024, 1024 x 768, and 1440 x 900, each in light and dark themes. The suite records both host and iframe content viewports and exercises the primary embedded controls.
- **Installed slicing suite:** A uniquely generated STL returned `201` from upload, the durable upload identifier was used by a job request that returned exactly `202`, real CuraEngine completed, Preview loaded six cumulative layers with more than 713 real segments, generated G-code contained CuraEngine and `LAYER:0` markers without mock markers, and server-side import into WPrint succeeded.
- **Package:** The verified unsigned local `.w3dp` SHA-256 was `9a754247d74a985921649a7e4d5e29df01ae9d7de1766ee736f0edeb43a09f94`. It contained the generated UI CSS/JavaScript, entrypoint, notices, and manifest.
- **Installed runtime:** `cura-web-ui` 0.1.0 is enabled and ready. Exactly one healthy `wprint3d-plugin-cura-web-ui-cura-gateway` container remains, backed by `wprint3d-plugin-cura-web-ui-data` and image digest `sha256:64817b1976122ee9acc2cbb2c86cbfa79ab418b2957e6dfe7100de18df51c48b`.
- **Lifecycle:** Disable/re-enable retained the named data volume and converged to the single healthy canonical container after stale-candidate cleanup.
- **Negative coverage:** Oversized reconstructed uploads return `413`; malformed multipart completes with a finite upstream `422`; disabled runtimes, unavailable sidecars, invalid artifact paths, and invalid artifact content types are rejected safely.
- **Final hygiene checks:** Targeted secret and trailing-whitespace scans passed, and `git diff --check` passed in both repositories.
- **Cleanup:** Three generated WPrint test G-code artifacts, package staging, Playwright traces/reports, temporary configuration, the isolated SQLite database, and the temporary network trace were removed. Pre-existing user screenshots and unrelated worktree edits were preserved.
- **Remaining limitations:** No physical printer was exercised. The package is an unsigned local candidate; production signing, registry publication, and multi-architecture release-image acceptance remain release gates rather than claims of this audit.
