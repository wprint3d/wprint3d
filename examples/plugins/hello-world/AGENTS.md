# AGENTS.md

## Purpose

This directory contains the `Hello World` reference plugin for WPrint 3D.

## Shape

- Runtime: `php`
- UI mode: `declarative`
- Footprint: `lightweight`

## Important files

- `plugin.json`: manifest, permissions, actions, and the `host.*` settings-tab schema
- `plugin.php`: PHP runtime entrypoint
- `hooks/on_boot.php`: boot hook sample
- `actions/ping.php`: minimal action sample
- `README.md`: quick package/install usage

## Working rules

- Keep this example aligned with `docs/plugin-development-guide.md`.
- Keep it minimal; this example is the default starting point for new plugin authors.
- Prefer documented `host.*` component ids when adjusting the declarative UI.
- If the manifest contract changes, update this example first.

## Verification

- `php artisan plugin:pack examples/plugins/hello-world`
- Install it through `Settings -> Plugins` or `php artisan plugin:install`
