# Host Metrics Bridge

Host Metrics example using:

- Bridge runtime
- Declarative host-rendered settings UI through the remote component API
- Declarative navbar widget

This variant uses the companion bridge service in [host-metrics-bridge-service](../host-metrics-bridge-service/README.md).

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported workflow relies on that checkout for `./plugin.sh`, live-source mounts, and `.w3dp` packaging/signing.

Package it:

```bash
./plugin.sh pack examples/plugins/host-metrics-declarative-bridge
```

Archive output:

```text
examples/plugins/host-metrics-declarative-bridge/builds/host-metrics-declarative-bridge.w3dp
```

Sign it if you plan to distribute it:

```bash
mkdir -p keys
openssl genpkey -algorithm RSA -out keys/host-metrics-declarative-bridge-private.pem -pkeyopt rsa_keygen_bits:4096
./plugin.sh pack examples/plugins/host-metrics-declarative-bridge --signing-key=keys/host-metrics-declarative-bridge-private.pem
```

Keep the private key outside the plugin directory and out of version control.

Install it:

```bash
./plugin.sh install examples/plugins/host-metrics-declarative-bridge/builds/host-metrics-declarative-bridge.w3dp
./plugin.sh enable wprint3d.host-metrics-declarative-bridge
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
