# AGENTS.md

## Purpose

This directory contains the `Host Metrics Bridge Bundle` example plugin.

## Shape

- Runtime: `bridge`
- UI mode: `custom_bundle`
- Footprint: `lightweight`

## Important files

- `plugin.json`: bridge runtime manifest, bundle entry, and component declarations
- `ui/settings.html`: custom-bundle entrypoint
- `components/host-metrics-card.js`: browser-side component module example
- Companion bridge service: `examples/plugins/host-metrics-bridge-service`

## Working rules

- Keep this example centered on bridge transport plus elevated custom-bundle UI.
- If browser-module loading changes, update this example and the PHP custom-bundle variant together.
- Do not drift this example away from the documented shape matrix.

## Verification

- Start the companion bridge service
- Install the plugin and confirm the custom bundle loads the declared JS module and renders host metrics
