# Embedded Cura UI Integration Plan

**Goal:** Make the existing Cura Web UI operate as a first-class WPrint workspace page, using WPrint authentication, theme, printer context, runtime proxy, durable upload/job API, and server-side G-code import while retaining standalone brand-neutral behavior.

**Repositories:** Primarily `../cura-web-ui`, with small generic host-context additions in `wprint3d-core`.

**Prerequisites:**

- runtime proxy and workspace presentation from plan 03;
- durable upload/job API from plan 04;
- staged valid plugin from plan 05.

## UI principles

- Embedded mode remains recognizably the Cura-style slicing workspace but fits WPrint's Material 3 shell and density.
- WPrint's app bar, page tab, authentication, printer selection, file library, and print controls remain the outer product shell.
- Embedded mode must not show a second account system, fake output device, standalone gateway URL/token controls, or a duplicate WPrint printer monitor.
- Standalone mode remains brand-neutral and retains real standalone-only configuration.
- Every visible control must work. Remove or hide unsupported entries rather than displaying disabled theater.
- Use semantic WPrint theme tokens passed by the host. Do not hardcode a second WPrint-specific palette.

## Task 1: Create a typed host-context parser

**Files:**

- Create `../cura-web-ui/apps/web/src/host/hostContext.ts`.
- Create `../cura-web-ui/apps/web/src/host/hostContext.test.ts`.
- Create `../cura-web-ui/apps/web/src/host/themeBridge.ts`.
- Create `../cura-web-ui/apps/web/src/host/themeBridge.test.ts`.
- Modify `../cura-web-ui/apps/web/src/main.tsx` only to initialize context cleanly.

### Step 1.1: Define the type

```ts
type HostMode = "standalone" | "wprint3d";

interface WPrintHostContext {
  mode: "wprint3d";
  pluginId: string;
  extensionId: string;
  runtimeBase: string;
  artifactImportBase: string;
  pluginApiBase: string;
  currentPrinterId?: string;
  locale: string;
  fallbackLocale: string;
  realtimeTransport: "polling";
  theme: WPrintSemanticTheme;
}
```

Standalone context contains the environment gateway URL/token and selectable WebSocket behavior.

### Step 1.2: Parse defensively

Tests must prove:

- missing `hostMode` selects standalone;
- only `hostMode=wprint3d` selects embedded;
- runtime/artifact/plugin API bases must be same-origin relative paths beginning with `/backend/api/plugins/<exact encoded plugin id>/`;
- absolute, scheme-relative, JavaScript, data, backslash, traversal, or foreign plugin paths reject embedded initialization;
- no token query parameter is accepted in embedded mode;
- malformed theme JSON falls back to safe semantic defaults and reports a nonfatal diagnostic;
- valid token colors/elevation values normalize to CSS variables;
- current printer ID and locale receive length/character bounds;
- query values are read once and do not change job ownership during a running session.

### Step 1.3: Apply semantic CSS variables

Map host theme roles to variables such as:

```text
--host-background
--host-on-background
--host-surface
--host-on-surface
--host-surface-variant
--host-on-surface-variant
--host-primary
--host-on-primary
--host-outline
--host-outline-variant
--host-error
--host-on-error
--host-success
--host-warning
```

Do not assume optional theme roles exist. Compute only bounded, safe values; never insert arbitrary CSS text from a query parameter.

Add `color-scheme: light|dark` based on semantic luminance or an explicit host field when plan 03 adds one.

## Task 2: Add a generic WPrint host-context endpoint

**Files in `wprint3d-core`:**

- Create `app/Http/Controllers/PluginHostContextController.php`.
- Modify `routes/api.php`.
- Modify `app/Plugins/PluginManifestValidator.php` only if a host-context declaration is required.
- Create `tests/Feature/Plugins/PluginHostContextTest.php`.
- Modify `frontend/components/PluginHostRenderer.js` to include the endpoint base if not already derivable.

### Step 2.1: Define endpoint

```text
GET /plugins/{pluginId}/host-context?printerId=<optional>
```

Return only permission-filtered data:

```json
{
  "coreVersion": "...",
  "plugin": {"id": "cura-web-ui", "version": "..."},
  "user": {"id": "...", "locale": "es-AR"},
  "printer": {
    "id": "...",
    "displayName": "...",
    "connected": true,
    "connectionStatus": "online",
    "machineType": "...",
    "extruderCount": 1
  },
  "capabilities": {
    "runtimeProxy": true,
    "gcodeArtifactImport": true,
    "directPrint": false
  }
}
```

Omit printer fields if none is selected. Require `printer.read` before returning printer data. Do not expose serial node, camera URLs, queued commands, raw machine document, other users, Docker state, storage paths, or tokens.

### Step 2.2: Test authorization

Cover unauthenticated, disabled plugin, missing permission, inaccessible/unknown printer, active printer, explicitly matching printer, and no sensitive keys recursively present.

### Step 2.3: Update Cura manifest permission

Add `printer.read` only when the UI consumes this endpoint. Do not add `printer.command.queue` in the first release because one-click print is explicitly deferred.

## Task 3: Centralize runtime transport selection

**Files:**

- Create `../cura-web-ui/apps/web/src/transport/createSlicerTransport.ts`.
- Create `../cura-web-ui/apps/web/src/transport/createSlicerTransport.test.ts`.
- Modify `../cura-web-ui/apps/web/src/App.tsx`.
- Modify `../cura-web-ui/packages/engine-api/src/http.ts` as required by plan 04.

### Step 3.1: Remove direct default construction from `App.tsx`

Current `App.tsx` directly defaults to `http://127.0.0.1:9311` and `dev-token`. Replace this with one factory:

- embedded: base URL is the same-origin `pluginRuntimeBase`, no token, polling events, credentials same-origin;
- standalone: base URL/token from environment or standalone settings, WebSocket events when configured.

The embedded connection settings UI must not be able to override the host-provided base.

### Step 3.2: Fetch behavior tests

Assert embedded requests:

- target `/backend/api/plugins/cura-web-ui/runtime/api/v1/...`;
- include same-origin cookies through normal fetch credential behavior;
- contain no Authorization token generated by Cura UI;
- use multipart upload without a manually set boundary;
- use polling, never construct a `ws://cura-web-slicer` URL.

Assert standalone behavior remains functional.

## Task 4: Replace base64 scene submission with upload IDs

**Files:**

- Modify `../cura-web-ui/apps/web/src/App.tsx`.
- Modify `../cura-web-ui/apps/web/src/lib/sceneDocument.ts`.
- Create `../cura-web-ui/apps/web/src/lib/modelUploads.ts`.
- Create `../cura-web-ui/apps/web/src/lib/modelUploads.test.ts`.
- Modify file reader tests and slice-workflow tests.

### Step 4.1: Define scene source state

A scene model keeps browser-preview bytes/blob separately from durable gateway upload state:

```ts
interface SceneModelSource {
  blob: Blob;
  fileName: string;
  mimeType: string;
  sizeBytes: number;
  contentFingerprint?: string;
  upload?: {
    id: string;
    sha256: string;
    gatewayIdentity: string;
  };
}
```

Do not serialize a Blob into workspace JSON. Workspace export follows an explicit archive format or preserves the existing supported behavior without pretending bytes are present.

### Step 4.2: Upload coordinator

Before job creation:

1. collect unique source blobs used by printable scene models;
2. reuse an upload only when gateway identity and content fingerprint match;
3. upload missing sources with bounded parallelism, default two;
4. expose per-file and aggregate progress where browser APIs allow it;
5. allow cancellation through `AbortController`;
6. build job models with upload IDs and transforms/roles;
7. submit one idempotent job request.

Duplicated scene objects that share the same immutable source may share one upload ID. Modified mesh bytes require a new upload. Transform-only changes do not.

### Step 4.3: User feedback

The Slice button states must be distinct:

```text
Preparing models
Uploading 1 of N
Submitting job
Queued
Slicing N%
Generating preview
Ready
Cancelling
Failed
```

Disable duplicate submission while active. Preserve layout width to avoid button jumps.

### Step 4.4: Tests

Cover shared uploads for duplicates, reupload after source change, no reupload after transform change, partial upload failure, abort, idempotency-key reuse after ambiguous network failure, and cleanup of unclaimed upload where supported.

## Task 5: Consume durable job polling and recovery

**Files:**

- Modify `../cura-web-ui/apps/web/src/lib/sliceWorkflow.ts` and tests.
- Modify `../cura-web-ui/apps/web/src/App.tsx`.
- Create a focused hook such as `apps/web/src/hooks/useSliceJob.ts` plus tests if extracting state reduces the monolith safely.

### Step 5.1: Store active job identity

Persist only safe resumable state in browser storage:

```text
gateway identity
job ID
request fingerprint
created timestamp
```

On embedded page reload:

1. read active job identity;
2. query through WPrint proxy;
3. resume polling if active;
4. load artifacts if completed;
5. clear local state if `404`/expired;
6. do not automatically create a replacement job.

Never persist bearer tokens or raw model bytes in local storage.

### Step 5.2: Map all states

Handle queued, retry-queued, preparing, processing, post-processing, cancelling, cancelled, completed, and failed explicitly. Unknown future state shows a compatibility error rather than being treated as completed.

For retry-queued, tell the user the slicer restarted and the job will retry. Show attempt count only when useful; do not expose internal stack traces.

### Step 5.3: Cancellation UI

Cancel is visible for active jobs, requires no destructive modal for ordinary slicing cancellation, becomes disabled while request is in flight, and remains in “Cancelling” until the server reaches terminal state. A timeout offers Retry status/Force reload, not a fake local cancellation.

### Step 5.4: Preview loading

After completion:

- load layer index first;
- lazily fetch chunks;
- preserve cumulative-layer behavior;
- abort obsolete layer requests when user moves slider rapidly;
- cache bounded recent chunks;
- show artifact-expired state with a re-slice action;
- never silently fall back to CSS/mock preview.

## Task 6: Define embedded feature policy and remove duplicate chrome

**Files:**

- Create `../cura-web-ui/apps/web/src/host/embeddedFeaturePolicy.ts` and tests.
- Modify `../cura-web-ui/apps/web/src/App.tsx`.
- Modify `../cura-web-ui/apps/web/src/styles.css`.
- Update affected E2E tests.

### Step 6.1: Feature matrix

Use one policy object, not scattered `if (embedded)` conditions:

| Feature | Standalone | WPrint embedded |
| --- | --- | --- |
| Application/product outer header | Show neutral standalone header | Hide; WPrint owns app bar/tab |
| Gateway URL/token preferences | Show | Hide |
| Standalone account/backups | Show only when real | Hide or route to WPrint settings later |
| Gateway output-device monitor | Show only when real | Hide |
| Fake local preview printer | Development only | Never show |
| Cura package registry | Show when gateway-backed | Hide initially; WPrint Marketplace owns plugins |
| Printer/material/profile editing | Show | Show slicer-specific resources |
| File/import/workspace tools | Show | Show |
| Prepare/Preview | Show | Show |
| Monitor stage | Standalone real output only | Hide; WPrint owns printer monitoring |
| Save/download G-code | Show | Replace primary action with Import to WPrint |
| Help/about | Show | Show with host/runtime versions |

### Step 6.2: No placeholder behavior

Audit every menu and modal under embedded policy. Each item must either:

- work through the runtime;
- work locally on browser scene state;
- invoke an implemented host API;
- be omitted.

Do not leave fake accounts, seeded output devices, package install demos, or pretend printer control visible.

### Step 6.3: Preserve standalone neutrality

Do not hardcode “WPrint” into normal standalone UI. WPrint-specific strings render only when host mode is WPrint and should use localized resources.

## Task 7: Integrate WPrint theme, density, and layout

**Files:**

- Modify `../cura-web-ui/apps/web/src/styles.css`.
- Modify relevant JSX structure in `App.tsx` and `ThreeViewport.tsx` only where required for fill layout/accessibility.
- Add visual regression screenshots/documentation under `../cura-web-ui/docs`.

Before editing, compare the rendered embedded page against WPrint's current Terminal, Preview, Control, file list, Settings, and plugin page in both themes. WPrint `DESIGN.md` is authoritative for the host shell.

### Step 7.1: Token usage

In embedded mode replace page-level colors with semantic host variables. Keep line-feature colors needed for slicing preview, but ensure their legends and selected states remain readable in both themes.

Avoid gradients, glass effects, large marketing headings, custom web fonts, and ornamental cards. Use the existing compact Cura workspace hierarchy within WPrint surfaces.

### Step 7.2: Fill and scroll contract

- document/body/root/app: `height: 100%`, `min-height: 0`;
- workspace main region: flex and bounded by iframe;
- settings sidebar and viewport own their scroll/overflow;
- no document-level horizontal scroll;
- no controls hidden behind WPrint bottom navigation;
- no nested full-page header consuming duplicate vertical space.

### Step 7.3: Theme changes

Initial query-param theme may reload the iframe when WPrint switches theme. Prefer adding a validated `postMessage` theme update so the scene/job state is not lost:

1. WPrint frame sends `{type: "wprint3d.host.theme", pluginId, theme}` to the exact iframe window/origin.
2. Cura validates event origin, source window, type, plugin ID, and theme shape.
3. Apply variables without reload.

Do not add wildcard `postMessage("*", ...)` for sensitive messages.

### Step 7.4: Required visual states

Verify:

- no models;
- model loaded;
- upload in progress;
- queued/slicing;
- completed preview;
- gateway starting/unavailable;
- plugin failed;
- artifact expired;
- cancellation;
- validation/unsupported feature error;
- storage full;
- no active WPrint printer;
- active offline printer (slicing still allowed, but printing is not implied).

## Task 8: Add localization infrastructure for embedded host copy

**Files:**

- Create or extend a locale module in `../cura-web-ui/apps/web/src/i18n`.
- Add initial `en` and `es` resources.
- Modify host-specific and new job/upload strings.
- Add tests for locale selection/fallback and missing keys.

Use `locale` and `fallbackLocale` from trusted parsed host context. Normalize `es_AR`/`es-AR` to the supported `es` catalog until regional variants exist. Existing untranslated Cura UI strings can migrate incrementally, but all new integration/error/action strings in this plan must use the locale system.

Do not concatenate sentence fragments that break translation. Allow label expansion without fixed-width clipping.

## Task 9: Import completed G-code into WPrint

**Files:**

- Create `../cura-web-ui/apps/web/src/host/wprintArtifactClient.ts` and tests.
- Modify completion/footer UI in `App.tsx`.
- Modify embedded E2E tests.

### Step 9.1: Primary completion actions

Embedded completed state offers:

- `Save to WPrint` as primary action;
- filename input/default based on original project/model, sanitized client-side for feedback but authoritative validation remains server-side;
- optional target directory selection or default `Sliced`;
- `Download G-code` as secondary only if product policy wants it;
- no direct `Print now` in first release.

### Step 9.2: Call host importer

POST to:

```text
<artifactImportBase>/gcode
```

with the job's relative G-code artifact path. Never fetch the G-code blob into browser memory for the WPrint import flow.

### Step 9.3: Handle outcomes

- success: stable success surface with normalized file name and guidance to WPrint Files;
- collision: offer edit name and retry, never overwrite silently;
- expired: show re-slice;
- storage full: explain cleanup/import alternative;
- plugin/runtime unavailable: retain completed local job identity and allow retry after recovery;
- repeated click after success: idempotently show imported target or prevent duplicate.

If importer idempotency is added, key it by plugin/job/artifact SHA and target path.

### Step 9.4: Refresh host file list

Because the plugin runs in an iframe, notify WPrint after successful import with a non-sensitive validated message:

```json
{
  "type": "wprint3d.plugin.gcode-imported",
  "pluginId": "cura-web-ui",
  "path": "Sliced/part.gcode"
}
```

WPrint verifies source frame/origin/plugin ID, invalidates the file-list React Query cache, and optionally shows its standard snackbar. The host remains authoritative; do not trust the message as proof of import without the successful server response.

## Task 10: Add accessibility and interaction tests

**Files:**

- Modify/add Vitest DOM tests.
- Modify/add Playwright tests under `../cura-web-ui/apps/web/tests`.
- Modify WPrint frontend tests for frame presentation/messages.

Cover:

- keyboard traversal through menu, viewport toolbar, settings, Slice/Cancel/Save actions;
- visible focus in both themes;
- accessible names for icon-only camera/model actions;
- progress announcements through polite live regions without announcing every 1% update;
- error alert semantics;
- focus return after dialogs;
- Escape behavior for menus/modals without cancelling an active slice unexpectedly;
- minimum touch targets;
- zoom/enlarged text without hidden actions;
- reduced-motion behavior;
- long Spanish labels;
- screen-reader distinction between queued, slicing, cancelling, failed, and completed.

## Task 11: Cross-application E2E flow

**Files:**

- Add a WPrint-integrated Playwright suite in the most maintainable repository; document why it lives there.
- Add helpers that install the unpacked plugin and run the real runtime container.
- Update `../cura-web-ui/docs/wprint3d-integration.md` with screenshots and commands.

Required E2E sequence:

1. start WPrint development stack;
2. build/start real Cura sidecar image with managed-plugin lifecycle;
3. install/enable unpacked development plugin through WPrint;
4. log into WPrint;
5. select Cura plugin page;
6. verify no duplicate standalone header/gateway token controls;
7. upload a real STL through runtime proxy;
8. start slice and observe real progress;
9. reload page mid-job and resume;
10. preview real layers;
11. import G-code server-to-server;
12. verify file appears in WPrint file list;
13. switch light/dark without losing scene/job state;
14. disable plugin and verify unavailable UI/runtime stop;
15. re-enable and verify durable completed job remains;
16. exercise cancellation on a second job;
17. verify another user cannot see the first user's jobs.

No mock adapter is allowed in the release-gate version of this flow.

## Phase verification

From `../cura-web-ui`:

```bash
rtk proxy pnpm verify
rtk proxy pnpm test:e2e
```

From `wprint3d-core`:

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Feature/Plugins/PluginHostContextTest.php \
  tests/Feature/Plugins/PluginRuntimeProxyTest.php \
  tests/Feature/Plugins/PluginRuntimeArtifactImportTest.php

cd frontend
pnpm exec expo export -p web
```

Then complete the visual matrix from `DESIGN.md` in both themes.

The phase is complete only when embedded mode has no direct sidecar URL/token, no base64 slice submission, no duplicate/fake host features, no browser G-code round-trip, and no layout/accessibility regressions across required breakpoints.
