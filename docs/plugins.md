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
- `sdkRevision: 4`

## Read This First

- Developer guide: [docs/plugin-development-guide.md](plugin-development-guide.md)
- API and SDK reference: [docs/plugin-sdk-reference.md](plugin-sdk-reference.md)
- SDK changelog and deprecation policy: [docs/plugin-sdk-changelog.md](plugin-sdk-changelog.md)
- Signing guide for plugin developers: [docs/plugin-signing-for-developers.md](plugin-signing-for-developers.md)
- Signature verification guide for users: [docs/plugin-signature-verification-for-users.md](plugin-signature-verification-for-users.md)
- Registry maintainer signing review guide: [docs/plugin-registry-signing-review.md](plugin-registry-signing-review.md)
- Worked example: [docs/octoprint-navbartemp-public-registry-guide.md](octoprint-navbartemp-public-registry-guide.md)
- Shape-matrix E2E guide: [docs/plugin-shape-matrix-e2e.md](plugin-shape-matrix-e2e.md)
- Browser walkthrough for packaging/installing: [docs/plugin-showcase-e2e.md](plugin-showcase-e2e.md)
- OctoPrint porting E2E reference: [docs/octoprint-porting-e2e.md](octoprint-porting-e2e.md)

## Example Matrix

The Host Metrics sample now exists in every supported runtime/UI shape combination:

| Example | Runtime | Settings UI | Navbar Widget | Path |
| --- | --- | --- | --- | --- |
| Host Metrics | `php` | `declarative` | `declarative` | [examples/plugins/host-metrics](../examples/plugins/host-metrics) |
| Host Metrics WebView | `php` | `webview` | `declarative` | [examples/plugins/host-metrics-webview-php](../examples/plugins/host-metrics-webview-php) |
| Host Metrics Bundle | `php` | `custom_bundle` | `declarative` | [examples/plugins/host-metrics-custom-bundle-php](../examples/plugins/host-metrics-custom-bundle-php) |
| Host Metrics Bridge | `bridge` | `declarative` | `declarative` | [examples/plugins/host-metrics-declarative-bridge](../examples/plugins/host-metrics-declarative-bridge) |
| Host Metrics Bridge WebView | `bridge` | `webview` | `declarative` | [examples/plugins/host-metrics-webview-bridge](../examples/plugins/host-metrics-webview-bridge) |
| Host Metrics Bridge Bundle | `bridge` | `custom_bundle` | `declarative` | [examples/plugins/host-metrics-custom-bundle-bridge](../examples/plugins/host-metrics-custom-bundle-bridge) |

Bridge examples use the companion service in [examples/plugins/host-metrics-bridge-service](../examples/plugins/host-metrics-bridge-service).

For OctoPrint-oriented ports, use [examples/plugins/octoprint-navbartemp-port](../examples/plugins/octoprint-navbartemp-port) as the reference case. It demonstrates the settings/state compatibility layer, a host-native navbar port, and a browser-side settings bundle using the host helper.

## Quick Commands

```bash
./plugin.sh make
./plugin.sh pack plugins/acme-hello-world
./plugin.sh install plugins/acme-hello-world/builds/acme-hello-world.w3dp
python3 scripts/plugin_sign.py plugins/acme-hello-world --private-key=/secure/path/acme.pem
python3 scripts/plugin_verify_signature.py verify plugins/acme-hello-world/builds/acme-hello-world.w3dp
php artisan plugin:sync-trusted-keys
./plugin.sh list
./plugin.sh doctor
./plugin.sh status
```

`./plugin.sh` is the recommended host entrypoint because it runs the plugin Artisan commands inside the backend container. Use raw `php artisan plugin:*` only when you are already inside that container or a matching PHP environment.
`./plugin.sh status` is the quickest way to verify which backend container was resolved, whether developer mode is live, and whether the unpacked-plugin source roots are actually visible for live installs.
By default, `./plugin.sh pack <plugin-path>` writes the release archive to `<plugin-path>/builds/<plugin-dir>.w3dp`. In the development stack that path stays visible on the host because the repo is bind-mounted into the backend container at `/var/www`.
Plugin development currently assumes a full `wprint3d-core` source checkout. The repo is small, and that checkout is the supported environment for live mounts, packaging, signing, and running `./plugin.sh`.

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
- WPrint 3D can now sync signer public keys from each trusted registry source with `php artisan plugin:sync-trusted-keys`.
- The scheduler runs that sync daily against the current marketplace source list, so reviewed marketplace packages can become trusted automatically once their registry publishes `signers/index.json`.
- Unsigned sideloaded packages remain installable but are flagged in the UI.
- Signed packages now embed the signer public key and SHA-256 fingerprint so users and maintainers can inspect and verify them locally.
- Plugin authors should follow [docs/plugin-signing-for-developers.md](plugin-signing-for-developers.md) before shipping public releases.
- Registry maintainers should use [docs/plugin-registry-signing-review.md](plugin-registry-signing-review.md) for first-release review, key continuity checks, and PR handling.

## Manifest Highlights

- `sdkVersion` selects the API level.
- `sdkRevision` selects the contract revision within that API level.
- `images` makes a plugin `heavyweight`; no declared images means it stays `lightweight`.
- `requirements.memoryMb` and `requirements.cpuCores` are advisory install-time host checks.
- `runtime.managedImageId` lets a bridge plugin ask WPrint 3D to start one of its declared service images automatically.
- WebView and custom-bundle assets should be declared under `assets` and referenced with `asset://...`.
- Declarative UI can mount `remote_component` definitions from `components`, and elevated browser surfaces can load `browser_module` components from `assets`.
- New declarative plugins should prefer the stable `host.*` component registry (`host.stack`, `host.row`, `host.button`, `host.input`, `host.switch`, `host.progress`, `host.data_strip`, `host.remote_component`, and related primitives).
- Plugins with a `settings_tab` surface get their own Settings tab and a `Settings` button on the plugin inventory card.
- Installed plugins keep lifecycle logs and a load-status badge so startup failures can be diagnosed without crashing the host.
