# Plan 05: Runtime Rate Limits and Managed-Sidecar Lifecycle

**Repository:** `wprint3d-core`.

**Objective:** Ensure a normal slicer session cannot throttle itself, and ensure reinstalling or re-enabling a managed bridge cannot reuse stale credentials or leak candidate containers.

## 1. Audit the request budget by semantic operation

The installed Cura UI performs several catalog reads, account/output-device reads, and `POST /settings/resolve` calls before the user creates a job. After a job completes, Preview reads job status, the layer manifest, diagnostics, and individual layer artifacts. These operations are normal metadata traffic even though some use `POST` for a large query payload.

The heavy bucket must be selected only for:

- any `multipart/form-data` request;
- a mutating `POST`, `PUT`, `PATCH`, or `DELETE` whose runtime path contains an `/uploads` or `/jobs` resource segment;
- artifact import through its independent artifact bucket.

The metadata bucket must include:

- `POST /settings/resolve`;
- catalog and capability reads;
- `GET /jobs/{id}` polling;
- `GET /jobs/{id}/layers` and `GET /jobs/{id}/layers/{layer}`;
- G-code and preview reads before server-side import.

Do not classify by HTTP method alone. Do not classify a read as heavy merely because its path is below `/jobs`.

### Required unit matrix

In `tests/Unit/PluginRuntimeRateLimiterTest.php`, assert both limits and keys for:

| Request | Expected bucket |
| --- | --- |
| `GET /capabilities` | metadata |
| `POST /settings/resolve` JSON | metadata |
| `GET /jobs/job-1/layers/5` | metadata |
| `POST /jobs` JSON | upload/heavy |
| multipart `POST` to another allowed path | upload/heavy |
| WPrint runtime-artifact import | artifact |

Metadata requests must share one plugin/user metadata key. Heavy operations must share a different plugin/user key. Artifact import must remain independent.

## 2. Fail E2E immediately on job throttling

The embedded slicing test must wait for the actual `POST /runtime/api/v1/jobs` response and require `202`. Do this before waiting for the event timeline. A missing job request or `429` must fail within the request timeout instead of presenting as a three-minute empty-timeline timeout.

Keep the later assertions for `job.completed`, real toolpath segments, cumulative layer loading, CuraEngine G-code markers, and WPrint artifact import. They catch metadata-bucket regressions that a successful job creation alone cannot detect.

## 3. Include bridge credentials in managed-service identity

A managed WPrint bridge token is generated/persisted independently of the image and service manifest. If the token changes while image, service, network, and alias stay equal, reusing the running container produces an unavoidable `401`.

Extend the service configuration fingerprint with a non-reversible SHA-256 of the encrypted token value. Requirements:

- never place plaintext credentials in a Docker label, log, diagnostic payload, or test failure;
- use the persisted ciphertext so diagnostics and activation calculate the same value;
- pass the same auth fingerprint when calculating canonical, candidate, saved-state, promotion, and diagnostic fingerprints;
- treat a changed token as a configuration change and start a candidate instead of reusing the canonical container;
- continue injecting plaintext only through the container environment at creation time.

### Required regression

Activate a revision-5 managed bridge with an initial encrypted token, capture its fingerprint, simulate a running canonical container with that label, rotate the encrypted token, and activate again. Assert that:

- a candidate is created;
- the new fingerprint differs;
- the run command includes a bridge-token environment argument;
- no assertion prints the plaintext token.

## 4. Budget readiness for retained slicer state

The gateway opens its SQLite database and validates retained artifact state during startup. A populated persistent volume can take longer than the original 15-second retry window.

Set the default bridge retry budget to 120 attempts at 500 ms, or 60 seconds total. Preserve environment configurability. Retry only transport-level `ConnectionException`; application-level authentication or validation failures remain fail-fast.

The Docker health state is supporting evidence, not a replacement for the authenticated bridge readiness request.

## 5. Clean failed candidates from persisted state

`PluginManagerService::serializePlugin()` deliberately omits raw `dependency_state` from browser-facing payloads. Failure cleanup must therefore not look for a `dependency_state` key in that serialized array.

On enable failure:

1. refresh the plugin model;
2. read `dependency_state` from the refreshed model;
3. if that state declares a candidate, call `discardCandidate()` with the exact persisted state;
4. otherwise deactivate the normal runtime payload;
5. mark the plugin failed without deleting retained volumes.

Add a unit test whose activation persists candidate metadata and whose bridge healthcheck fails. Require `discardCandidate(candidateState)` exactly once and require that ordinary `deactivate()` is not called.

## 6. Installed-package acceptance

After focused tests and Pint pass:

1. record current plugin/container/image/volume state;
2. install the exact verified local `.w3dp`;
3. enable the plugin and allow the full readiness budget;
4. confirm exactly one container with `wprint3d.plugin.id=cura-web-ui` remains;
5. confirm it is healthy and uses the digest-pinned image;
6. disable and re-enable the plugin;
7. confirm the named data volume remains;
8. rerun embedded upload, job `202`, real slicing, six cumulative layers, G-code, and import;
9. rerun the responsive/theme matrix.

If a stale candidate from an earlier broken build exists, resolve its labels and persisted ownership first, then remove only that exact non-canonical container. Never delete the plugin data volume during candidate cleanup.

## 7. Verification commands

```bash
php artisan test tests/Unit/PluginRuntimeRateLimiterTest.php
php artisan test tests/Unit/Plugins/PluginDependencyServiceTest.php
php artisan test tests/Unit/Plugins/PluginManagerServiceTest.php
php artisan test tests/Feature/Plugins/PluginManagementApiTest.php
./vendor/bin/pint --test app/Providers/RouteServiceProvider.php app/Plugins/PluginDependencyService.php app/Plugins/PluginManagerService.php
```

Run the installed embedded Playwright specifications only with credentials supplied through process environment variables. Remove their traces after verification.

### Gate 05

- initial UI traffic does not produce `429`;
- job creation returns `202`;
- at least six cumulative Preview layers load through the host;
- token rotation creates/replaces a candidate safely;
- disable/re-enable retains data;
- exactly one healthy canonical sidecar remains;
- no secret appears in logs, docs, diffs, or retained traces.
