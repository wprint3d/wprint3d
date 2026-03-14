# AGENTS.md

## Purpose

This directory contains the `Host Metrics Bridge` example plugin for the bridge runtime.

## Shape

- Runtime: `bridge`
- UI mode: `declarative`
- Footprint: `lightweight`

## Important files

- `plugin.json`: bridge runtime manifest and declarative surfaces
- `docs/README.md` or the shape-matrix docs: bridge usage notes
- Companion bridge service: `examples/plugins/host-metrics-bridge-service`

## Working rules

- Keep the manifest aligned with the companion bridge service contract.
- This example demonstrates bridge transport, not elevated UI.
- If bridge payloads or healthchecks change, update the WebView/custom-bundle bridge variants too.

## Verification

- Start the companion bridge service
- Install the plugin and confirm the declarative settings tab can fetch host metrics
