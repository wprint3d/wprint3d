# WPrint 3D Plugin Docs

WPrint 3D's plugin platform supports:

- `.w3dp` packages and unpacked development-mount installs
- `php` and `bridge` runtimes
- `declarative`, `webview`, and `custom_bundle` UI modes
- manifest-declared remote components for declarative UI plus JS modules for elevated browser-based surfaces
- dedicated plugin settings tabs, navbar widgets, printer surfaces, modals, and pages
- signed official-registry packages plus trusted and sideloaded third-party sources

Current SDK target:

- `sdkVersion: 1`
- `sdkRevision: 2`

## Read This First

- Developer guide: [docs/plugin-development-guide.md](/home/facuarmo/wprint3d-core/docs/plugin-development-guide.md)
- API and SDK reference: [docs/plugin-sdk-reference.md](/home/facuarmo/wprint3d-core/docs/plugin-sdk-reference.md)
- SDK changelog and deprecation policy: [docs/plugin-sdk-changelog.md](/home/facuarmo/wprint3d-core/docs/plugin-sdk-changelog.md)
- Shape-matrix E2E guide: [docs/plugin-shape-matrix-e2e.md](/home/facuarmo/wprint3d-core/docs/plugin-shape-matrix-e2e.md)
- Browser walkthrough for packaging/installing: [docs/plugin-showcase-e2e.md](/home/facuarmo/wprint3d-core/docs/plugin-showcase-e2e.md)

## Example Matrix

The Host Metrics sample now exists in every supported runtime/UI shape combination:

| Example | Runtime | Settings UI | Navbar Widget | Path |
| --- | --- | --- | --- | --- |
| Host Metrics | `php` | `declarative` | `declarative` | [examples/plugins/host-metrics](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics) |
| Host Metrics WebView | `php` | `webview` | `declarative` | [examples/plugins/host-metrics-webview-php](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-webview-php) |
| Host Metrics Bundle | `php` | `custom_bundle` | `declarative` | [examples/plugins/host-metrics-custom-bundle-php](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-custom-bundle-php) |
| Host Metrics Bridge | `bridge` | `declarative` | `declarative` | [examples/plugins/host-metrics-declarative-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-declarative-bridge) |
| Host Metrics Bridge WebView | `bridge` | `webview` | `declarative` | [examples/plugins/host-metrics-webview-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-webview-bridge) |
| Host Metrics Bridge Bundle | `bridge` | `custom_bundle` | `declarative` | [examples/plugins/host-metrics-custom-bundle-bridge](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-custom-bundle-bridge) |

Bridge examples use the companion service in [examples/plugins/host-metrics-bridge-service](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics-bridge-service).

## Quick Commands

```bash
./plugin.sh make
./plugin.sh pack plugins/acme-hello-world
./plugin.sh install plugins/acme-hello-world.w3dp
./plugin.sh list
./plugin.sh doctor
./plugin.sh status
```

`./plugin.sh` is the recommended host entrypoint because it runs the plugin Artisan commands inside the backend container. Use raw `php artisan plugin:*` only when you are already inside that container or a matching PHP environment.
`./plugin.sh status` is the quickest way to verify which backend container was resolved, whether developer mode is live, and whether the unpacked-plugin source roots are actually visible for live installs.

## Development Mount

When WPrint 3D runs through `./run.sh -e dev`, unpacked source plugins are discovered from:

- host path: `./plugins`
- container path: `/var/www/plugins`
- host path: `./examples/plugins`
- container path: `/var/www/plugins-dev`

Enable `developerMode` in Settings, then open `Settings -> Plugins -> Add a plugin -> Install unpacked` to install live source directories without packaging them first.

Production backend images do not bundle `examples/plugins`. That keeps the shipped runtime smaller and avoids publishing demonstration packages in production images. If you want the sample plugins, use the development stack or a source checkout.

## Registry And Trust

- Official packages come from the GitHub-backed official registry.
- Trusted third-party registries can be added in the Marketplace via the gear button.
- Unsigned sideloaded packages remain installable but are flagged in the UI.

## Manifest Highlights

- `sdkVersion` selects the API level.
- `sdkRevision` selects the contract revision within that API level.
- `images` makes a plugin `heavyweight`; no declared images means it stays `lightweight`.
- `requirements.memoryMb` and `requirements.cpuCores` are advisory install-time host checks.
- `runtime.managedImageId` lets a bridge plugin ask WPrint 3D to start one of its declared service images automatically.
- WebView and custom-bundle assets should be declared under `assets` and referenced with `asset://...`.
- Declarative UI can mount `remote_component` definitions from `components`, and elevated browser surfaces can load `browser_module` components from `assets`.
- Plugins with a `settings_tab` surface get their own Settings tab and a `Settings` button on the plugin inventory card.
