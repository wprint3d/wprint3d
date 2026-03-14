# AGENTS.md

## Purpose

This directory contains the `Host Metrics Bundle` example plugin for the PHP runtime.

## Shape

- Runtime: `php`
- UI mode: `custom_bundle`
- Footprint: `lightweight`

## Important files

- `plugin.json`: manifest, bundle entry, and browser-module component declarations
- `actions/host_metrics.php`: host metric action handler
- `ui/settings.html`: custom bundle entrypoint
- `components/host-metrics-card.js`: browser-side component module example

## Working rules

- Keep this example centered on the elevated custom-bundle path.
- If `components` or browser-module loading changes, update this example first.
- Maintain parity with the bridge custom-bundle variant where possible.

## Verification

- `php artisan plugin:pack examples/plugins/host-metrics-custom-bundle-php`
- Confirm the settings page loads the declared JS component module
