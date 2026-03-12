# Host Metrics Plugin Walkthrough

The baseline Host Metrics example is now part of the full plugin shape matrix documented in:

- [docs/plugin-shape-matrix-e2e.md](/home/facuarmo/wprint3d-core/docs/plugin-shape-matrix-e2e.md)

Use this baseline example when you want the default SDK path:

- PHP runtime
- declarative settings tab
- declarative navbar widget

Implementation entrypoints:

- [examples/plugins/host-metrics/plugin.json](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics/plugin.json)
- [examples/plugins/host-metrics/actions/host_metrics.php](/home/facuarmo/wprint3d-core/examples/plugins/host-metrics/actions/host_metrics.php)
- [app/Plugins/Support/HostMetricsReader.php](/home/facuarmo/wprint3d-core/app/Plugins/Support/HostMetricsReader.php)
