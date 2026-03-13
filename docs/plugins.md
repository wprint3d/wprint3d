# WPrint 3D Plugin Development

WPrint 3D now ships with a first-party plugin platform built around `.w3dp` packages.

## Package layout

Every plugin package must contain:

- `plugin.json`
- Runtime code and assets declared by the manifest
- Optional declarative UI schemas
- Optional WebView or custom bundle assets

## Supported runtimes

- `php`: lightweight on-demand PHP handlers executed by the core process
- `bridge`: external services invoked through HTTP

## Supported UI modes

- `declarative`: default and recommended for low-memory devices
- `webview`: isolated HTML experience
- `custom_bundle`: elevated mode for richer remote bundles

## Supported UI surfaces

- `settings_tab`
- `navbar_widget`
- `printer_panel`
- `printer_action`
- `modal`
- `page`

## Built-in declarative components

- `section`
- `text`
- `divider`
- `list`
- `key_value`
- `button`
- `form`
- `progress_cluster`

`progress_cluster` is intended for compact status surfaces such as `navbar_widget`. It polls a declared plugin action and renders host-themed status bars, so the plugin inherits the active WPrint 3D theme without shipping its own UI bundle.

`settings_tab` surfaces are rendered as first-class tabs inside `Settings`. They are no longer embedded under the plugin inventory list. If a plugin declares a `settings_tab`, WPrint 3D also exposes a `Settings` button on that plugin's card so operators can jump straight to the plugin's own settings page.

Plugins may optionally declare a top-level `icon` field with a Material Community Icons name. WPrint 3D uses that icon for plugin-specific settings tabs and falls back to the puzzle icon when it is omitted.

## Selected permissions

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

## Core CLI

```bash
php artisan plugin:make acme.hello-world "Hello World"
php artisan plugin:pack plugins/acme-hello-world
php artisan plugin:search hello
php artisan plugin:install plugins/acme-hello-world.w3dp
php artisan plugin:list
php artisan plugin:doctor
```

## Development Mount

When WPrint 3D is started through `./run.sh -e dev`, the development compose stack exposes a live plugin source mount for unpacked plugins.

- Host path: `./examples/plugins`
- Container path: `/var/www/plugins-dev`
- Backend setting gate: `Settings -> System -> developerMode`

Once `developerMode` is enabled, `Settings -> Plugins -> Add a plugin` exposes an `Install unpacked` tab that lists source directories from the mounted development path. Installing from that tab does not create a `.w3dp` archive. Instead, WPrint 3D stores the plugin metadata and points the plugin runtime directly at the mounted source directory.

This means:

- PHP plugin code changes are picked up directly from disk in the running development stack
- Declarative manifest and UI changes can be refreshed from the plugin card with `Refresh`
- Removing the plugin from WPrint 3D does not delete the source directory from the mount

## Signing

Unsigned plugins can still be sideloaded, but they are marked as elevated risk.

To sign a package:

```bash
php artisan plugin:pack plugins/acme-hello-world \
  --signing-key=/path/to/private.pem \
  --passphrase=secret
```

Configure trusted public keys in `PLUGIN_TRUSTED_PUBLIC_KEYS`.

## Official registry

The official registry is GitHub-backed and consumes an index JSON file plus release-hosted `.w3dp` assets.

- Device UI reads the registry through `PLUGIN_REGISTRY_INDEX_URL`
- The website marketplace reads the same index
- `plugin:publish` can upload release assets to the configured GitHub repository

Trusted third-party registries can also be added from the in-app Marketplace via the gear button. Those sources are stored per instance and their listings are marked in the UI as trusted third-party packages so operators can distinguish them from official registry results.

## Example plugin

See [examples/plugins/hello-world](/home/facuarmo/wprint3d-core/examples/plugins/hello-world) for a minimal plugin that:

- renders a declarative settings tab inside the Settings modal
- exposes a `ping` action
- listens to `app.boot`

See [examples/plugins/host-metrics](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics) for a compact navbar widget plugin that reads host CPU and RAM usage through the plugin runtime and renders two host-themed progress bars.
