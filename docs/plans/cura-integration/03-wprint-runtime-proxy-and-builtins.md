# WPrint Runtime Proxy and Built-in Plugin Plan

**Goal:** Let an authenticated, WPrint-served custom bundle use its managed bridge without learning internal Docker credentials, import a completed G-code artifact server-to-server, and ship the signed `.w3dp` as a built-in package through the same plugin installer used everywhere else.

**Repository:** `wprint3d-core`.

**Prerequisite:** `02-wprint-managed-runtime-hardening.md` is green.

## Task 1: Create an authenticated managed-runtime HTTP client

**Files:**

- Create `app/Plugins/Runtime/ManagedPluginHttpClient.php`.
- Create `app/Plugins/Runtime/ManagedPluginRequestContext.php`.
- Create `app/Plugins/Runtime/ManagedPluginResponse.php` if needed for streaming.
- Modify `app/Plugins/Runtimes/BridgePluginRuntimeAdapter.php` to reuse the client.
- Create `tests/Unit/Plugins/Runtime/ManagedPluginHttpClientTest.php`.

### Step 1.1: Define resolution rules

The HTTP client receives a serialized installed plugin, never a caller-supplied base URL. It must:

1. require an installed managed bridge;
2. obtain `baseUrl` from observed `dependency_state.runtime.baseUrl` after reconciliation;
3. decrypt the stored bearer token;
4. construct the final URL by joining a validated relative path;
5. reject absolute URLs, scheme-relative paths, backslashes, NULs, and traversal;
6. attach the bearer token and trusted host-context headers;
7. reject redirects;
8. use separate JSON and streaming timeout policies;
9. redact token values and query strings from errors where they can contain user data.

Static bridge plugins continue to use their existing adapter path and are not automatically eligible for browser proxying.

### Step 1.2: Write failing tests

Use a fake HTTP handler and secret store. Cover:

- correct resolved internal URL;
- bearer token attached exactly once;
- browser-supplied `Authorization` and `X-WPrint-*` headers removed;
- authenticated user ID, validated printer ID, locale, plugin ID, and request ID injected;
- invalid active printer ID omitted rather than trusted;
- redirects rejected;
- path outside signed proxy prefix rejected;
- method outside manifest allowlist rejected;
- ordinary timeout versus stream timeout;
- disabled/failed plugin rejected;
- stale dependency state causes reconciliation or a `503`, never a fallback to manifest `baseUrl`;
- no secret in exception text or logs.

### Step 1.3: Implement with streaming support

Use the existing Guzzle/Laravel HTTP stack but preserve PSR-7 streams. Do not convert uploads, G-code, logs, or layer payloads into one giant PHP string.

Expose focused methods such as:

```text
requestJson(plugin, method, path, context, body)
forward(plugin, serverRequest, path, context)
streamTo(plugin, method, path, context, destinationStream, limits)
```

Do not expose a method that accepts an arbitrary URL.

### Step 1.4: Reuse in bridge actions and healthchecks

For managed runtimes, `BridgePluginRuntimeAdapter` uses the new client so actions, hooks, and healthchecks receive the same token behavior. Static bridges remain backward-compatible.

### Step 1.5: Verify

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins/Runtime/ManagedPluginHttpClientTest.php \
  tests/Unit/Plugins/PluginManagerServiceTest.php
```

## Task 2: Add the same-origin runtime proxy

**Files:**

- Create `app/Http/Controllers/PluginRuntimeProxyController.php`.
- Create `app/Http/Requests/PluginRuntimeProxyRequest.php` only if it helps isolate validation.
- Modify `routes/api.php`.
- Modify `config/plugins.php`.
- Create `tests/Feature/Plugins/PluginRuntimeProxyTest.php`.
- Modify proxy/nginx request-size configuration only if the existing authenticated backend upload path cannot carry the configured maximum.

### Step 2.1: Add one explicit catch-all route

Under the existing authenticated plugin route group:

```text
GET|POST|PUT|PATCH|DELETE
/plugins/{pluginId}/runtime/{runtimePath}
```

Keep this route after the more-specific `assets`, `settings`, `state`, `logs`, and `actions` routes. Apply the same authentication/native/password middleware as existing plugin routes.

Do not expose this route to unauthenticated startup pages or public API tokens without the existing native API policy.

### Step 2.2: Write authorization tests

Cover:

- unauthenticated request rejected;
- unknown plugin `404`;
- disabled or failed plugin `503` with stable error code;
- non-managed or non-bridge plugin rejected;
- revision below 5 or missing `runtime.httpProxy` rejected;
- allowed method/path forwarded;
- disallowed method/path rejected before any sidecar call;
- path traversal and encoded traversal rejected after one and repeated decoding;
- current printer context is injected only if accessible to the authenticated user;
- plugin token/internal URL absent from response headers/body;
- upstream `Set-Cookie`, `Location`, connection, transfer-encoding, and other hop-by-hop headers stripped;
- safe content type, content length, ETag, cache control, and request ID preserved where appropriate;
- upstream 4xx/5xx status and safe body forwarded without turning every error into `500`.

### Step 2.3: Implement body forwarding modes

Support these request types explicitly:

1. JSON: stream or pass the raw JSON body and content type.
2. Multipart upload: preserve uploaded file bytes, filename, MIME type, and non-file fields without loading the complete file into memory.
3. Empty POST/DELETE: forward no body.
4. Binary response: stream.
5. JSON response: it may still stream; do not require decoding unless error sanitization needs it.

Enforce both declared and host upload limits. Reject early using `Content-Length` when available, and enforce a streaming byte counter when it is not.

Do not proxy WebSocket upgrade requests in revision 5. Return a clear `426` or `400` code that tells the embedded client to use polling.

### Step 2.4: Define timeout selection

- normal metadata request: `runtime.httpProxy.requestTimeoutSecs`;
- upload/artifact streaming route: `streamTimeoutSecs`;
- healthcheck: plugin runtime health timeout;
- never allow a manifest to select an unbounded timeout.

Client disconnect should cancel the upstream proxy request where supported, but it must not automatically cancel a previously accepted slice job.

### Step 2.5: Add abuse controls

Use existing Laravel rate-limiting primitives with different buckets:

- metadata and polling requests: moderate per-user/per-plugin rate;
- uploads and job creation: low concurrent rate;
- artifact downloads: low concurrent rate but not an artificially tiny byte rate.

Return `429` with `Retry-After`. Do not rate-limit healthchecks performed by the host lifecycle manager through the browser bucket.

### Step 2.6: Verify

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Feature/Plugins/PluginRuntimeProxyTest.php
```

Add a memory-oriented integration test with a generated file larger than normal PHP memory-safe unit payloads. Verify peak application memory does not scale linearly with the full file size within a generous tolerance.

## Task 3: Add server-to-server G-code import

**Files:**

- Create `app/Http/Controllers/PluginRuntimeArtifactController.php`.
- Create `app/Plugins/Runtime/PluginGcodeArtifactImporter.php`.
- Modify `routes/api.php`.
- Modify `app/Plugins/PluginManifestValidator.php` only if Task 1 left artifact normalization incomplete.
- Create `tests/Unit/Plugins/Runtime/PluginGcodeArtifactImporterTest.php`.
- Create `tests/Feature/Plugins/PluginRuntimeArtifactImportTest.php`.
- Reuse existing filename/path helpers or extract them from `app/Http/Controllers/UserController.php` instead of duplicating inconsistent rules.

### Step 3.1: Define route and input

```text
POST /plugins/{pluginId}/runtime-artifacts/{importId}
```

JSON:

```json
{
  "runtimePath": "/api/v1/jobs/job_abcd1234/gcode",
  "fileName": "part.gcode",
  "subDirectory": "Sliced"
}
```

The `{importId}` resolves one signed `runtime.artifactImports[]` declaration. It is not a sidecar path or arbitrary storage backend.

### Step 3.2: Write failing tests

Cover:

- permission `storage.write` required;
- runtime path must match the anchored signed regex;
- URL, query-string escape, traversal, encoded traversal, and newline injection rejected;
- only allowed upstream content types accepted;
- missing/invalid content length still constrained by streamed byte limit;
- upstream redirect rejected;
- sanitized filename must end in an accepted G-code extension;
- empty, dot, path-separator, control-character, and reserved names rejected;
- existing target produces `409` and is not overwritten;
- nested directory is normalized using existing file-library rules;
- successful stream writes a temporary object then atomically renames it;
- failure removes temporary data;
- imported file appears through `FilesController::index`;
- sidecar auth token and URL never appear in response;
- another user's job returns the sidecar's authorization failure and imports nothing.

### Step 3.3: Implement streaming and validation

Use `Storage::disk('gcode')` and a unique temporary name inside a non-user-visible temp directory on the same disk. Count bytes while copying. Optionally inspect the beginning for plausible text/G-code markers, but do not reject valid vendor headers merely because the first command differs.

Return:

```json
{
  "name": "Sliced/part.gcode",
  "sizeBytes": 123456,
  "sha256": "...",
  "importedAt": "..."
}
```

Compute SHA-256 during the stream; do not re-read the whole file solely for hashing.

### Step 3.4: Add lifecycle logs

Record success/failure with plugin ID, authenticated user ID, job path identifier, normalized target name, size, and request ID. Do not log the bearer token, full internal URL, model names if privacy policy treats them as sensitive, or G-code contents.

### Step 3.5: Verify

Run unit and feature importer tests, then use a fake streaming bridge to import a multi-megabyte generated G-code file into an isolated test disk.

## Task 4: Add workspace custom-bundle presentation and host context

**Files:**

- Modify `app/Plugins/PluginManifestValidator.php`.
- Modify `app/Plugins/PluginManagerService.php` if normalized presentation/context must be serialized.
- Modify `frontend/components/PluginHostRenderer.js`.
- Modify `frontend/components/UserRightPane.js`.
- Modify `frontend/components/UserMobileLayout.js`.
- Modify/add focused frontend tests under `frontend/tests`.
- Modify `docs/plugin-sdk-reference.md`.

This is a UI/UX task. Before editing, read `DESIGN.md` and inspect current page-plugin rendering adjacent to Terminal, Preview, Control, and Recordings at desktop and mobile breakpoints.

### Step 4.1: Write renderer tests

Cover:

- `presentation=workspace` is accepted only for `page/custom_bundle` revision 5;
- workspace bundle renders without the generic plugin card title, subtitle, and explanatory paragraph;
- iframe fills the owning page region with `width: 100%`, `height: 100%`, and `minHeight: 0` rather than a fixed 360 px card;
- ordinary custom bundles retain current card rendering;
- page navigation uses the extension's optional compact label and Material Community icon, with the title and puzzle icon as backward-compatible fallbacks;
- mobile scene does not overflow behind bottom navigation;
- embedded URL includes `hostMode`, `pluginRuntimeBase`, `pluginArtifactImportBase`, `currentPrinterId`, `locale`, theme, and `realtimeTransport=polling`;
- embedded URL never includes runtime bearer token/base URL/container state;
- missing active printer uses an empty context, not an invalid literal;
- frame has a stable accessible title based on plugin/extension title.

### Step 4.2: Extend URL construction

Update `buildEmbeddedUiUrl()` using relative same-origin paths only. Continue to pass existing settings/state/action helpers for other plugins. Add:

```text
hostMode=wprint3d
pluginRuntimeBase=/backend/api/plugins/<id>/runtime
pluginArtifactImportBase=/backend/api/plugins/<id>/runtime-artifacts
realtimeTransport=polling
```

Do not expose admin-only plugin diagnostics in the browser context.

### Step 4.3: Render a fill workspace

The full-page frame must inherit the owning pane's bounds. Ensure all ancestors use `flex: 1` and `minHeight: 0` where necessary. Scrolling belongs inside the Cura UI, not on the outer WPrint document.

Keep WPrint navigation, background, and pane framing. The embedded UI should not replace the WPrint app bar or mobile bottom navigation.

### Step 4.4: Consider iframe sandboxing explicitly

The current iframe has no `sandbox`. Do not casually add one globally because existing same-origin plugins may rely on access. For revision-5 workspace bundles:

- document the current trust implication;
- prefer `sandbox="allow-scripts allow-forms allow-downloads allow-same-origin"` only after tests prove required fetch/download behavior;
- do not include `allow-top-navigation`, popups, or pointer lock unless a documented slicer function requires them;
- if sandboxing is deferred, record it as a security follow-up and limit workspace presentation to signed/trusted plugins.

### Step 4.5: Visual verification

Verify the empty/loading/ready/failure frame states at:

- 375 × 812;
- 812 × 375;
- 768 px boundary;
- 1024 × 768;
- 1440 × 900;
- 1920 × 1080;
- light and dark themes.

Capture screenshots when the Cura UI exists; for this phase a lightweight fixture page is acceptable for host geometry testing.

## Task 5: Define and implement built-in package inventory

**Files:**

- Create `app/Plugins/Builtins/BuiltinPluginDescriptor.php`.
- Create `app/Plugins/Builtins/BuiltinPluginRepository.php`.
- Create `app/Plugins/Builtins/BuiltinPluginInstaller.php`.
- Create `app/Console/Commands/PluginInstallBuiltins.php`.
- Modify `config/plugins.php`.
- Modify `app/Console/Commands/BootstrapRuntime.php`.
- Create `resources/plugins/builtin/index.json`.
- Create `resources/plugins/builtin/archives/.gitkeep`.
- Modify `.gitignore` as needed so generated `.w3dp` archives are not accidentally committed.
- Add tests under `tests/Unit/Plugins/Builtins` and `tests/Feature/Plugins`.

### Step 5.1: Keep built-in status host-owned

A plugin manifest cannot declare itself built-in. The host inventory does:

```json
{
  "schemaVersion": 1,
  "plugins": [
    {
      "id": "cura-web-ui",
      "version": "0.2.0",
      "archive": "archives/cura-web-ui-0.2.0.w3dp",
      "sha256": "<archive-sha256>",
      "defaultEnabled": true,
      "required": false
    }
  ]
}
```

The release pipeline renders the real version/hash. The source repository may contain an empty inventory or a clearly non-release fixture, but no placeholder can enter a production image.

### Step 5.2: Write inventory validation tests

Reject:

- path traversal or absolute archive paths;
- missing archive;
- archive SHA mismatch;
- plugin ID/version mismatch between inventory and package;
- unsigned or untrusted built-in package;
- duplicate IDs;
- downgrade relative to a newer installed version;
- incompatible `minCoreVersion`;
- built-in inventory trying to mark itself required when policy disallows that plugin.

### Step 5.3: Define installation policy

On server bootstrap after migrations:

1. load inventory;
2. for each descriptor, validate local archive and SHA;
3. skip if installed version is newer;
4. skip if same version and runtime path is healthy/present;
5. install if absent;
6. update transactionally if bundled version is newer;
7. enable only on first install when `defaultEnabled=true`;
8. preserve a user's explicit disabled choice on subsequent WPrint upgrades;
9. continue WPrint bootstrap on optional plugin failure;
10. record a persistent actionable failure visible in plugin management.

Do not contact the Marketplace to locate the built-in package. Its archive is local. Image pull still follows the signed manifest.

### Step 5.4: Distinguish source and updates

Use install source type `builtin` with metadata for bundled core version and archive SHA. Automatic Marketplace updates may later replace it only from the official signed registry and only with a newer compatible version. A later WPrint bundle must never downgrade that newer version.

### Step 5.5: Avoid repeated pulls on normal startup

Installing the same version must be idempotent. Runtime reconciliation may inspect the local image/container, but it should not execute an unconditional registry pull when the exact digest is already available and the pull policy is `if-not-present`.

### Step 5.6: Bootstrap visibility

Use existing startup logging/progress facilities to report:

- verifying built-in plugins;
- installing/updating package;
- pulling runtime image;
- enabling runtime;
- optional failure and recovery action.

Do not hold the entire WPrint application unavailable forever. Apply configured pull/health timeouts and continue with the plugin marked failed.

### Step 5.7: Tests

Cover fresh install, idempotent reboot, user-disabled preservation, newer installed version preservation, bundled upgrade, failed image pull, failed candidate rollback, missing archive, bad SHA, bad signature, incompatible core, and optional failure not aborting bootstrap.

## Task 6: Add built-in release staging support

**Files:**

- Create `scripts/stage-builtin-plugin.sh` or an equivalent existing-style script.
- Modify the production Docker build workflow files that assemble the WPrint backend image.
- Modify `Dockerfile` only as required to copy the staged inventory/archive into the production image.
- Add shell tests following existing `tests/Unit/dockerfile-*.sh` patterns.
- Document the release input in `docs/plugins.md`.

### Step 6.1: Define script inputs

The staging script accepts explicit, validated values:

```text
--archive <path>
--expected-plugin-id cura-web-ui
--expected-version <semver>
--expected-sha256 <sha>
--destination resources/plugins/builtin/archives/...
```

It verifies the archive through the WPrint plugin verifier before staging it and renders `index.json` atomically. It must not download a mutable `latest` URL.

### Step 6.2: Keep source and generated artifacts separate

Do not commit production `.w3dp` binaries to this repository. CI downloads the exact signed release asset and stages it before `docker build`. The Docker image contains the staged archive after build.

Local development without a staged archive remains valid and uses unpacked plugins from the development mount.

### Step 6.3: Test production-image presence

Add a release verification command that starts the built backend image and checks:

- inventory parses;
- archive exists;
- archive SHA matches;
- package signature is trusted;
- plugin ID/version matches inventory.

Do not require starting the Cura sidecar in this static image-content test.

## Phase gate

Run all focused core tests from plans 02 and 03, then:

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins tests/Feature/Plugins

cd frontend
pnpm exec expo export -p web
```

Also run the actual streaming proxy/import fixture and the built-in installer against a signed local test `.w3dp`.

The phase is complete only when:

- browser requests are authenticated and forwarded without exposing internal credentials;
- large request/response bodies are streamed under enforced limits;
- only signed allowlisted runtime paths/methods are reachable;
- G-code imports directly from sidecar into WPrint storage and appears in the file list;
- workspace custom bundles fill the page without breaking ordinary plugin cards;
- built-in status comes from a host inventory;
- first install, reboot, bundled update, user disable, failure, and rollback behaviors are tested;
- a production backend image can prove it contains the exact staged signed `.w3dp`.
