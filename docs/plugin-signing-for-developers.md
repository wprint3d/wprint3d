# Plugin Signing For Developers

Signing matters because a `.w3dp` archive is executable plugin code. A valid signature gives users and registry maintainers a way to confirm that a package really came from you and that the manifest was not modified after you built it.

WPrint 3D already warns when someone tries to install an unsigned plugin. Signing does not replace good release notes or code review, but it does establish publisher continuity across releases.

## Prerequisites

- A full `wprint3d-core` source checkout.
- Your plugin directory inside that checkout.
- `openssl`.
- A private key that stays outside the plugin directory and out of git.

## What A Signed Package Carries

When you sign a package, WPrint 3D embeds this metadata in `plugin.json -> signature` inside the `.w3dp` archive:

- `algorithm`
- `keyId`
- `publicKey`
- `publicKeySha256`
- `value`

That makes the archive self-describing enough for users and registry maintainers to inspect and verify it locally.

## Recommended Flow

### 1. Generate one long-lived signing key

Use one stable key per publisher or team, not one key per release.

```bash
mkdir -p ~/.config/wprint3d/plugin-signing
openssl genpkey \
  -algorithm RSA \
  -pkeyopt rsa_keygen_bits:4096 \
  -out ~/.config/wprint3d/plugin-signing/acme-plugin.pem
```

Optional: extract the public key now for your own records.

```bash
openssl pkey \
  -in ~/.config/wprint3d/plugin-signing/acme-plugin.pem \
  -pubout \
  -out ~/.config/wprint3d/plugin-signing/acme-plugin.pub.pem
```

### 2. Build and sign the package

Use the helper script from the repo root:

```bash
python3 scripts/plugin_sign.py \
  examples/plugins/hello-world \
  --private-key ~/.config/wprint3d/plugin-signing/acme-plugin.pem
```

That script will:

- run `php artisan plugin:pack`
- sign the manifest
- write the package to `<plugin>/builds/<plugin-dir>.w3dp`
- verify the resulting archive
- extract the embedded public key next to the package as `<plugin>.pub.pem`

Equivalent raw command:

```bash
./plugin.sh pack examples/plugins/hello-world \
  --signing-key=/home/you/.config/wprint3d/plugin-signing/acme-plugin.pem
```

### 3. Inspect the signed archive

```bash
python3 scripts/plugin_verify_signature.py inspect \
  examples/plugins/hello-world/builds/hello-world.w3dp
```

Example output:

```text
Package: /.../examples/plugins/hello-world/builds/hello-world.w3dp
Plugin: wprint3d.hello-world @ 0.1.0
Algorithm: openssl-sha256
Key ID: 8d6c...
Embedded public key: yes
Public key SHA-256: 7f4a...
Key ID matches embedded key: yes
```

### 4. Verify the package before release

```bash
python3 scripts/plugin_verify_signature.py verify \
  examples/plugins/hello-world/builds/hello-world.w3dp
```

If you are rotating nothing and want to confirm continuity against the previous release:

```bash
python3 scripts/plugin_verify_signature.py verify \
  examples/plugins/hello-world/builds/hello-world.w3dp \
  --previous-package /tmp/hello-world-0.0.9.w3dp
```

That should only pass if the new release is signed correctly and uses the same public key as the previous release.

### 5. Publish the release artifact

If you are hosting releases on GitHub, upload the signed package to a release before opening the registry PR:

```bash
php artisan plugin:publish \
  examples/plugins/hello-world/builds/hello-world.w3dp \
  --repo=acme/hello-world \
  --token="$GITHUB_TOKEN"
```

## Publishing To The Public Registry

The current public-registry process is still PR-driven.

### First release

1. Keep the plugin in its own repository.
2. Build and sign the `.w3dp`.
3. Verify the package locally.
4. Upload the signed package to an immutable release URL.
5. Open a PR to the public registry with:
   - the plugin repository URL
   - the release/package URL
   - the plugin ID and version
   - the public-key SHA-256 fingerprint
   - the embedded public key PEM in the PR description
6. Wait for the registry maintainers to review and reach out.

### Subsequent releases

1. Keep using the same signing key unless you are intentionally rotating it.
2. Run:

   ```bash
   python3 scripts/plugin_verify_signature.py verify new-release.w3dp \
     --previous-package previous-release.w3dp
   ```

3. Open the PR with:
   - the plugin repository URL
   - the new package URL
   - the new version
   - the verification output
4. If the key changed, explain why in the PR description and expect manual follow-up.

## Best Practices

- Keep the private key outside the plugin directory.
- Do not commit private keys, generated `.pem` files, or passphrase files.
- Use one stable signing identity across releases.
- Use a passphrase for keys that leave your workstation.
- Rotate keys only for compromise, team ownership change, or a documented security event.
- If you rotate, tell registry maintainers before they discover it from a failed continuity check.
- Treat unsigned releases as test artifacts, not public releases.

## Troubleshooting

### `Package is unsigned`

You packed without `--signing-key`. Rebuild through `plugin_sign.py` or `./plugin.sh pack ... --signing-key=...`.

### `Verified: no`

The archive was modified after signing, the wrong key was used, or the manifest serialization changed outside the packer. Rebuild from clean source and re-sign.

### `Matches previous release key: no`

You changed signing keys. Either revert to the previous key or prepare a documented key-rotation explanation for the registry PR.
