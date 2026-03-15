# Hello World Plugin

Reference plugin for the WPrint 3D plugin platform.

## Why this example uses declarative UI

Declarative host-rendered UI is the default because it is the lightest option for constrained devices such as Raspberry Pi 3 class hardware. This example also demonstrates the stable `host.*` component registry (`host.section`, `host.heading`, `host.caption`, `host.chip_group`, and `host.button`) that new plugins should prefer in their manifests.

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported plugin workflow depends on that checkout for `./plugin.sh`, the dev stack, and the packaging/signing commands below.

Package it:

```bash
./plugin.sh pack examples/plugins/hello-world
```

Archive output:

```text
examples/plugins/hello-world/builds/hello-world.w3dp
```

Sign it if you plan to distribute it:

```bash
mkdir -p keys
openssl genpkey -algorithm RSA -out keys/hello-world-private.pem -pkeyopt rsa_keygen_bits:4096
./plugin.sh pack examples/plugins/hello-world --signing-key=keys/hello-world-private.pem
```

Keep the private key outside the plugin directory and out of version control.
For the full signing, verification, and registry submission flow, see `/home/facuarmo/wprint3d-core/docs/plugin-signing-for-developers.md`.

Install it:

```bash
./plugin.sh install examples/plugins/hello-world/builds/hello-world.w3dp
./plugin.sh enable wprint3d.hello-world
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
