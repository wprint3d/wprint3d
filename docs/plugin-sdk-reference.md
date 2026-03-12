# Plugin SDK Reference

## SDK Identity

Current SDK pair:

- `sdkVersion: 1`
- `sdkRevision: 1`

`sdkVersion` is the API level.

`sdkRevision` is the contract revision within that API level. Revisions let WPrint 3D evolve the SDK without immediately forcing a full API-level jump.

## Manifest Fields

### Required

- `id`
- `name`
- `version`
- `sdkVersion`
- `runtime`

### Recommended

- `sdkRevision`
- `description`
- `author`
- `minCoreVersion`
- `icon`
- `permissions`
- `actions`
- `uiExtensions`
- `signature`
- `assets`
- `components`
- `updateSource`

## Runtime Contracts

### PHP Runtime

Manifest:

```json
"runtime": {
  "type": "php",
  "entry": "plugin.php"
}
```

Actions and hooks point at relative PHP files such as:

- `actions/ping.php`
- `hooks/on_boot.php`

The host invokes them with JSON on `STDIN`.

Available environment variables:

- `WPRINT3D_PLUGIN_ID`
- `WPRINT3D_PLUGIN_STORAGE_PATH`
- `WPRINT3D_BASE_PATH`
- `WPRINT3D_VENDOR_AUTOLOAD`

### Bridge Runtime

Manifest:

```json
"runtime": {
  "type": "bridge",
  "baseUrl": "http://bridge-service:9310",
  "healthcheck": "/health"
}
```

The host performs:

- `GET /health` when enabling the plugin
- `POST` to hook/action paths for runtime work

Action payload:

```json
{
  "kind": "action",
  "action": "host_metrics",
  "payload": {},
  "context": {
    "userId": "...",
    "printerId": null
  }
}
```

Hook payload:

```json
{
  "kind": "hook",
  "hook": "app.boot",
  "context": {}
}
```

## Supported Permissions

- `printer.read`
- `printer.command.queue`
- `camera.read`
- `host.metrics.read`
- `network.outbound`
- `storage.read`
- `storage.write`
- `ui.settings_tab`
- `ui.navbar_widget`
- `ui.printer_panel`
- `ui.printer_action`
- `ui.modal`
- `ui.page`
- `ui.webview`
- `ui.custom_bundle`

## Supported Hooks

- `app.boot`
- `serial.command.before_send`
- `serial.command.response_received`
- `serial.line.received`
- `camera.snapshot.before_take`
- `camera.snapshot.after_take`
- `print.job.started`
- `print.job.failed`
- `print.job.finished`

## Supported Surfaces

- `settings_tab`
- `navbar_widget`
- `printer_panel`
- `printer_action`
- `modal`
- `page`

## UI Modes

### Declarative

Required field:

- `schema`

Supported built-in components:

- `section`
- `text`
- `divider`
- `list`
- `key_value`
- `button`
- `form`
- `progress_cluster`
- `remote_component`

`progress_cluster` fields:

- `dataActionId`
- `pollIntervalMs`
- `items[]`

Each item supports:

- `id`
- `label`
- `valueKey`

### WebView

Required field:

- `url`

Use `asset://ui/settings.html` for plugin-packaged assets.

### Custom Bundle

Required field:

- `bundle.url`

Use `asset://ui/settings.html` for plugin-packaged assets unless you intentionally host the bundle elsewhere.

## Manifest Components

The top-level `components` field declares reusable plugin components.

Example:

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

Supported today:

- `kind: "remote_component"` for host-rendered declarative UI on web and native
- `kind: "browser_module"`
- `browser_module` use from `webview` and `custom_bundle` surfaces

Not supported today:

- arbitrary host-rendered React components inside declarative schema nodes

UI extensions may reference declared component IDs:

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

Declarative schemas may mount a declared remote component directly:

```json
{
  "component": "remote_component",
  "componentId": "hostMetricsPanel",
  "props": {
    "title": "Host telemetry",
    "description": "This is rendered by the host."
  }
}
```

## Asset Rules

- Every plugin-packaged elevated UI entrypoint must be declared in `assets`.
- Every manifest component module must also be declared in `assets`.
- Asset paths must stay inside the plugin runtime directory.
- `..` segments are rejected.
- Asset-backed UI references are rewritten into authenticated host URLs at runtime.

Example:

```json
"assets": [
  {
    "path": "ui/settings.html"
  }
]
```

## Management API

### Inventory And Metadata

- `GET /api/plugins`
- `GET /api/plugins/{pluginId}`
- `GET /api/plugins/sdk`
- `GET /api/plugins/ui?surface=settings_tab`

### Install / Remove / Update

- `POST /api/plugins/install`
- `POST /api/plugins/{pluginId}/enable`
- `POST /api/plugins/{pluginId}/disable`
- `POST /api/plugins/{pluginId}/update`
- `DELETE /api/plugins/{pluginId}`

### Runtime

- `POST /api/plugins/{pluginId}/actions/{actionId}`
- `GET /api/plugins/{pluginId}/assets/{assetPath}`

### Registry

- `GET /api/plugins/registry`
- `GET /api/plugins/registry/sources`
- `PUT /api/plugins/registry/sources`

### Development

- `GET /api/plugins/development`
- `POST /api/plugins/install` with `unpackedPath`

## CLI

- `php artisan plugin:make`
- `php artisan plugin:pack`
- `php artisan plugin:publish`
- `php artisan plugin:search`
- `php artisan plugin:install`
- `php artisan plugin:list`
- `php artisan plugin:enable`
- `php artisan plugin:disable`
- `php artisan plugin:remove`
- `php artisan plugin:update`
- `php artisan plugin:doctor`

## Host Theme Metadata For Elevated UI

Elevated UI entrypoints receive query parameters for:

- `pluginId`
- `pluginName`
- `extensionId`
- `extensionMode`
- `pluginApiBase`
- `actionId`
- `components`
- `componentIds`
- `theme`

The `theme` value is JSON and includes the current Paper theme color tokens such as:

- `primary`
- `secondary`
- `tertiary`
- `surface`
- `surfaceVariant`
- `background`
- `onSurface`
- `onSurfaceVariant`
- `outline`
- `outlineVariant`
- `error`

## Example Use Cases

- `php + declarative`: compact settings panels, navbar widgets, quick actions
- `php + webview`: lightweight HTML dashboards that still use host actions
- `php + custom_bundle`: heavier UI with richer client logic but local PHP runtime
- manifest-declared browser components: asset-backed JS modules mounted by elevated UI shells
- `bridge + declarative`: external integrations rendered in host-owned cards
- `bridge + webview`: full external-service integrations with isolated HTML UI
- `bridge + custom_bundle`: richest integration path when both runtime and UI live outside the default host path
