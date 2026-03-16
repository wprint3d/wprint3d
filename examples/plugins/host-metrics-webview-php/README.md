# Host Metrics WebView

Host Metrics example using:

- PHP runtime
- WebView settings UI served from plugin assets
- Declarative navbar widget

Use this when you need isolated HTML UI but want to keep runtime execution inside WPrint 3D's PHP adapter.

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported workflow relies on that checkout for `./plugin.sh`, live-source mounts, and `.w3dp` packaging/signing.

Unsigned development build:

```bash
./plugin.sh pack examples/plugins/host-metrics-webview-php
```

Archive output:

```text
examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp
```

Generate or reuse a long-lived signing key:

```bash
./plugin.sh keygen --output ../plugin-signing/host-metrics-webview-php.pem
```

Interactive signed release flow:

```bash
./plugin.sh pack examples/plugins/host-metrics-webview-php --wizard
```

Non-interactive signed release flow:

```bash
./plugin.sh pack examples/plugins/host-metrics-webview-php --signing-key ../plugin-signing/host-metrics-webview-php.pem
```

Verify before installing or publishing:

```bash
./plugin.sh verify examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp
./plugin.sh verify examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp --require-trusted
```

Restore a source tree from a package when you need to test or fork a published release:

```bash
./plugin.sh restore examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp --output plugins/host-metrics-webview-php-fork
```

Every signed `.w3dp` embeds the signer public key automatically.
Keep the private key outside the plugin directory and out of version control, back it up in at least one secure encrypted location, and if you ever restore it from backup run `chmod 600 ../plugin-signing/host-metrics-webview-php.pem`.
For the full signing, verification, backup, and registry submission flow, see:

- [../../../docs/plugin-signing-for-developers.md](../../../docs/plugin-signing-for-developers.md)
- [../../../docs/plugin-signature-verification-for-users.md](../../../docs/plugin-signature-verification-for-users.md)
- [../../../docs/plugin-registry-signing-review.md](../../../docs/plugin-registry-signing-review.md)

Install it:

```bash
./plugin.sh install examples/plugins/host-metrics-webview-php/builds/host-metrics-webview-php.w3dp
./plugin.sh enable wprint3d.host-metrics-webview-php
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
