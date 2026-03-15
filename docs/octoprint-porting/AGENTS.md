# AGENTS.md

## Purpose

Use this directory as the quick memory bank when porting OctoPrint plugins into WPrint 3D.

## First Pass Checklist

- Identify which OctoPrint mixins the source plugin uses.
- Decide the lightest WPrint 3D shape that preserves behavior.
- Keep original setting keys where practical so the port stays mechanically traceable.
- Prefer host-rendered UI for navbar, settings, and small status widgets.
- Only fall back to `webview` or `custom_bundle` when the plugin genuinely needs browser-native layout or scripting.

## Mapping Rules

- `SettingsPlugin.get_settings_defaults()` -> `plugin.json -> settings.defaults`
- `SettingsPlugin` save/load AJAX -> `/api/plugins/{id}/settings`
- `_plugin_manager.send_plugin_message(...)` -> effect `send_plugin_message`
- `TemplatePlugin` settings tab -> `uiExtensions` `surface: "settings_tab"`
- `TemplatePlugin` navbar item -> `uiExtensions` `surface: "navbar_widget"`
- `AssetPlugin` static files -> manifest `assets` plus `asset://...`
- Knockout/browser glue -> `/api/plugins/sdk/octoprint-compat.js`
- plugin-side periodic navbar refresh -> action polling from `data_strip`

## Runtime Choice

- Use `php` when the OctoPrint plugin mostly reads host state, formats data, or queues host actions.
- Use `bridge` when the OctoPrint plugin already wraps an external service or depends on a non-PHP runtime.
- Use heavyweight `images` only when the port truly needs containerized dependencies.

## UI Choice

- Use `declarative` for host-native settings forms, buttons, simple cards, and metrics.
- Use `custom_bundle` for OctoPrint ports that already have meaningful browser-side UI logic and can benefit from the compatibility helper.
- Do not rebuild the entire navbar in an iframe. Prefer the host `data_strip` widget for temperature/status ports. In WPrint 3D, `navbar_widget` strips should read like inline telemetry inside the app bar, not like standalone pills/cards.

## Browser Helper Notes

`/api/plugins/sdk/octoprint-compat.js` gives ported browser pages:

- `getSettings()`
- `saveSettings()`
- `getState()`
- `invokeAction()`
- `watchState()`
- `getTheme()`

That should replace most custom AJAX wiring from the original OctoPrint plugin.

## PHP Port Notes

- Use `WPRINT3D_BOOTSTRAP_APP` when the action needs Laravel models or configuration.
- Keep shell access minimal. Prefer host-owned services like printer statistics readers.
- If the OctoPrint plugin used a repeated timer, first ask whether visible-surface polling is enough. It usually is for navbar widgets and settings previews.

## Recommended Verification

1. Install the port from `Settings -> Plugins -> Add a plugin -> Install unpacked`.
2. Enable it and open the plugin's dedicated settings tab.
3. Change one or two settings and save them.
4. Verify the settings page preview updates.
5. Verify the host-rendered navbar or settings surface reflects the same state.
6. If the port reads printer state, test it with an active printer selected.
