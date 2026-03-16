# Plugin Signature Verification For Users

WPrint 3D already warns when you install an unsigned plugin. This guide exists so the trust model is transparent instead of hidden behind the UI.

## Why You Should Care

A plugin package can run code, render UI, and access host-approved capabilities. Signature verification helps answer two questions:

1. Did this package really come from the publisher it claims to come from?
2. Was the package modified after the publisher built it?

Signature verification does not prove the plugin is safe or bug-free. It only proves publisher continuity and package integrity.

## What The Warnings Mean

- `Signed`: the package signature matches a trusted public key configured in WPrint 3D.
- `Unsigned`: the package has no signature. Treat it like an unverified sideload.
- `Invalid signature`: the package claims to be signed, but the signature does not verify against trusted keys.

## Quick Local Check

From a `wprint3d-core` source checkout:

```bash
./plugin.sh verify /path/to/plugin.w3dp
./plugin.sh verify /path/to/plugin.w3dp --require-trusted
python3 scripts/plugin_verify_signature.py inspect /path/to/plugin.w3dp
python3 scripts/plugin_verify_signature.py verify /path/to/plugin.w3dp
```

If the package embeds a public key and the signature is valid, `verify` should print `Verified: yes`.
If `./plugin.sh verify ... --require-trusted` passes, the package is also signed by a key trusted by the current WPrint 3D instance, including keys synced from trusted registries.

## Compare With A Previous Release

If you already trust an older version of the same plugin, compare the new package against it:

```bash
python3 scripts/plugin_verify_signature.py verify \
  /path/to/new-release.w3dp \
  --previous-package /path/to/old-release.w3dp
```

That should only pass if:

- the new package is correctly signed
- the embedded public key matches the previous release key

## Extract The Public Key

You can export the embedded signer key for your own records:

```bash
python3 scripts/plugin_verify_signature.py inspect \
  /path/to/plugin.w3dp \
  --write-public-key tmp/plugin-signer.pub.pem
```

The output fingerprint is the easiest thing to compare across releases.

## When To Stop And Ask Questions

Be cautious if any of these happen:

- the package is unsigned
- the signature is invalid
- the public key changed unexpectedly between releases
- the maintainer cannot explain which key they use
- the package URL or repository URL changed without explanation

In those cases, do not install the package on a system you care about until the publisher explains the discrepancy.

## Best Practices For Users

- Prefer official-registry packages when they exist.
- Keep a copy of the last trusted release if you install third-party plugins.
- Compare the embedded public-key fingerprint before upgrading a sideloaded plugin.
- Run `./plugin.sh verify <package> --require-trusted` before installing packages that claim to come from a trusted marketplace source.
- If you need to inspect or fork a removed plugin, restore it first with `./plugin.sh restore /path/to/plugin.w3dp --output plugins/plugin-fork` and review the files before reinstalling it.
- Treat first-time third-party plugins as a trust decision, not just a click-through warning.
- If you operate printers for other people, record which plugin version and signer fingerprint you accepted.
