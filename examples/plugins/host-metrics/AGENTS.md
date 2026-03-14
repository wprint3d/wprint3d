# AGENTS.md

## Purpose

This directory contains the baseline `Host Metrics` example plugin.

## Shape

- Runtime: `php`
- UI mode: `declarative`
- Footprint: `lightweight`

## Important files

- `plugin.json`: declarative settings tab plus navbar widget
- `actions/host_metrics.php`: host metric action handler
- `plugin.php`: PHP runtime entrypoint
- `docs/README.md`: example-specific walkthrough

## Working rules

- Treat this as the default “happy path” SDK example.
- Keep the declarative settings tab and navbar widget in parity with the other Host Metrics shape variants.
- If the shared host metrics payload changes, update the other shape-matrix examples too.

## Verification

- `php artisan plugin:pack examples/plugins/host-metrics`
- Re-run the relevant plugin shape/browser walkthrough if the UI changes
