# Cura Integration Release, Verification, and Rollout Plan

**Goal:** Coordinate the two repositories and two release artifacts into a reproducible WPrint release, prove failure/recovery behavior, and roll out without risking existing printer operation.

**Repositories:** `wprint3d-core`, `../cura-web-ui`, and the official plugin registry/release infrastructure when available.

**Prerequisites:** Plans 02–06 are complete and their focused gates are green.

## 1. Required release compatibility record

Create one machine-readable compatibility document in the Cura release and copy its values into WPrint built-in inventory metadata:

```json
{
  "curaWebUiVersion": "X.Y.Z",
  "wprintPluginSdk": {"version": 1, "revision": 5},
  "minimumWPrintCoreVersion": "A.B.C",
  "gatewayApiVersion": "1.1",
  "runtimeImage": {
    "reference": "ghcr.io/wprint3d/cura-web-slicer:X.Y.Z",
    "digest": "sha256:...",
    "platforms": ["linux/amd64", "linux/arm64"]
  },
  "curaEngine": {
    "version": "...",
    "sourceCommit": "...",
    "resourceRevision": "..."
  },
  "w3dp": {
    "fileName": "cura-web-ui-X.Y.Z.w3dp",
    "sha256": "...",
    "signerFingerprint": "..."
  }
}
```

Every value must be generated from verified artifacts. Do not hand-copy a digest or checksum into the final WPrint image.

## 2. Feature flags and safe defaults

Add temporary rollout controls in WPrint configuration:

```text
WPRINT3D_BUILTIN_CURA_ENABLED=true|false
WPRINT3D_BUILTIN_CURA_AUTO_ENABLE=true|false
WPRINT3D_PLUGIN_RUNTIME_PROXY_ENABLED=true|false
WPRINT3D_PLUGIN_RUNTIME_RECONCILE_ENABLED=true|false
```

Rules:

- code paths default enabled in development after tests are green;
- first production canary may bundle but not auto-enable Cura;
- disabling Cura feature flag does not disable unrelated plugins/runtime facilities;
- emergency proxy disable leaves existing printing, file, camera, and terminal flows operational;
- flags are temporary migration controls and must have a removal issue/milestone.

Do not use flags to hide a failed release gate.

The four controls are implemented in `config/plugins.php`. The built-in installer
honors the Cura-specific controls without skipping unrelated built-ins, startup
reconciliation honors its own switch, and the runtime proxy returns an explicit
`503` response while disabled. Host context also omits proxy/import URLs when the
proxy kill switch is active.

## 3. Cross-repository release sequence

The sequence is strict.

### Stage 3.1: WPrint SDK/core candidate

1. Merge SDK revision 5 and runtime hardening.
2. Run full WPrint backend/frontend/plugin suites.
3. Build a candidate WPrint backend image containing the new runtime manager but no production Cura `.w3dp` yet.
4. Publish or identify the exact WPrint commit/tooling revision used to validate/package plugins.
5. Exercise revision-2/3/4 example plugins for backward compatibility.
6. Tag a core release candidate or immutable commit SHA for Cura CI.

### Stage 3.2: Cura artifacts

1. Pin the WPrint validator/packager revision from Stage 3.1.
2. Build and test amd64/arm64 runtime images.
3. Push the multiarch versioned tag.
4. Resolve and verify the multiarch digest/platform descriptors.
5. Render the release manifest with digest and minimum core version.
6. Build/stage UI and integrity map.
7. Package/sign/verify `.w3dp` with the pinned WPrint toolchain.
8. Generate checksum, SBOM, provenance, and corresponding-source metadata.
9. Publish a Cura release candidate and official registry candidate entry.

### Stage 3.3: WPrint built-in image

1. Download the exact signed Cura release candidate `.w3dp` by immutable release asset URL.
2. Verify archive checksum and signer fingerprint against compatibility record.
3. Stage it through WPrint's built-in plugin script.
4. Build production WPrint backend image.
5. Run static built-in inventory/package verification inside that image.
6. Start a full WPrint stack and execute the release E2E suite.
7. Publish WPrint release candidate.

The WPrint image workflow accepts `cura_w3dp_url`, `cura_w3dp_sha256`,
`cura_plugin_version`, `cura_compatibility_url`, and
`cura_compatibility_sha256` only for an explicit dispatch. It downloads over HTTPS,
requires the `CURA_W3DP_SIGNER_PUBLIC_KEY` repository secret, runs
`plugin:verify --require-trusted`,
and atomically renders `resources/plugins/builtin/index.json` before Docker
build. Pushes without those inputs intentionally contain an empty inventory;
they cannot silently pick up a mutable `latest` package.

### Stage 3.4: Finalize

Promote both artifacts without rebuilding them. Final release tags must point to the already-tested OCI digest and `.w3dp` bytes. If any artifact is rebuilt, repeat downstream verification and update all checksums/provenance.

## 4. CI job matrix

### WPrint core required jobs

| Job | Required evidence |
| --- | --- |
| PHP unit/feature | All plugin validator, archive, packager, lifecycle, proxy, artifact, built-in, and bootstrap tests. |
| Formatting | Pint passes on modified backend files. |
| Frontend unit | Plugin renderer/workspace/context/message tests. |
| Frontend export | Expo web export succeeds. |
| Backward compatibility | SDK revisions 0–4 fixtures install/render/enable as before. |
| Docker fixture | Managed bridge create/reconcile/update/rollback/disable/volume retention. |
| Static built-in audit | Inventory, archive SHA, signature, ID/version, min core. |
| Security | Path traversal, header spoofing, redirect, secret redaction, volume targeting, archive tamper. |

### Cura required jobs

| Job | Required evidence |
| --- | --- |
| Contracts | OpenAPI/AsyncAPI lint and generated diff clean. |
| TypeScript | Typecheck/unit/build. |
| Gateway | Python tests including SQLite/recovery/cancel/auth/cleanup. |
| UI E2E standalone | Real gateway/CuraEngine, not mock adapter. |
| Runtime amd64 | Build, security start, real slice, restart, cancel. |
| Runtime arm64 | Native build/run with same tests. |
| Manifest/package | WPrint r5 validator, signed pack, verify, asset integrity. |
| Supply chain | Digest, SBOM, provenance, secret scan, license/source audit. |

### Integrated required jobs

Use a full WPrint stack plus exact candidate `.w3dp` and OCI digest. Required flows are listed in Section 6.

## 5. Failure-injection matrix

Automate where practical. Otherwise document exact manual steps and captured evidence.

| Failure | Injection | Expected result |
| --- | --- | --- |
| Registry unavailable on fresh install | Block registry DNS/network | Cura plugin install fails visibly; WPrint remains healthy; retry works later. |
| Pull exceeds timeout | Slow/fake registry | Operation ends bounded; no half-installed active version. |
| Digest mismatch | Test registry/reference fixture | Installation rejected before activation. |
| Candidate health fails | Image returns unhealthy | Previous healthy version remains active. |
| Docker socket unavailable | Remove socket in test stack | Plugin marked failed; core/printers continue; diagnostic is actionable. |
| Docker network renamed | Recreate Compose network | Bootstrap reconciliation reattaches/recreates runtime. |
| Managed container deleted | `docker rm -f` fixture | Reconcile recreates it from desired state. |
| Managed volume missing | Remove disposable test volume | Runtime recreates empty volume and reports data loss; no host-path fallback. |
| Sidecar killed while queued | Kill container | Restart recovers queued job. |
| Sidecar killed while slicing | Kill container/process | Job becomes retry-queued and completes once, within max attempts. |
| CuraEngine hangs | Test wrapper blocks | Timeout kills process group; job fails/retries per policy. |
| CuraEngine ignores TERM | Test wrapper traps TERM | KILL after grace; no orphan process. |
| Disk full | Quota-limited fixture volume | Upload/job rejected with `507`; WPrint unaffected; cleanup guidance shown. |
| Corrupt SQLite | Corrupt disposable DB copy | Health fails clearly; volume retained; no destructive automatic reset. |
| Corrupt layer artifact | Modify fixture file | Artifact request fails with diagnostic; job metadata remains; re-slice offered. |
| Browser reload mid-upload | Reload/abort | Partial upload removed/expired; no phantom job. |
| Browser reload mid-job | Reload | Existing job resumes by polling; no duplicate submission. |
| WPrint backend restarts | Restart backend only | Sidecar continues/reconciles; UI reconnects after host returns. |
| User disabled plugin | Disable through UI | Container removed; volume retained; page disappears/unavailable. |
| Cross-user access | Use second account and captured IDs | `404`; no jobs/artifacts leak. |
| Header spoof | Send `X-WPrint-User-Id` from browser | Core strips/replaces; cannot impersonate. |
| G-code import collision | Existing same filename | `409`; existing file unchanged. |
| G-code import interrupted | Kill upstream mid-stream | Temp file removed; no partial visible file. |
| Tampered W3DP asset | Modify UI file after signing | Verification/installation rejected. |

Do not run destructive failure injections against production data or physical printer workflows. Use disposable plugin volumes, test databases, and fake/unconnected printer context.

## 6. Integrated acceptance flows

### Flow A: Fresh built-in installation

1. Start from clean WPrint volumes and no Cura image.
2. Start WPrint candidate stack.
3. Observe built-in inventory verification and image pull.
4. Verify plugin record has source `builtin`, trusted signer, expected version, and heavyweight classification.
5. Verify managed container flags, labels, network, digest, non-root user, and volume.
6. Verify WPrint login/terminal/files remain usable during and after plugin initialization.
7. Open slicer page and verify health/versions.

### Flow B: Real slice and import

1. Use a normal user account.
2. Open embedded slicer in WPrint.
3. Load deterministic cube STL.
4. Choose real printer/material/profile resources.
5. Submit and observe upload/queue/progress.
6. Preview real first/middle/last layers.
7. Save to WPrint as `Sliced/integration-cube.gcode`.
8. Verify file appears through normal WPrint file API/UI with nonzero size.
9. Verify generated file is parseable by WPrint preview/stat tooling.
10. Do not start a physical print as part of automated integration.

### Flow C: Restart recovery

1. Start a sufficiently slow real slice.
2. Kill only the sidecar container.
3. Let Docker restart/reconcile it.
4. Verify job shows recovery/retry rather than disappearing.
5. Verify exactly one completed artifact set and bounded attempts.
6. Reload browser and verify state resumes.

### Flow D: Update success

1. Install/enable candidate version N with completed job data.
2. Offer signed version N+1 with a different image digest.
3. Update through WPrint.
4. Verify candidate health before switch.
5. Verify canonical container now uses N+1 digest/spec hash.
6. Verify N completed jobs/data remain.
7. Verify UI/gateway compatibility and a new real slice.

### Flow E: Update rollback

1. Begin with healthy version N.
2. Attempt signed N+1 fixture whose gateway health fails.
3. Verify update reports failure.
4. Verify current installed/active version remains N.
5. Verify N container still handles API and prior jobs.
6. Verify failed candidate removed and diagnostics retained.

### Flow F: Disable, reboot, re-enable

1. Disable Cura plugin.
2. Verify container removed and data volume retained.
3. Restart WPrint stack.
4. Verify reconcile does not restart disabled runtime.
5. Re-enable.
6. Verify container starts and prior completed jobs remain.

### Flow G: Uninstall retention and explicit deletion

1. Uninstall with default retention.
2. Verify container/package record removed and volume retained/labeled.
3. Reinstall same plugin and verify documented data reuse behavior.
4. Uninstall again and explicitly delete data through admin confirmation.
5. Verify only the validated managed volume is removed.

## 7. Performance and soak verification

### Baseline metrics

Record separately for representative amd64 desktop/server and arm64 SBC:

- image compressed/uncompressed size;
- first pull duration on representative network;
- idle sidecar RSS/CPU;
- cube and medium-model slice time;
- peak CuraEngine RSS;
- upload throughput through WPrint proxy;
- G-code import throughput;
- preview layer response latency;
- SQLite/database size growth;
- storage cleanup time;
- WPrint backend RSS impact during streaming;
- time from WPrint boot to plugin ready.

### Soak scenarios

Run at least:

- 50 sequential small real slices;
- repeated cancel/restart cycle;
- 24-hour idle with periodic health/polling;
- storage retention/cleanup with hundreds of completed jobs;
- repeated WPrint backend restarts while sidecar remains;
- repeated theme/page navigation without iframe/job memory growth.

Acceptance:

- no orphan CuraEngine processes;
- no unbounded event/task/iframe memory growth;
- no SQLite lock storms;
- no duplicate job execution;
- no growing temporary files after cleanup;
- existing WPrint print workers/serial polling remain responsive.

Set hard resource thresholds only after collecting baseline on target hardware. The initial container memory limit remains enforced and OOM behavior must produce a recoverable failed/retry job, not destabilize the host.

## 8. Observability and support bundle

### WPrint diagnostics

Expose to administrators:

- installed Cura plugin/UI/runtime versions;
- desired image reference and actual digest;
- container running/health/spec match;
- last pull/start/health/reconcile/update timestamps;
- bounded sanitized container log tail;
- retained volume present and approximate usage;
- last plugin lifecycle/update error.

### Gateway diagnostics

Expose non-sensitive health/capability fields and per-job downloadable sanitized engine logs for the job owner/admin policy. Logs must not contain model bytes, bearer tokens, host filesystem paths, or arbitrary environment dumps.

### Support bundle

Extend existing logging/support tooling to optionally include:

- plugin manifest without signature value/token state;
- built-in inventory entry;
- lifecycle log;
- sanitized Docker inspection subset;
- gateway build metadata/health;
- selected job metadata/error/log tail with user consent;
- no uploaded meshes or G-code by default.

## 9. Backup and recovery policy

Phase one plugin-volume backups are explicit, not implied:

- WPrint backup documentation states whether plugin volumes are included.
- Until volume backup exists, completed G-code imported into WPrint is covered only by existing WPrint storage backup policy; sidecar projects/jobs are not guaranteed by WPrint backup.
- Add a follow-up for volume export/import using a safe host-managed helper container, never arbitrary plugin shell access.
- Corrupt database recovery must preserve the volume and provide export/diagnostic instructions. Do not auto-delete/reinitialize production data.

Document disaster recovery:

1. stop/disable plugin runtime;
2. snapshot retained volume;
3. collect diagnostics;
4. attempt tested migration/repair on a copy;
5. restore or explicitly reset only with administrator confirmation.

## 10. Rollout stages

### Stage 0: Developer-only

- unpacked plugin;
- local image tag allowed;
- real CuraEngine required;
- runtime proxy and lifecycle behind feature flags;
- no official registry/built-in staging.

Exit: integrated flows A–C pass in development.

### Stage 1: Signed opt-in beta

- signed `.w3dp` in official beta channel;
- digest-pinned multiarch image;
- not auto-enabled;
- collect performance/failure metrics from target amd64/arm64 systems.

Exit: no P0/P1 lifecycle, data-loss, auth, or printer-interference defects; update/rollback flows pass.

### Stage 2: Bundled but user-enabled

- `.w3dp` included in WPrint production image;
- visible as built-in;
- user/admin enables and triggers first runtime start;
- existing WPrint behavior unchanged if never enabled.

Exit: first-pull/start support burden acceptable and soak gates pass.

### Stage 3: Enabled by default

- fresh installations enable automatically;
- upgrades preserve prior explicit disable;
- failure remains isolated from core operation.

Exit: target hardware matrix and operational support sign-off complete.

## 11. Rollback procedures

### Roll back Cura plugin only

1. Disable automatic update for affected version if needed.
2. Publish/restore official registry pointer to last good signed version without mutating old artifacts.
3. Use WPrint plugin rollback/update path to activate previous package/image digest.
4. Preserve plugin volume; migrations must be backward-compatible or declare one-way boundary before release.
5. Verify old gateway can read database. If not, stop and restore a volume snapshot; do not start old code on a newer unsupported schema.

### Disable built-in activation without WPrint downgrade

Use the Cura feature flag/default-enabled inventory policy in a patched WPrint release. Existing installed user choice and data remain visible/recoverable.

### Roll back WPrint core

Only if plugin SDK/database changes are backward compatible. Before core rollback, disable managed plugins or verify older core will not misinterpret revision-5 manifests. Never leave an unmanaged sidecar with credentials running indefinitely.

## 12. Final sign-off checklist

### Architecture and security

- [ ] `.w3dp` signed and asset integrity verified.
- [ ] OCI reference pinned by verified multiarch digest.
- [ ] Browser sees only same-origin WPrint endpoints.
- [ ] Sidecar token encrypted/redacted and never sent to browser.
- [ ] Managed container has no privileged mode, host port, Docker socket, USB, or arbitrary host mount.
- [ ] Resource/security flags confirmed by actual inspection.
- [ ] Cross-user and path/header abuse tests pass.

### Durability

- [ ] SQLite migration/restart/retry/cancel tests pass.
- [ ] No FastAPI BackgroundTasks production slicing remains.
- [ ] Active process groups terminate on cancel/shutdown/timeout.
- [ ] Volume survives disable/update and obeys uninstall retention.
- [ ] Import is atomic and partial files are cleaned.

### Product/UI

- [ ] Full WPrint visual matrix passed in light/dark.
- [ ] No duplicate standalone/account/gateway/output chrome embedded.
- [ ] Real model upload, slice, preview, reload recovery, cancel, and import pass.
- [ ] Keyboard/accessibility/long Spanish copy verified.
- [ ] Existing WPrint Terminal, Preview, Control, Files, Settings, and mobile navigation have no regression.

### Platform/release

- [ ] Native amd64 real slice passes.
- [ ] Native arm64 real slice passes.
- [ ] SBOM, provenance, licenses, and corresponding source published.
- [ ] Built WPrint image contains exact verified `.w3dp`.
- [ ] Update success and rollback fixtures pass.
- [ ] Failure injection and soak gates pass.
- [ ] Operational diagnostics, backup limitations, and rollback runbook documented.

Only after every required item is checked should the built-in plugin move to default-enabled production rollout.
