# Plugin Development Guide

## Overview

WPrint 3D plugins are host-controlled extensions packaged as `.w3dp` archives or mounted live from source in the development stack. Every plugin is described by a single `plugin.json` manifest and can combine:

- a runtime shape: `php` or `bridge`
- a UI shape: `declarative`, `webview`, or `custom_bundle`
- a dependency footprint: `lightweight` or `heavyweight`
- one or more surfaces: `settings_tab`, `navbar_widget`, `printer_panel`, `printer_action`, `modal`, `page`

Use the lightest shape that fits the job. The platform is intentionally biased toward host-rendered UI and short-lived runtime handlers because many WPrint 3D installs run on low-memory SBCs.

## Lightweight vs Heavyweight

`lightweight`

- Declares no container images in the manifest.
- Installs without extra runtime dependencies.
- Best for host-rendered UI, PHP hooks, and small bridge adapters that already exist elsewhere.

`heavyweight`

- Declares one or more images in `plugin.json -> images`.
- WPrint 3D will pull those images during install/update.
- Optional healthcheck commands can be executed to prove the image is usable.
- Optional `requirements.memoryMb` and `requirements.cpuCores` let the host warn when the system is below the plugin's minimum target.

This is intentionally close to the host-managed add-on model used by systems like Home Assistant: the plugin declares its container dependencies, but WPrint 3D owns the pull, readiness, and trust UX.

## Choose A Shape

### Runtime

`php`

- Best default for small plugins.
- Runs short-lived PHP handlers inside the core environment.
- Good for lightweight actions, hooks, and host-owned UI.

`bridge`

- Use when your plugin already has its own service process or non-PHP stack.
- WPrint 3D calls the external service over HTTP.
- Good for integrations, heavy processing, or codebases that should stay outside the main PHP runtime.

### UI

`declarative`

- Best default.
- Host-rendered and theme-native.
- Recommended for settings tabs, cards, lists, forms, buttons, and compact metrics.
- Supports a stable `host.*` component registry plus `host.remote_component`, a reusable host-rendered component kind that works on web and native.

`webview`

- Isolated HTML surface.
- Use when you need custom layout, richer typography, or browser-native APIs.
- Prefer asset-backed HTML served by WPrint 3D rather than remote public URLs.

`custom_bundle`

- Elevated UI path for heavier assets or richer client logic.
- Same isolation model as WebView in the current host renderer, but treated as a more privileged UI mode.
- Use sparingly and document why the declarative path is insufficient.

## About The `components` Field

Manifest-declared components now support two distinct execution models:

- `remote_component` for host-rendered declarative UI on web and native
- `browser_module` for elevated browser-based surfaces

This still does not mean arbitrary runtime-loaded React Native code. In practice:

- `remote_component` is a manifest-declared template the host resolves into native React Native Paper UI
- `browser_module` is a JS module imported by WebView/custom-bundle HTML

That gives us a real cross-platform remote component API without allowing unbounded third-party React Native code execution inside the app shell.

## Host Component Registry

The declarative host renderer now exposes a stable registry of host components. New plugins should prefer `host.*` namespaced component ids instead of depending on raw renderer internals.

Recommended components:

- layout: `host.stack`, `host.row`, `host.surface`, `host.scroll`, `host.spacer`
- typography: `host.heading`, `host.text`, `host.caption`
- actions/forms: `host.button`, `host.input`, `host.switch`, `host.form`
- display: `host.section`, `host.card`, `host.list`, `host.key_value`, `host.badge`, `host.chip_group`, `host.progress`
- dynamic widgets: `host.progress_cluster`, `host.data_strip`, `host.remote_component`

Legacy ids like `section`, `text`, `button`, `progress_cluster`, and `remote_component` still work, but they are compatibility aliases now.

## Manifest Anatomy

```json
{
  "id": "acme.hello-world",
  "name": "Hello World",
  "version": "0.1.0",
  "sdkVersion": 1,
  "sdkRevision": 4,
  "homepageUrl": "https://github.com/acme/hello-world-plugin",
  "documentationUrl": "https://github.com/acme/hello-world-plugin#readme",
  "sourceUrl": "https://github.com/acme/hello-world-plugin",
  "runtime": {
    "type": "php",
    "entry": "plugin.php"
  },
  "permissions": [
    "ui.settings_tab"
  ],
  "actions": [
    {
      "id": "ping",
      "label": "Ping",
      "handler": "actions/ping.php"
    }
  ],
  "uiExtensions": [
    {
      "id": "settings",
      "surface": "settings_tab",
      "mode": "declarative",
      "title": "Hello World",
      "schema": {
        "component": "host.section",
        "children": [
          {
            "component": "host.button",
            "label": "Ping",
            "actionId": "ping"
          }
        ]
      }
    }
  ],
  "requirements": {
    "memoryMb": 1024,
    "cpuCores": 2
  },
  "images": [
    {
      "id": "metrics-service",
      "image": "ghcr.io/acme/metrics-service:1.2.3",
      "engine": "auto",
      "healthcheck": {
        "command": ["php", "-v"],
        "timeoutSecs": 15
      }
    }
  ],
  "signature": {
    "algorithm": "none"
  }
}
```

For public releases, treat `homepageUrl`, `documentationUrl`, and `sourceUrl` as canonical metadata. Registry entries and landing pages should read from those fields instead of guessing repository links from wherever the plugin happened to be developed.

## Building A Plugin

### 1. Scaffold It

```bash
./plugin.sh make
```

The scaffold is now interactive by default. It prompts for:

- plugin identifier and display name
- shape: `php` / `bridge` plus `declarative` / `webview` / `custom_bundle`
- optional required image reference
- optional managed bridge service wiring
- optional minimum memory and CPU targets

You can still use it non-interactively:

```bash
./plugin.sh make acme.hello-world "Hello World" --shape=bridge-custom-bundle --image=ghcr.io/acme/hello-world-service:latest --memory=1024 --cpu=2
```

The scaffold emits the current SDK pair:

- `sdkVersion`
- `sdkRevision`

It also creates a local `AGENTS.md` file inside the plugin directory so contributors have a plugin-scoped working guide next to the manifest, runtime files, and assets.

Use `./plugin.sh` from the host checkout when possible. It enters the running backend container and executes `php artisan plugin:*` there, which avoids requiring the host machine to have the same PHP runtime as the stack.

Scaffolds created with `./plugin.sh make` land in repo [plugins](../plugins) by default. In the development stack, the unpacked install flow discovers both your local `plugins/` directory and the bundled reference plugins under [examples/plugins](../examples/plugins), so new plugins show up next to the samples in `Settings -> Plugins -> Add a plugin -> Install unpacked`.

## Runtime Diagnostics

Every installed plugin now keeps a bounded lifecycle log and a host-visible load state.

In the UI, the plugin inventory exposes:

- a `Logs` button on every installed plugin card
- a `Failed to load` badge when startup or source sync failed
- the most recent startup error in the card body

Use this when developing:

1. install or refresh the plugin
2. enable it
3. if it fails, open `Logs`
4. fix the startup issue and try again

This is the intended debugging path. Plugins should fail gracefully and leave diagnostics behind instead of taking the host down with them.

## Porting OctoPrint Plugins

The current SDK revision is designed to make OctoPrint ports mechanically straightforward.

Use this mapping:

- `SettingsPlugin.get_settings_defaults()` -> `settings.defaults`
- `_plugin_manager.send_plugin_message(...)` -> `send_plugin_message`
- `TemplatePlugin` settings template -> `settings_tab`
- navbar template -> `navbar_widget`
- `AssetPlugin` files -> manifest `assets`
- browser AJAX/view model code -> `/api/plugins/sdk/octoprint-compat.js`

### Recommended Port Strategy

1. Keep the original settings keys.
2. Rebuild navbar/status widgets as host-rendered declarative surfaces first.
3. Use a `custom_bundle` settings tab only if the original browser-side behavior is meaningful and reusable.
4. If the original plugin only needed periodic refresh while visible, use action polling instead of recreating a long-lived server timer.

### OctoPrint Browser Helper

For browser-based settings pages, import:

```js
await import("/backend/api/plugins/sdk/octoprint-compat.js");
const host = window.WPrint3DOctoPrintCompat.fromWindow();
```

Then use:

- `host.getSettings()`
- `host.saveSettings(settings)`
- `host.getState()`
- `host.watchState(callback, options)`
- `host.invokeAction("actionId", payload)`

### Reference Port

Use [examples/plugins/octoprint-navbartemp-port](../examples/plugins/octoprint-navbartemp-port) as the reference implementation.

It shows:

- persisted settings defaults that mirror the original OctoPrint plugin
- a host-rendered native navbar strip
- a `custom_bundle` settings tab using the compatibility helper
- `send_plugin_message` state updates that keep the navbar and the settings preview in sync

Host-rendered `settings_tab` pages are mounted inside a shared WPrint 3D shell. That shell keeps the plugin title visible at the top, but collapses the heavier metadata/warning hero by default so the plugin's own settings UI remains the primary thing on screen.

### Shape values

- `php-declarative`
- `php-webview`
- `php-custom-bundle`
- `bridge-declarative`
- `bridge-webview`
- `bridge-custom-bundle`

### 2. Pick The Runtime

For `php`:

```json
"runtime": {
  "type": "php",
  "entry": "plugin.php"
}
```

For `bridge`:

```json
"runtime": {
  "type": "bridge",
  "baseUrl": "http://bridge-service:9310"
}
```

Or, for a host-managed bridge image:

```json
"runtime": {
  "type": "bridge",
  "managedImageId": "metrics-service",
  "healthcheck": "/health"
}
```

Bridge plugins should expose:

- `GET /health`
- action endpoints such as `POST /actions/host_metrics`
- optional hook endpoints such as `POST /hooks/app.boot`

If `runtime.managedImageId` is used, the referenced image must declare `service.port`, and WPrint 3D will:

- pull the image during install/update
- start the container when the plugin is enabled
- attach it to the current WPrint 3D container network
- resolve the bridge `baseUrl` automatically from the managed service alias and port

### 3. Add Actions

Actions are the typed entrypoints the host can invoke from:

- declarative buttons/forms
- polling widgets like `progress_cluster`
- WebView/custom-bundle pages that call back through the host API

PHP action example:

```php
<?php

$input = json_decode(stream_get_contents(STDIN), true);

echo json_encode([
    'data' => [
        'message' => 'pong',
        'payload' => $input['payload'] ?? [],
    ],
]);
```

### 4. Add Settings UI

If a plugin declares a `settings_tab`, WPrint 3D:

- creates a dedicated tab inside Settings
- shows the plugin icon with the puzzle badge
- adds a `Settings` button to the plugin card

That keeps the plugin inventory clean and avoids embedding arbitrary settings UI inside the plugin list.

### 5. Package Or Mount

Package for distribution:

```bash
./plugin.sh pack examples/plugins/host-metrics
```

That writes the archive to:

```text
examples/plugins/host-metrics/builds/host-metrics.w3dp
```

Or, in development mode:

- run `./run.sh -e dev`
- enable `developerMode`
- open `Settings -> Plugins -> Add a plugin -> Install unpacked`
- install the source directory directly from the mounted development path

## Container Images And Resource Requirements

Heavyweight plugins declare image dependencies in the manifest:

```json
"requirements": {
  "memoryMb": 1024,
  "cpuCores": 2
},
"images": [
  {
    "id": "metrics-service",
    "image": "ghcr.io/acme/metrics-service:1.2.3",
    "engine": "auto",
    "healthcheck": {
      "command": ["curl", "-f", "http://127.0.0.1:9310/health"],
      "timeoutSecs": 15
    },
    "service": {
      "port": 9310,
      "networkAlias": "acme-metrics"
    }
  }
]
```

Notes:

- `images` is optional. No images means the plugin is `lightweight`.
- `engine` can be `auto`, `docker`, or `podman`.
- `healthcheck.command` is optional but recommended for heavyweight plugins.
- `requirements` is optional. If omitted, WPrint 3D will still install the plugin and try to run it.
- If requirements are declared and the host falls short, install still succeeds, but the UI shows warnings so the user can make an informed decision.

### Managed bridge services

If a bridge plugin declares `runtime.managedImageId`, the matching image entry must also declare `service.port`.

WPrint 3D uses that to create a host-managed sidecar container named after the plugin and image ID. This is the recommended way to ship self-contained bridge plugins with their own API server or large runtime dependencies.

## Asset-Backed Elevated UI

For `webview` and `custom_bundle`, declare assets in the manifest and reference them with `asset://`.

```json
"uiExtensions": [
  {
    "id": "settings",
    "surface": "settings_tab",
    "mode": "webview",
    "title": "Host Metrics",
    "url": "asset://ui/settings.html",
    "dataActionId": "host_metrics"
  }
],
"assets": [
  {
    "path": "ui/settings.html"
  }
]
```

WPrint 3D rewrites `asset://ui/settings.html` into an authenticated same-origin asset URL at runtime.

You can also declare JS component modules in the manifest:

```json
"components": [
  {
    "id": "hostMetricsCard",
    "kind": "browser_module",
    "entry": "asset://components/host-metrics-card.js",
    "exports": "mount"
  }
]
```

Or declare a reusable host-rendered remote component:

```json
"components": [
  {
    "id": "hostMetricsPanel",
    "kind": "remote_component",
    "schema": {
      "component": "host.section",
      "title": {
        "$prop": "title",
        "default": "Host telemetry"
      },
      "children": [
        {
          "component": "host.text",
          "text": "{{description}}"
        }
      ]
    }
  }
]
```

And consume it from a declarative schema:

```json
"schema": {
  "component": "host.remote_component",
  "componentId": "hostMetricsPanel",
  "props": {
    "title": "Host telemetry",
    "description": "Rendered by the host on web and native."
  }
}
```

And reference them from the elevated UI extension:

```json
"uiExtensions": [
  {
    "id": "host-metrics-settings",
    "surface": "settings_tab",
    "mode": "custom_bundle",
    "title": "Host Metrics",
    "bundle": {
      "url": "asset://ui/settings.html"
    },
    "components": ["hostMetricsCard"]
  }
]
```

The host also appends runtime metadata to the elevated UI URL:

- `pluginId`
- `pluginName`
- `extensionId`
- `extensionMode`
- `pluginApiBase`
- `actionId` when declared on the extension
- `components`
- `componentIds`
- `theme` as JSON theme tokens

That lets the page inherit the active theme and call back into the host API without hardcoding instance-specific URLs.

## Shape Matrix Examples

Use the Host Metrics matrix as the reference implementation:

- [examples/plugins/host-metrics](../examples/plugins/host-metrics)
- [examples/plugins/host-metrics-webview-php](../examples/plugins/host-metrics-webview-php)
- [examples/plugins/host-metrics-custom-bundle-php](../examples/plugins/host-metrics-custom-bundle-php)
- [examples/plugins/host-metrics-declarative-bridge](../examples/plugins/host-metrics-declarative-bridge)
- [examples/plugins/host-metrics-webview-bridge](../examples/plugins/host-metrics-webview-bridge)
- [examples/plugins/host-metrics-custom-bundle-bridge](../examples/plugins/host-metrics-custom-bundle-bridge)

These samples deliberately keep the feature set the same:

- CPU and RAM telemetry
- navbar widget
- dedicated settings tab

Only the runtime/UI shape changes.

The custom-bundle variants additionally demonstrate manifest-declared JS component modules:

- [examples/plugins/host-metrics-custom-bundle-php](../examples/plugins/host-metrics-custom-bundle-php)
- [examples/plugins/host-metrics-custom-bundle-bridge](../examples/plugins/host-metrics-custom-bundle-bridge)

The declarative variants demonstrate the cross-platform remote component API:

- [examples/plugins/host-metrics](../examples/plugins/host-metrics)
- [examples/plugins/host-metrics-declarative-bridge](../examples/plugins/host-metrics-declarative-bridge)

## Best Practices

- Default to `php + declarative` unless you can explain why you need something heavier.
- Prefer `host.*` declarative component ids for new plugins so the manifest stays aligned with the documented registry.
- Keep actions fast and idempotent.
- Use dedicated `settings_tab` pages instead of crowding the plugin inventory.
- Declare every WebView/custom-bundle entry asset explicitly.
- Pass state through actions and host APIs, not ad-hoc remote globals.
- Treat bridge services as production dependencies: healthcheck them, version them, and document how they are deployed.
- Keep plugin IDs stable and dotted, for example `acme.host-metrics`.
- Request only the permissions your plugin actually uses.
- Add screenshots and an E2E script when shipping a public sample plugin.

## Development Workflow

1. Scaffold or copy from the closest shape example.
2. Implement actions and hooks.
3. Add the correct UI surface.
4. Package or install unpacked from the development mount.
5. Verify in browser and, if relevant, through CLI flows.
6. Add docs and screenshots before publishing.

## Publishing Workflow

1. Increment plugin version.
2. Confirm the manifest targets a supported `sdkVersion` and `sdkRevision`.
3. Package the plugin.
4. Sign it before any public release.
5. If you want official-registry inclusion, keep the plugin in its own repository and open a PR against the public registry with that repository URL plus the signed package details.
6. Wait for the WPrint 3D team to reach out before expecting that plugin to appear publicly.
7. Otherwise, publish it through your own trusted registry or direct `.w3dp` distribution.
8. Add release notes that mention the SDK revision, runtime/UI shape, and signer continuity if you rotated keys.

## Source Checkout Requirement

Today, plugin development is anchored to the full `wprint3d-core` source tree.
That repository is intentionally small, and the supported workflow depends on the monorepo backend container for:

- `./plugin.sh`
- live development mounts
- plugin packaging
- plugin signing
- browser E2E validation against the running stack

If you want to develop plugins, clone the full source tree first and work from that checkout instead of trying to package from a standalone extracted plugin directory.

## Signing Guides

Use these guides together:

- Plugin authors: [docs/plugin-signing-for-developers.md](plugin-signing-for-developers.md)
- End users: [docs/plugin-signature-verification-for-users.md](plugin-signature-verification-for-users.md)
- Public registry maintainers: [docs/plugin-registry-signing-review.md](plugin-registry-signing-review.md)
