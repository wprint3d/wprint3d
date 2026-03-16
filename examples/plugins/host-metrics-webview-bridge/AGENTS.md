# AGENTS.md

## Purpose

This directory contains the `Host Metrics Bridge WebView` example plugin.

## Shape

- Runtime: `bridge`
- UI mode: `webview`
- Footprint: `lightweight`

## Important files

- `plugin.json`: bridge runtime manifest and WebView surface declaration
- `ui/settings.html`: bridge-backed settings page
- Companion bridge service: `examples/plugins/host-metrics-bridge-service`

## Working rules

- Keep this example focused on the combination of bridge transport plus WebView UI.
- Asset-backed settings pages should stay same-origin and host-served.
- If the bridge response shape changes, update the bridge custom-bundle variant too.

## Verification

- Start the companion bridge service
- Install the plugin and confirm the WebView settings page renders host metrics data
