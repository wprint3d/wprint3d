# Hello World Plugin

Reference plugin for the WPrint 3D plugin platform.

## Why this example uses declarative UI

Declarative host-rendered UI is the default because it is the lightest option for constrained devices such as Raspberry Pi 3 class hardware. This example also demonstrates the stable `host.*` component registry (`host.section`, `host.heading`, `host.caption`, `host.chip_group`, and `host.button`) that new plugins should prefer in their manifests.

## Packaging and release

Develop this plugin from a full `wprint3d-core` source checkout. The repository is small, and the supported plugin workflow depends on that checkout for `./plugin.sh`, the dev stack, and the packaging/signing commands below.

Unsigned development build:

```bash
./plugin.sh pack examples/plugins/hello-world
```

Archive output:

```text
examples/plugins/hello-world/builds/hello-world.w3dp
```

Generate or reuse a long-lived signing key:

```bash
./plugin.sh keygen --output ../plugin-signing/hello-world.pem
```

Interactive signed release flow:

```bash
./plugin.sh pack examples/plugins/hello-world --wizard
```

Non-interactive signed release flow:

```bash
./plugin.sh pack examples/plugins/hello-world --signing-key ../plugin-signing/hello-world.pem
```

Verify before installing or publishing:

```bash
./plugin.sh verify examples/plugins/hello-world/builds/hello-world.w3dp
./plugin.sh verify examples/plugins/hello-world/builds/hello-world.w3dp --require-trusted
```

Restore a source tree from a package when you need to test or fork a published release:

```bash
./plugin.sh restore examples/plugins/hello-world/builds/hello-world.w3dp --output plugins/hello-world-fork
```

Every signed `.w3dp` embeds the signer public key automatically.
Keep the private key outside the plugin directory and out of version control, back it up in at least one secure encrypted location, and if you ever restore it from backup run `chmod 600 ../plugin-signing/hello-world.pem`.
For the full signing, verification, backup, and registry submission flow, see:

- [../../../docs/plugin-signing-for-developers.md](../../../docs/plugin-signing-for-developers.md)
- [../../../docs/plugin-signature-verification-for-users.md](../../../docs/plugin-signature-verification-for-users.md)
- [../../../docs/plugin-registry-signing-review.md](../../../docs/plugin-registry-signing-review.md)

Install it:

```bash
./plugin.sh install examples/plugins/hello-world/builds/hello-world.w3dp
./plugin.sh enable wprint3d.hello-world
```

For public-registry inclusion, keep the plugin in its own repository, open a PR against the public registry with that repository URL, and wait for the WPrint 3D team to follow up.
