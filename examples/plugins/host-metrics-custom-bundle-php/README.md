# Host Metrics Bundle

Host Metrics example using:

- PHP runtime
- Custom bundle settings UI served from plugin assets
- Manifest-declared JS component loaded from `components/host-metrics-card.js`
- Declarative navbar widget

Use this when you need elevated UI behavior while still keeping actions and hooks on the PHP runtime.

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported workflow relies on that checkout for `./plugin.sh`, live-source mounts, and `.w3dp` packaging/signing.

Package it:

```bash
./plugin.sh pack examples/plugins/host-metrics-custom-bundle-php
```

Archive output:

```text
examples/plugins/host-metrics-custom-bundle-php/builds/host-metrics-custom-bundle-php.w3dp
```

Sign it if you plan to distribute it:

```bash
mkdir -p keys
openssl genpkey -algorithm RSA -out keys/host-metrics-custom-bundle-php-private.pem -pkeyopt rsa_keygen_bits:4096
./plugin.sh pack examples/plugins/host-metrics-custom-bundle-php --signing-key=keys/host-metrics-custom-bundle-php-private.pem
```

Keep the private key outside the plugin directory and out of version control.

Install it:

```bash
./plugin.sh install examples/plugins/host-metrics-custom-bundle-php/builds/host-metrics-custom-bundle-php.w3dp
./plugin.sh enable wprint3d.host-metrics-custom-bundle-php
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
