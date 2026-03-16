# Host Metrics Plugin Walkthrough

The baseline Host Metrics example is now part of the full plugin shape matrix documented in:

- [docs/plugin-shape-matrix-e2e.md](../../../../docs/plugin-shape-matrix-e2e.md)

Use this baseline example when you want the default SDK path:

- PHP runtime
- declarative settings tab
- declarative navbar widget

Implementation entrypoints:

- [examples/plugins/host-metrics/plugin.json](../plugin.json)
- [examples/plugins/host-metrics/actions/host_metrics.php](../actions/host_metrics.php)
- [app/Plugins/Support/HostMetricsReader.php](../../../../app/Plugins/Support/HostMetricsReader.php)
