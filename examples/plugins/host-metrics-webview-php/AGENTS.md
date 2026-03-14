# AGENTS.md

## Purpose

This directory contains the `Host Metrics WebView` example plugin for the PHP runtime.

## Shape

- Runtime: `php`
- UI mode: `webview`
- Footprint: `lightweight`

## Important files

- `plugin.json`: manifest and asset-backed WebView registration
- `actions/host_metrics.php`: host metric action handler
- `ui/settings.html`: settings page rendered inside the WebView surface

## Working rules

- Keep this example focused on the WebView-specific path rather than duplicating declarative behavior.
- Asset references in `plugin.json` should remain `asset://...` based.
- If the WebView host API changes, update this example alongside the bridge WebView variant.

## Verification

- `php artisan plugin:pack examples/plugins/host-metrics-webview-php`
- Open the plugin settings tab and confirm the WebView asset resolves correctly
