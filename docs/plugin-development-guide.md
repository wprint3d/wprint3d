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
- Supports `remote_component`, a reusable host-rendered component kind that works on web and native.

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

## Manifest Anatomy

```json
{
  "id": "acme.hello-world",
  "name": "Hello World",
  "version": "0.1.0",
  "sdkVersion": 1,
  "sdkRevision": 2,
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
        "component": "section",
        "children": [
          {
            "component": "button",
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

## Building A Plugin

### 1. Scaffold It

```bash
php artisan plugin:make
```

The scaffold is now interactive by default. It prompts for:

- plugin identifier and display name
- shape: `php` / `bridge` plus `declarative` / `webview` / `custom_bundle`
- optional required image reference
- optional managed bridge service wiring
- optional minimum memory and CPU targets

You can still use it non-interactively:

```bash
php artisan plugin:make acme.hello-world "Hello World" --shape=bridge-custom-bundle --image=ghcr.io/acme/hello-world-service:latest --memory=1024 --cpu=2
```

The scaffold emits the current SDK pair:

- `sdkVersion`
- `sdkRevision`

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
php artisan plugin:pack examples/plugins/host-metrics
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
      "component": "section",
      "title": {
        "$prop": "title",
        "default": "Host telemetry"
      },
      "children": [
        {
          "component": "text",
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
  "component": "remote_component",
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

- [examples/plugins/host-metrics](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics)
- [examples/plugins/host-metrics-webview-php](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-webview-php)
- [examples/plugins/host-metrics-custom-bundle-php](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-custom-bundle-php)
- [examples/plugins/host-metrics-declarative-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-declarative-bridge)
- [examples/plugins/host-metrics-webview-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-webview-bridge)
- [examples/plugins/host-metrics-custom-bundle-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-custom-bundle-bridge)

These samples deliberately keep the feature set the same:

- CPU and RAM telemetry
- navbar widget
- dedicated settings tab

Only the runtime/UI shape changes.

The custom-bundle variants additionally demonstrate manifest-declared JS component modules:

- [examples/plugins/host-metrics-custom-bundle-php](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-custom-bundle-php)
- [examples/plugins/host-metrics-custom-bundle-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-custom-bundle-bridge)

The declarative variants demonstrate the cross-platform remote component API:

- [examples/plugins/host-metrics](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics)
- [examples/plugins/host-metrics-declarative-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-declarative-bridge)

## Best Practices

- Default to `php + declarative` unless you can explain why you need something heavier.
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
4. Optionally sign it.
5. Publish it to the official registry or a trusted third-party registry.
6. Add release notes that mention the SDK revision and runtime/UI shape.
