# Host Metrics Bridge Bundle

Host Metrics example using:

- Bridge runtime
- Custom bundle settings UI served from plugin assets
- Manifest-declared JS component loaded from `components/host-metrics-card.js`
- Declarative navbar widget

This variant is the highest-flexibility Host Metrics example in the SDK matrix.

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported workflow relies on that checkout for `./plugin.sh`, live-source mounts, and `.w3dp` packaging/signing.

Package it:

```bash
./plugin.sh pack examples/plugins/host-metrics-custom-bundle-bridge
```

Archive output:

```text
examples/plugins/host-metrics-custom-bundle-bridge/builds/host-metrics-custom-bundle-bridge.w3dp
```

Sign it if you plan to distribute it:

```bash
mkdir -p keys
openssl genpkey -algorithm RSA -out keys/host-metrics-custom-bundle-bridge-private.pem -pkeyopt rsa_keygen_bits:4096
./plugin.sh pack examples/plugins/host-metrics-custom-bundle-bridge --signing-key=keys/host-metrics-custom-bundle-bridge-private.pem
```

Keep the private key outside the plugin directory and out of version control.
For the full signing, verification, and registry submission flow, see `/home/facuarmo/wprint3d-core/docs/plugin-signing-for-developers.md`.

Install it:

```bash
./plugin.sh install examples/plugins/host-metrics-custom-bundle-bridge/builds/host-metrics-custom-bundle-bridge.w3dp
./plugin.sh enable wprint3d.host-metrics-custom-bundle-bridge
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
