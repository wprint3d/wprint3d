# OctoPrint NavbarTemp Port: Pack, Sign, And Publish To The First Public Registry

This is the concrete bootstrap flow used to publish the first registry entry for `octoprint.navbartemp-port`.

## Goal

1. build a signed `.w3dp` package for `examples/plugins/octoprint-navbartemp-port`
2. verify its signature and checksum
3. initialize the new `plugin-registry` repository
4. add the plugin package, signer key, and `index.json` entry
5. make the landing site ready to consume that registry

## Prerequisites

- a full `wprint3d-core` checkout
- `python3`
- `openssl`
- `php`
- the local showcase repo at `/home/facuarmo/wprint3d.github.io`

The plugin manifest now declares its canonical public URLs:

- `homepageUrl`: `https://github.com/wprint3d/OctoPrint-NavbarTemp-Port`
- `documentationUrl`: `https://github.com/wprint3d/OctoPrint-NavbarTemp-Port#readme`
- `sourceUrl`: `https://github.com/wprint3d/OctoPrint-NavbarTemp-Port`

## 1. Create or reuse the official signing key

Keep the key outside the plugin repo:

```bash
mkdir -p /home/facuarmo/.config/wprint3d/plugin-signing
openssl genpkey \
  -algorithm RSA \
  -pkeyopt rsa_keygen_bits:4096 \
  -out /home/facuarmo/.config/wprint3d/plugin-signing/wprint3d-official-registry.pem
```

## 2. Pack and sign the plugin

The normal default output path is:

```text
examples/plugins/octoprint-navbartemp-port/builds/octoprint-navbartemp-port.w3dp
```

On this machine that existing `builds/` directory was already root-owned from an earlier container-side artifact, so the actual release run used a fresh explicit output path:

```bash
python3 scripts/plugin_sign.py \
  examples/plugins/octoprint-navbartemp-port \
  --private-key /home/facuarmo/.config/wprint3d/plugin-signing/wprint3d-official-registry.pem \
  --output /tmp/octoprint-navbartemp-port-release-final-20260315-0833.w3dp \
  --write-public-key /tmp/octoprint-navbartemp-port-release-final-20260315-0833.pub.pem
```

That command:

- builds the package
- signs `plugin.json`
- verifies the resulting archive
- writes the embedded public key to a standalone PEM file

## 3. Verify the package metadata

Inspect it:

```bash
python3 scripts/plugin_verify_signature.py inspect \
  /tmp/octoprint-navbartemp-port-release-final-20260315-0833.w3dp \
  --json
```

Result from this run:

```json
{
  "package": "/tmp/octoprint-navbartemp-port-release-final-20260315-0833.w3dp",
  "pluginId": "octoprint.navbartemp-port",
  "version": "0.1.0",
  "algorithm": "openssl-sha256",
  "keyId": "91e51549bf7bf03df729bde4901d78c1bc055a1a",
  "publicKeySha256": "f574ea60be1517ed0ed8692f609561e91178773435c38d06a7486e71b124211e",
  "embeddedPublicKeyMatchesKeyId": true,
  "hasEmbeddedPublicKey": true
}
```

Compute the package checksum:

```bash
sha256sum /tmp/octoprint-navbartemp-port-release-final-20260315-0833.w3dp
```

Result from this run:

```text
b6ae0be4a55f5b0b39a6a8496e8a1c8b87d4f4a8bae9da3aacc47905514cb9ca  /tmp/octoprint-navbartemp-port-release-final-20260315-0833.w3dp
```

## 4. Initialize the registry repository

This bootstrap created a new local repo at:

```text
/home/facuarmo/plugin-registry
```

Initialization:

```bash
mkdir -p /home/facuarmo/plugin-registry/packages/octoprint-navbartemp-port/0.1.0
mkdir -p /home/facuarmo/plugin-registry/signers
git -C /home/facuarmo/plugin-registry init -b main
git -C /home/facuarmo/plugin-registry remote add origin https://github.com/wprint3d/plugin-registry.git
```

## 5. Copy the release package and signer key into the registry repo

```bash
cp /tmp/octoprint-navbartemp-port-release-final-20260315-0833.w3dp \
  /home/facuarmo/plugin-registry/packages/octoprint-navbartemp-port/0.1.0/octoprint-navbartemp-port.w3dp

cp /tmp/octoprint-navbartemp-port-release-final-20260315-0833.pub.pem \
  /home/facuarmo/plugin-registry/signers/octoprint.navbartemp-port.pub.pem
```

Also add the signer manifest consumed by `plugin:sync-trusted-keys`:

```json
{
  "keys": [
    {
      "id": "octoprint.navbartemp-port",
      "url": "https://raw.githubusercontent.com/wprint3d/plugin-registry/main/signers/octoprint.navbartemp-port.pub.pem",
      "publicKeySha256": "f574ea60be1517ed0ed8692f609561e91178773435c38d06a7486e71b124211e"
    }
  ]
}
```

## 6. Add the first `index.json` entry

The initialized registry now uses:

- [plugin-registry/index.json](/home/facuarmo/plugin-registry/index.json)
- [plugin-registry/README.md](/home/facuarmo/plugin-registry/README.md)
- [plugin-registry/signers/index.json](/home/facuarmo/plugin-registry/signers/index.json)
- [octoprint-navbartemp-port.w3dp](/home/facuarmo/plugin-registry/packages/octoprint-navbartemp-port/0.1.0/octoprint-navbartemp-port.w3dp)
- [octoprint.navbartemp-port.pub.pem](/home/facuarmo/plugin-registry/signers/octoprint.navbartemp-port.pub.pem)

The important fields are:

- `packageUrl`
- `latestVersion`
- `homepageUrl`
- `documentationUrl`
- `sourceUrl`
- `versions[0].sha256`
- `versions[0].publicKeySha256`

Those URL fields should match the canonical values in `plugin.json`, not the monorepo path that was used while developing the plugin.

The package URL targets the future GitHub raw URL:

```text
https://raw.githubusercontent.com/wprint3d/plugin-registry/main/packages/octoprint-navbartemp-port/0.1.0/octoprint-navbartemp-port.w3dp
```

## 7. Publish the registry repo

Once the GitHub repository exists:

```bash
git -C /home/facuarmo/plugin-registry add .
git -C /home/facuarmo/plugin-registry commit -m "feat: bootstrap public plugin registry"
git -C /home/facuarmo/plugin-registry push -u origin main
```

At that point the default registry index URL used by WPrint 3D and the landing page becomes valid:

```text
https://raw.githubusercontent.com/wprint3d/plugin-registry/main/index.json
```

And signer sync can use:

```bash
php artisan plugin:sync-trusted-keys
```

## 8. Open the first registry PR workflow

For the first plugin release, include in the PR description:

- plugin ID: `octoprint.navbartemp-port`
- version: `0.1.0`
- repository URL: `https://github.com/wprint3d/OctoPrint-NavbarTemp-Port`
- package URL
- checksum: `b6ae0be4a55f5b0b39a6a8496e8a1c8b87d4f4a8bae9da3aacc47905514cb9ca`
- signer fingerprint: `f574ea60be1517ed0ed8692f609561e91178773435c38d06a7486e71b124211e`
- the embedded public key PEM

## 9. Landing-site support

The showcase site in `/home/facuarmo/wprint3d.github.io` now:

- reads the registry index URL from env instead of hardcoding it everywhere
- supports the real registry `versions[]` shape and derives `latestVersion`, `packageUrl`, and docs URLs from it
- can be feature-flagged on with `EXPO_PUBLIC_PLUGIN_SYSTEM_ENABLED=true`

Useful local commands:

```bash
EXPO_PUBLIC_PLUGIN_SYSTEM_ENABLED=true \
EXPO_PUBLIC_PLUGIN_REGISTRY_INDEX_URL=https://raw.githubusercontent.com/wprint3d/plugin-registry/main/index.json \
pnpm --dir /home/facuarmo/wprint3d.github.io exec expo export -p web
```

## Notes

- The current local `examples/plugins/octoprint-navbartemp-port/builds/` directory is stale and root-owned on this machine. That is why this publication flow used `--output /tmp/...`.
- `plugin:pack` now fails with a clear writable-path error instead of surfacing a raw `ZipArchive::close()` failure when the target path is blocked.
