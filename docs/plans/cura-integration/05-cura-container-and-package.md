# Cura Runtime Container and W3DP Packaging Plan

**Goal:** Produce two synchronized release artifacts from `cura-web-ui`: a multi-architecture OCI runtime image containing the gateway and a real pinned CuraEngine, and a signed `.w3dp` containing the compiled UI plus the digest-pinned image declaration.

**Primary repository:** `../cura-web-ui`.

**Cross-repository tooling:** Use `wprint3d-core`'s revision-5 validator/packager from plans 02–03. Do not implement a second incompatible `.w3dp` signing format in JavaScript or Python.

## Artifact model

One release version produces:

```text
cura-web-ui vX.Y.Z
├── ghcr.io/wprint3d/cura-web-slicer:X.Y.Z
├── ghcr.io/wprint3d/cura-web-slicer:X.Y.Z@sha256:<multiarch-digest>
├── cura-web-ui-X.Y.Z.w3dp
├── cura-web-ui-X.Y.Z.w3dp.sha256
├── SBOM-runtime-X.Y.Z.spdx.json
├── provenance-runtime-X.Y.Z.intoto.jsonl
└── corresponding-source-X.Y.Z metadata/source archive links
```

The `.w3dp` manifest references the multi-architecture digest. Versioned tags are human-readable; the digest is authoritative.

## Task 1: Pin engine and resource inputs

**Files:**

- Create `engine.lock.json`.
- Create `scripts/verify-engine-lock.mjs` or Python equivalent.
- Modify `scripts/setup-curaengine.py` to read the lock for development.
- Create `docs/runtime-build.md`.
- Add tests for lock parsing/checksum enforcement.

### Step 1.1: Define lock schema

Record independently:

```json
{
  "schemaVersion": 1,
  "curaEngine": {
    "version": "5.12.1",
    "sourceRepository": "<official repository URL>",
    "sourceCommit": "<full commit SHA>",
    "sourceArchiveSha256": "<sha256>",
    "license": "AGPL-3.0-only-or-later-as-confirmed-upstream"
  },
  "curaResources": {
    "version": "5.12.1",
    "sourceRepository": "<official repository URL>",
    "sourceCommit": "<full commit SHA>",
    "sourceArchiveSha256": "<sha256>"
  },
  "buildDependencies": {
    "conanConfig": {
      "sourceCommit": "<full commit SHA>",
      "sourceArchiveSha256": "<sha256>"
    }
  },
  "platforms": {
    "linux/amd64": {"buildStrategy": "source"},
    "linux/arm64": {"buildStrategy": "source"}
  }
}
```

Use full immutable commits and verified archive checksums. Do not use `latest`, branch heads, unverified release URLs, or a locally cached AppImage as a release input.

The exact license identifier must be verified from the pinned upstream sources; do not copy this plan's descriptive placeholder into released metadata.

### Step 1.2: Require source parity across architectures

Build CuraEngine from the same pinned source commit for amd64 and arm64. The existing AppImage extraction remains a convenient development path on amd64 but is not the release path, because it does not solve arm64 parity or corresponding-source reproducibility.

### Step 1.3: Add a build feasibility gate

Before integrating the final Dockerfile, create a temporary CI/manual build on native or emulated amd64 and arm64 that:

1. fetches only locked sources;
2. installs the documented build dependencies;
3. builds CuraEngine through the upstream-supported CMake/Conan flow for the pinned revision;
4. runs `CuraEngine help`;
5. runs a real cube slice using the pinned resources;
6. records exact compiler/dependency versions;
7. verifies output contains layers and non-empty extrusion moves.

If arm64 cannot build, stop. Do not publish an amd64-only multiarch manifest, a mock arm64 image, QEMU-only runtime claim, or the basic-FDM fallback as CuraEngine.

### Step 1.4: Document upstream patches

If a patch is required:

- store it under `docker/runtime/patches/`;
- make it minimal and architecture-neutral where possible;
- record upstream issue/commit context;
- include it in corresponding source;
- test patched and unpatched behavior explicitly.

## Task 2: Build the production runtime image

**Files:**

- Create `docker/runtime/Dockerfile`.
- Create `docker/runtime/entrypoint.sh`.
- Create `docker/runtime/build-curaengine.sh`.
- Create `docker/runtime/healthcheck.py` or `services/slicer-gateway/app/healthcheck.py`.
- Create `.dockerignore`.
- Modify `services/slicer-gateway/pyproject.toml` for reproducible installation metadata.
- Add a container test script under `scripts/`.

### Step 2.1: Use explicit stages

Recommended stages:

1. `engine-source`: fetch/verify locked engine and resources.
2. `engine-builder`: compile CuraEngine and run build-time smoke checks.
3. `python-builder`: build a wheel for the gateway and lock/install Python dependencies.
4. `runtime`: copy only runtime libraries, engine binary/resources, gateway wheel, licenses, and metadata.

Do not copy the repository `.cache`, virtualenv, node modules, tests, `.git`, user data, or release signing keys.

### Step 2.2: Runtime filesystem and user

The final image must:

- run as UID/GID `10001:10001`;
- have a writable `/data` only when mounted;
- use `/tmp` only through host-provided tmpfs;
- listen on `0.0.0.0:9311`;
- expose but not publish port 9311;
- set `PYTHONDONTWRITEBYTECODE=1` and unbuffered logs;
- contain no package manager caches or compiler toolchain;
- work with a read-only root filesystem;
- handle TERM and forward it to Uvicorn/job runner cleanly;
- use one Uvicorn worker so in-process runner coordination is not duplicated;
- never mount or expect Docker socket, USB, host `/dev`, or WPrint storage.

The entrypoint validates required environment and volume writability before starting. It must not download CuraEngine or mutate the image root.

### Step 2.3: Runtime labels and metadata

Add OCI labels for:

- source repository/revision;
- Cura Web UI version;
- gateway API version;
- CuraEngine version/commit;
- Cura resources commit;
- licenses;
- build timestamp and revision;
- supported architecture.

Write matching `/usr/share/cura-web-slicer/build.json`. Gateway capabilities reads this file and exposes non-sensitive version data.

### Step 2.4: Health behavior

Separate:

- container/image self-check executable: verifies Python import, database/storage readiness when mounted, and `CuraEngine help` without requiring network auth;
- authenticated HTTP readiness: `/api/v1/health` validates gateway initialized, DB open, storage writable, worker alive, and engine discovered.

Health must not create a full slice on every probe.

### Step 2.5: Container tests

Test with read-only root, dropped capabilities, no-new-privileges, memory/CPU/PID limits, non-root user, mounted named volume, and tmpfs. Verify:

- container starts;
- health passes with token;
- `/data` files are owned/writable by UID 10001;
- root filesystem writes fail;
- no network-exposed port exists unless a test publishes it explicitly;
- auto-download remains off;
- restart preserves SQLite/jobs;
- TERM stops CuraEngine child process within grace;
- `docker inspect` shows the intended user/entrypoint/labels.

## Task 3: Add real multi-architecture slicing tests

**Files:**

- Create `scripts/container-real-slice-test.py`.
- Create `tests/fixtures/calibration-cube.stl` only if licensing/provenance is documented; otherwise generate a deterministic ASCII STL in the test script.
- Create or modify `.github/workflows/runtime-image.yml`.

### Step 3.1: Test sequence

For each platform:

1. create an isolated named volume;
2. start image with production settings and random bridge token;
3. upload deterministic cube through multipart API;
4. create job with upload ID and profile snapshot;
5. poll until terminal with bounded timeout;
6. require completed status;
7. download G-code and assert a minimum size, layer markers, movement, and extrusion;
8. request layer index and at least first/middle/last chunks;
9. restart container with same volume and verify job/artifacts persist;
10. create and cancel a second job;
11. remove container and test volume.

Do not compare complete G-code byte-for-byte across architectures unless upstream guarantees determinism. Compare semantic invariants and record estimates for drift monitoring.

### Step 3.2: Native versus emulated execution

Prefer native arm64 runners. QEMU build smoke is useful but not sufficient for release readiness because CuraEngine performance and native dependencies matter. Mark a release candidate blocked until native arm64 slicing passes.

### Step 3.3: Performance budget

Record, but initially do not hard-fail on, cube slice wall time, peak RSS, output size, and layer count. After several stable runs, establish architecture-specific regression thresholds with tolerance.

## Task 4: Make the plugin source manifest valid and generated

**Files:**

- Replace or create `packages/wprint3d-plugin/plugin/plugin.template.json`.
- Generate `packages/wprint3d-plugin/plugin/plugin.json` for development/release.
- Create `scripts/render-wprint3d-manifest.mjs`.
- Modify `packages/wprint3d-plugin/src/manifest.ts`.
- Modify `packages/wprint3d-plugin/src/manifest.test.ts`.
- Modify `packages/wprint3d-plugin/package.json`.
- Modify root `package.json`.
- Update `docs/wprint3d-integration.md`.

### Step 4.1: Eliminate the duplicate invalid contract

Current problems to remove:

- static `baseUrl` instead of `managedImageId`;
- nonexistent permissions such as `network.local`, `storage.plugin`, `package.registry`, `account.backup`, and `output.device`;
- action field `title` where WPrint requires `label`;
- webview extensions using `bundle` instead of required `url`;
- undeclared runtime image;
- declared `ui` asset directory absent from package;
- duplicate manifest object in TypeScript that can drift from JSON.

Use the revision-5 target shape from `01-architecture-and-contracts.md`. For the initial integration, declare one full-page workspace extension. Do not load the complete slicer app again inside printer panel and settings card surfaces.

### Step 4.2: Render development and release modes

The render script accepts explicit arguments:

```text
--mode development|release
--version X.Y.Z
--image ghcr.io/...:X.Y.Z
--digest sha256:...
--min-core-version X.Y.Z
--engine-version ...
--resource-revision ...
```

Development mode may use a local tag and unsigned signature placeholder for unpacked installation. Release mode must fail if:

- version is not exact SemVer;
- digest missing/invalid;
- image is `latest`, local, or unqualified;
- min core version missing;
- repository is dirty unless an explicit CI override identifies the build revision;
- generated manifest fails WPrint validator.

### Step 4.3: Make JSON authoritative

`manifest.ts` should export TypeScript types/helpers and load or validate the JSON fixture for tests rather than maintaining a hand-written duplicate object. The test parses `plugin/plugin.json` and asserts SDK identity, managed image reference, allowed permissions, workspace surface, assets, and absence of forbidden static bridge URLs.

### Step 4.4: Add WPrint validation test

In CI, validate the rendered manifest with the exact WPrint core revision that first supports SDK revision 5. Do not rely only on a locally recreated TypeScript interface.

## Task 5: Build and stage the browser UI inside the plugin

**Files:**

- Modify `apps/web/vite.config.ts`.
- Modify `apps/web/package.json` only for build scripts.
- Create `scripts/stage-wprint3d-plugin.mjs`.
- Create `packages/wprint3d-plugin/plugin/licenses/` source files/notices.
- Create generated `packages/wprint3d-plugin/plugin/metadata/build.json` during staging.
- Ensure `packages/wprint3d-plugin/plugin/ui/` is generated and ignored appropriately.
- Add staging tests.

### Step 5.1: Make Vite output relocatable

Set a relative asset base so WPrint can serve nested authenticated asset URLs. Verify `index.html` references `./assets/...`, not `/assets/...` and not a development server.

The production UI must contain no external CDN dependency and no source-map paths that reveal builder home directories. Decide whether source maps are excluded or published separately for trusted debugging.

### Step 5.2: Stage atomically

The staging script:

1. removes a temporary staging directory, not the live plugin tree;
2. runs/assumes a successful web build;
3. copies `apps/web/dist` into staged `ui`;
4. copies license/notices;
5. writes build metadata with repository commit and versions;
6. renders the manifest with the image digest;
7. calculates a preliminary integrity map for staging validation; the WPrint packager independently recalculates it and refuses a mismatch before signing;
8. atomically replaces generated plugin staging output;
9. refuses node modules, `.env`, caches, test output, or secrets.

Do not use ad-hoc shell `cp` commands in release workflow when this script can define the exact file set.

### Step 5.3: Test package contents

Assert:

- `ui/index.html` exists;
- every referenced UI asset exists;
- no absolute Vite paths;
- no development gateway URL/token literal in the production bundle except harmless documentation strings explicitly reviewed;
- no forbidden directories/files;
- build metadata matches manifest version/image digest;
- integrity map covers every declared asset file;
- package size is within an agreed budget.

## Task 6: Package and sign with the WPrint toolchain

**Files:**

- Create `.github/workflows/release.yml` or extend an existing release workflow.
- Add release helper scripts under `scripts/`.
- Coordinate with the WPrint staging tool from `03-wprint-runtime-proxy-and-builtins.md`.

### Step 6.1: Release order is fixed

1. verify source tree and contracts;
2. build/test platform images;
3. push versioned multiarch image;
4. obtain registry multiarch digest;
5. verify digest resolves to both required platform manifests;
6. render/stage plugin with exact digest;
7. validate via WPrint SDK revision-5 validator;
8. package and sign `.w3dp` using WPrint's official packager;
9. verify signature and asset integrity using a clean WPrint verifier;
10. produce archive checksum/SBOM/provenance;
11. create release assets;
12. update official plugin registry metadata;
13. make archive available to the WPrint core release pipeline for built-in staging.

Never package before the final multiarch digest exists.

### Step 6.2: Use the WPrint packager, not a clone

Until a dedicated plugin-tools image exists, CI may:

1. check out `wprint3d-core` at a pinned commit supporting revision 5 into a temporary directory;
2. copy the staged plugin source into that checkout's `plugins/cura-web-ui` development path;
3. run the core's `plugin:pack`/verify commands inside its test or development container;
4. mount the signing key as an ephemeral read-only secret;
5. copy only the resulting `.w3dp` out;
6. destroy the temporary checkout/container/secret mount.

Record the WPrint packager commit in build provenance. Do not use an unpinned main branch.

### Step 6.3: Signing safety

- signing key exists only in protected release jobs;
- pull-request jobs never receive it;
- passphrase comes from an ephemeral file/secret, never CLI logs;
- unsigned packages cannot be published as official/built-in;
- CI verifies the embedded signer key/fingerprint matches the official trusted key;
- key rotation follows WPrint registry continuity rules.

### Step 6.4: Release reproducibility

Use stable ordering/timestamps where WPrint packager supports it. If byte-identical `.w3dp` reproduction is not yet possible, at least make manifest, UI build, image digest, integrity hashes, lock inputs, and provenance deterministic and explain remaining ZIP metadata variance.

## Task 7: Licensing, SBOM, and corresponding source

**Files:**

- Create/update `THIRD_PARTY_NOTICES.md`.
- Create `packages/wprint3d-plugin/plugin/licenses/THIRD_PARTY_NOTICES.md`.
- Include exact upstream licenses in the runtime image under `/usr/share/licenses/cura-web-slicer/`.
- Add SBOM/provenance generation to release workflow.
- Document corresponding-source publication in `docs/runtime-build.md`.

### Step 7.1: Separate-process boundary documentation

Document that WPrint communicates with the Cura gateway/engine through HTTP/process boundaries. Do not present that statement as a substitute for license compliance. Release must still provide all notices and corresponding source obligations for the distributed runtime image.

### Step 7.2: Corresponding source set

Publish or link an immutable source set containing:

- exact CuraEngine and resource sources;
- patches;
- build scripts and Dockerfile;
- gateway source;
- dependency lockfiles/recipes needed to rebuild;
- license texts;
- build provenance.

### Step 7.3: SBOM scan gate

Generate an SPDX or CycloneDX SBOM for the final runtime image. Scan for known critical vulnerabilities, secrets, and unexpected packages. Define a documented exception process; do not silently ignore scanner failure. The Conan configuration used by the source build is a locked build dependency, not a moving branch.

## Phase verification

Run source verification:

```bash
rtk proxy pnpm verify
```

Then run:

- runtime image build for amd64 and arm64;
- native real-slice test on both;
- read-only/non-root/security container test;
- restart/recovery/cancellation test;
- manifest render in release mode;
- WPrint validator and package/sign/verify round trip;
- archive content/integrity audit;
- SBOM/provenance generation;
- corresponding-source link validation.

The phase is complete only when the OCI digest and `.w3dp` are synchronized, signed, multiarch-tested, and reproducible from pinned inputs. A working local AppImage extraction is not sufficient evidence.
