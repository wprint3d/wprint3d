# Host Metrics Bridge Service

This helper service backs the bridge-runtime Host Metrics examples:

- `examples/plugins/host-metrics-declarative-bridge`
- `examples/plugins/host-metrics-webview-bridge`
- `examples/plugins/host-metrics-custom-bundle-bridge`

Run it during local development or E2E verification:

```bash
php -S 127.0.0.1:9310 router.php
```

It exposes:

- `GET /health`
- `POST /actions/host_metrics`
