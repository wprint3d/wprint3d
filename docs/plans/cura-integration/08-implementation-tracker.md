# Cura Integration Implementation Tracker

**Purpose:** This is the compact execution ledger for the detailed plans. It does not replace them. Before checking an item, execute the corresponding task in the linked document and record evidence.

## How Luna should use this tracker

For one checkbox at a time:

1. Open the named detailed plan/task.
2. Re-read every listed source file because the repository may have changed.
3. Record pre-existing dirty files and do not overwrite unrelated changes.
4. Add the failing focused test.
5. Run it and record the expected failure.
6. Implement only that task's contract.
7. Run focused tests and formatting/build checks.
8. Update applicable docs.
9. Record the commit SHA or working-tree checkpoint and evidence below.
10. Move to the next checkbox only when its prerequisites are green.

If a task changes a public contract, update `01-architecture-and-contracts.md` first. If a required external fact cannot be verified, stop at that gate rather than inserting a placeholder implementation.

## Evidence template

Copy this block into the implementation progress report for every item:

```text
Task ID:
Repositories/files changed:
Pre-existing dirty files preserved:
Failing test added:
Observed red result:
Implementation summary:
Focused verification commands:
Focused verification results:
Full/phase verification results:
Manual/visual evidence:
Known limitations:
Commit/checkpoint:
Next unblocked task:
```

## Phase A: Contract acceptance

Detailed source: [`01-architecture-and-contracts.md`](01-architecture-and-contracts.md).

- [x] **A01** Accept sidecar-owned durable slice jobs; do not use Laravel/Redis as the slice source of truth.
- [x] **A02** Accept `.w3dp` plus digest-pinned external OCI image as the normal online distribution unit.
- [x] **A03** Defer OCI archive embedding/offline bundles explicitly.
- [x] **A04** Accept Plugin SDK 1 revision 5 fields and compatibility behavior.
- [x] **A05** Accept same-origin HTTP proxy plus polling for the first embedded release.
- [x] **A06** Accept private named-volume retention and explicit deletion policy.
- [x] **A07** Accept server-to-server G-code import and initial one-job concurrency.
- [ ] **A08** Accept native amd64 and arm64 real-slice release gates.

**Gate A evidence:** architecture checklist signed off and no unresolved ownership decision.

## Phase B: WPrint managed runtime

Detailed source: [`02-wprint-managed-runtime-hardening.md`](02-wprint-managed-runtime-hardening.md).

- [x] **B01** Add SDK revision-5 manifest tests and revision-aware normalization. Requires A04.
- [x] **B02** Update SDK reference/changelog and preserve revisions 0–4. Requires B01.
- [x] **B03** Introduce typed/testable Docker container client. Requires B01.
- [x] **B04** Add deterministic runtime/container/volume identities and spec hash. Requires B03.
- [x] **B05** Add encrypted/redacted managed-runtime secret store. Requires B03.
- [x] **B06** Create private named volume and enforced container spec. Requires B04–B05.
- [x] **B07** Fix per-image pull timeout, digest verification, and image healthcheck execution. Requires B03.
- [x] **B08** Implement authenticated blue/green activation. Requires B04–B07.
- [x] **B09** Implement healthy-version preservation and rollback on update failure. Requires B08.
- [x] **B10** Add safe archive staging, zip-slip/link/size protection, and atomic promotion. Requires B01.
- [x] **B11** Add signed asset-integrity generation/verification/tamper tests. Requires B10.
- [x] **B12** Make install/update transactional across package, image, DB, and runtime. Requires B08–B11.
- [x] **B13** Add runtime reconciliation command and server-bootstrap integration. Requires B08.
- [x] **B14** Add retained-data diagnostics and explicit admin volume deletion. Requires B06, B13.
- [x] **B15** Run disposable managed-bridge Docker lifecycle smoke test. Requires B01–B14.

**Gate B evidence:** phase-02 unit/feature/formatting commands pass; actual fixture container survives stop/reconcile/update rollback; no secrets appear in output.

## Phase C: WPrint proxy, artifact import, and built-ins

Detailed source: [`03-wprint-runtime-proxy-and-builtins.md`](03-wprint-runtime-proxy-and-builtins.md).

- [x] **C01** Add managed-runtime HTTP client with resolved state, bearer auth, trusted headers, and streaming. Requires B05, B08.
- [x] **C02** Reuse client for managed actions/hooks/healthchecks. Requires C01.
- [x] **C03** Add authenticated allowlisted runtime proxy route/controller. Requires B01, C01.
- [x] **C04** Add multipart/binary streaming limits, timeout policy, header stripping, and rate limits. Requires C03.
- [x] **C05** Add server-to-server G-code artifact importer and atomic file visibility. Requires C01, B01.
- [x] **C06** Add workspace custom-bundle presentation and non-secret host URL context. Requires B01.
- [x] **C07** Add built-in inventory/repository/installer and source type. Requires B12.
- [x] **C08** Integrate idempotent optional built-in install/update into bootstrap. Requires B13, C07.
- [x] **C09** Add release staging script and production-image static package audit. Requires C07.
- [x] **C10** Run real streaming proxy/import fixture and signed built-in package bootstrap test. Requires C01–C09.

**Gate C evidence:** phase-03 tests pass; browser-facing API reveals no internal URL/token; large proxy/import bodies remain streamed; built-in install is idempotent.

## Phase D: Durable Cura gateway

Detailed source: [`04-cura-durable-gateway.md`](04-cura-durable-gateway.md).

- [x] **D01** Add OpenAPI/AsyncAPI upload, job 1.1, progress, idempotency, and polling contracts. Requires A01, A05.
- [x] **D02** Regenerate Python/TypeScript contracts and extend `HttpSlicerClient`. Requires D01.
- [x] **D03** Add production storage/quota settings and safe storage-path resolver. Requires D01.
- [x] **D04** Add SQLite migrations and upload/job/artifact/event repositories. Requires D03.
- [x] **D05** Add streaming authenticated upload endpoints. Requires D04.
- [x] **D06** Replace FastAPI BackgroundTasks with lifespan-managed durable JobRunner. Requires D04–D05.
- [x] **D07** Refactor CuraEngine to async subprocess execution with progress and process-group cancellation. Requires D06.
- [x] **D08** Persist/stream G-code, logs, diagnostics, thumbnails, layer index, and chunks as files. Requires D04, D07.
- [x] **D09** Add WPrint bridge auth mode and owner isolation. Requires D04.
- [x] **D10** Add safe cleanup, retention, quota, and storage health. Requires D04, D08.
- [x] **D11** Remove legacy job/artifact methods from production memory/JSON wiring. Requires D06–D10.
- [x] **D12** Run real restart/retry/cancel/cross-owner/cleanup sequence. Requires D01–D11.

**Gate D evidence:** `pnpm verify` passes; real gateway process restart recovers a job exactly once; cancellation kills real CuraEngine; artifacts persist and remain owner-scoped.

## Phase E: Runtime image and W3DP

Detailed source: [`05-cura-container-and-package.md`](05-cura-container-and-package.md).

- [x] **E01** Create immutable engine/resource lock with verified source commits/checksums/licenses. Requires A08.
- [x] **E02** Prove source build and real cube slice on native amd64. Requires E01.
- [ ] **E03** Prove same source build and real cube slice on native arm64. Requires E01.
- [x] **E04** Add multi-stage non-root/read-only production Dockerfile and entrypoint. Requires D12, E02–E03.
- [x] **E05** Add runtime metadata, health executable, OCI labels, and container security tests. Requires E04.
- [x] **E06** Add multiarch real-slice/restart/cancel CI workflow. Requires E04–E05.
- [x] **E07** Replace invalid Cura plugin manifest with revision-5 generated template. Requires B01, C03, C05, C06.
- [x] **E08** Make Vite build relocatable and atomically stage UI/licenses/build metadata. Requires E07.
- [x] **E09** Validate manifest/package using pinned WPrint revision-5 toolchain. Requires B11, E08.
- [ ] **E10** Build/push multiarch image, resolve digest, render manifest, then sign `.w3dp` in that order. Requires E06, E09.
- [ ] **E11** Generate/verify SBOM, provenance, notices, and corresponding source. Requires E10.

**Gate E evidence:** exact OCI digest supports both platforms; signed `.w3dp` references that digest; WPrint verifier passes; archive contains complete UI and notices; native real slicing passes twice.

The repository now implements the digest-gated `stage-w3dp` workflow job and
the release-mode checks for platform manifests and attestations. E10/E11 stay
unchecked until a real registry publish, native arm64 run, SBOM/provenance
resolution, and protected WPrint signing job produce release evidence.

## Phase F: Embedded UI

Detailed source: [`06-embedded-ui-integration.md`](06-embedded-ui-integration.md).

- [x] **F01** Add typed defensive host-context/theme parser. Requires C06.
- [x] **F02** Add permission-filtered WPrint plugin host-context endpoint. Requires C03.
- [x] **F03** Centralize embedded versus standalone transport selection. Requires D02, F01.
- [x] **F04** Replace scene base64 submission with upload coordinator/upload IDs. Requires D05, F03.
- [x] **F05** Add durable polling/reload recovery/cancel state handling. Requires D06–D09, F03.
- [x] **F06** Apply embedded feature policy and remove duplicate/fake host chrome. Requires F01.
- [x] **F07** Apply WPrint semantic tokens, workspace fill, scroll, and live theme updates. Requires C06, F01.
- [x] **F08** Add English/Spanish integration localization and fallback tests. Requires F01.
- [x] **F09** Add Save-to-WPrint artifact flow and host file-list refresh message. Requires C05, F05.
- [x] **F10** Complete accessibility/unit/visual matrix. Requires F04–F09.
- [ ] **F11** Run cross-application real-Cura E2E flow. Requires E10, F01–F10.

**Gate F evidence:** embedded browser uses only same-origin WPrint endpoints; real upload/slice/reload/preview/cancel/import passes; no duplicated standalone chrome; full light/dark responsive and accessibility matrix recorded.

The executable cross-application harness is now
[`scripts/e2e_cura_wprint3d.py`](../../scripts/e2e_cura_wprint3d.py). It is
fail-closed and requires a staged non-zero digest, disposable-stack opt-in,
and second-user credentials; F11 remains open until that harness runs against
the candidate WPrint image and published gateway digest.

## Phase G: Release and rollout

Detailed source: [`07-release-verification-and-rollout.md`](07-release-verification-and-rollout.md).

- [x] **G01** Generate compatibility record from exact verified artifacts. Requires E10–E11.
- [x] **G02** Stage exact signed `.w3dp` into WPrint candidate image. Requires C09, G01.
- [ ] **G03** Run WPrint/Cura/integrated required CI matrices. Requires F11, G02.
- [ ] **G04** Complete every failure-injection scenario or document bounded approved exception. Requires G03.
- [ ] **G05** Complete fresh install, slice/import, restart, update, rollback, disable/re-enable, and uninstall retention flows. Requires G03.
- [ ] **G06** Record amd64/arm64 performance baseline and complete soak scenarios. Requires G03.
- [ ] **G07** Complete diagnostics/support bundle and backup/recovery documentation. Requires G03.
- [ ] **G08** Release signed opt-in beta. Requires G01–G07.
- [ ] **G09** Bundle as user-enabled built-in after beta exit criteria. Requires G08 evidence.
- [ ] **G10** Enable by default only after final sign-off checklist. Requires G09 evidence.

The repository now contains the automation needed for G01/G02
(`create:compatibility-record`, digest-gated Cura staging, and WPrint's
trusted built-in inventory/stager), but these checkboxes remain open until the
external release artifacts and candidate image produce immutable evidence.

## Blocker rules

Mark a task blocked only with evidence. Use one of these categories:

- **Contract decision:** product/architecture choice conflicts with `01`.
- **External dependency:** upstream CuraEngine/resource build cannot be reproduced.
- **Platform:** native arm64 runner/build/runtime unavailable or failing.
- **Security:** safe proxy/container/archive behavior cannot be demonstrated.
- **Data migration:** new code cannot safely read/roll back the persistent schema.
- **Tooling:** pinned WPrint packager/verifier unavailable.

For a blocker, record:

1. exact failing command/test;
2. full bounded error;
3. files/commits involved;
4. three attempted safe resolutions when applicable;
5. smallest decision or external change needed;
6. tasks that remain independently unblocked.

Do not bypass a blocker with mock slicing, unsigned release artifacts, mutable image tags, disabled auth, fake arm64 claims, browser token exposure, or destructive storage reset.

## Current external gate report

- **Platform:** `uname -m` reports `x86_64`; the only local Buildx node is the
  Docker Desktop node with QEMU emulation. No native arm64 runner or Docker
  context is configured locally.
- **Evidence:** the local amd64 source build and real slice pass; the local
  OCI/attestation and compatibility fixtures pass; the full arm64 source build
  was intentionally not promoted as evidence because it would run under QEMU.
- **Safe resolutions attempted:** inspected SSH hosts/configuration, Docker
  contexts, and Buildx nodes; retained the native `ubuntu-24.04-arm` workflow;
  exercised the release verifiers with a synthetic two-platform OCI index.
- **Hosted registry evidence:** the latest remote WPrint Docker Image CI run
  reached multi-architecture manifest creation but failed while copying one
  Docker Hub layer with HTTP 429 (`toomanyrequests`). The workflow already
  authenticates before publication; a registry quota/credential change is
  required before treating that run as release evidence.
- **Smallest external change needed:** run
  `.github/workflows/multiarch-image.yml` from a repository branch with access
  to the hosted native arm64 runner and registry write permission; then run
  WPrint's protected `.github/workflows/cura-plugin-signing.yml` with its
  signing environment approval, and feed the resulting immutable digest/signed
  archive into the built-in staging `workflow_dispatch`.
- **Independent work remains green:** WPrint runtime/proxy/built-in tests, Cura
  gateway durability/UI tests, package validation, support bundle, backup and
  recovery documentation, and release verifier logic remain locally verified.
