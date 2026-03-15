# Host Metrics WebView

Host Metrics example using:

- PHP runtime
- WebView settings UI served from plugin assets
- Declarative navbar widget

Use this when you need isolated HTML UI but want to keep runtime execution inside WPrint 3D's PHP adapter.

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported workflow relies on that checkout for `./plugin.sh`, live-source mounts, and `.w3dp` packaging/signing.

Package it:

```bash
./plugin.sh pack examples/plugins/host-metrics-webview-php
```

Archive output:

```text
examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp
```

Sign it if you plan to distribute it:

```bash
mkdir -p keys
openssl genpkey -algorithm RSA -out keys/host-metrics-webview-php-private.pem -pkeyopt rsa_keygen_bits:4096
./plugin.sh pack examples/plugins/host-metrics-webview-php --signing-key=keys/host-metrics-webview-php-private.pem
```

Keep the private key outside the plugin directory and out of version control.

Install it:

```bash
./plugin.sh install examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp
./plugin.sh enable wprint3d.host-metrics-webview-php
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
