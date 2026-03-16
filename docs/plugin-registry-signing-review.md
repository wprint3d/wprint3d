# Public Registry Signing Review Guide

This guide is for maintainers reviewing plugin submissions for the public registry.

The goal is simple:

- reject unsigned or tampered releases
- preserve key continuity across versions
- keep the registry index aligned with auditable release artifacts

## Review Inputs

Every submission should give you:

- the plugin repository URL
- the release version
- the `.w3dp` download URL
- the plugin ID
- the signer public-key SHA-256 fingerprint
- for a first release, the embedded public key PEM in the PR description

If any of those are missing, ask the developer to fill the gaps before you review the package itself.

## Tools

Use the verification helper from a `wprint3d-core` checkout:

```bash
./plugin.sh verify /path/to/plugin.w3dp
./plugin.sh verify /path/to/plugin.w3dp --require-trusted
python3 scripts/plugin_verify_signature.py inspect /path/to/plugin.w3dp
python3 scripts/plugin_verify_signature.py verify /path/to/plugin.w3dp
```

To export the embedded public key for manual review:

```bash
python3 scripts/plugin_verify_signature.py inspect \
  /path/to/plugin.w3dp \
  --write-public-key tmp/plugin-signer.pub.pem
```

## Review Workflow

### 1. Download the candidate package

Use the PR’s release URL or attached artifact and keep a local copy.

### 2. Confirm the package is actually signed

```bash
python3 scripts/plugin_verify_signature.py inspect candidate.w3dp
python3 scripts/plugin_verify_signature.py verify candidate.w3dp
```

Reject the submission if:

- `Algorithm` is `none`
- `Embedded public key` is `no`
- `Verified` is `no`

### 3. Check signer continuity

If the plugin already has a public release in the registry, compare against the last accepted package:

```bash
python3 scripts/plugin_verify_signature.py verify \
  candidate.w3dp \
  --previous-package previous-release.w3dp
```

Expected result:

- `Verified: yes`
- `Matches previous release key: yes`

If the key changed:

- do not merge immediately
- ask the maintainer to explain the rotation in the PR
- require out-of-band confirmation if the plugin is already trusted by many users

### 4. First-release handling

If there is no previous release:

1. export the embedded public key
2. compare the embedded public key against the PEM pasted in the PR description
3. record the public-key SHA-256 fingerprint in the PR discussion
4. use that key as the continuity anchor for future releases
5. add that key to `signers/index.json` when you update the registry PR

### 5. Sanity-check the plugin package

At minimum:

- inspect `plugin.json`
- confirm `id`, `version`, and metadata match the PR
- confirm the package URL and repository URL belong to the claimed maintainer
- if the plugin is an update, confirm the version increment is sensible

If you have the stack available, also install the package in a test environment and confirm it loads without obvious runtime failure.

## What To Ask The Maintainer When Something Is Wrong

### Missing signature

Ask them to rebuild through the signing helper:

```bash
python3 scripts/plugin_sign.py path/to/plugin \
  --private-key ../plugin-signing/plugin-signing.pem
```

Tell them to resubmit the new `.w3dp` and include the verification output in the PR.

### Invalid signature

Ask them to:

1. rebuild from clean source
2. re-sign the package
3. rerun:

   ```bash
   python3 scripts/plugin_verify_signature.py verify path/to/package.w3dp
   ```

4. post the successful output in the PR

### Unexpected key rotation

Ask them to explain:

- why the key changed
- whether the previous key was compromised
- whether users need to trust a new signing identity

Do not merge until the explanation is credible and documented in the PR.

## Registry Update Workflow

The public registry is still PR-driven. The maintainer-side flow should be:

1. verify the candidate package signature
2. verify key continuity against the previous accepted release
3. confirm the package URL is stable and downloadable
4. update the registry index entry
5. update `signers/index.json` so WPrint 3D instances can auto-sync the accepted signer key
6. merge the PR only after the signature review passes

## Local Restore And Inspection

If you need to inspect or salvage a package that is no longer in the registry, restore it into a disposable source tree:

```bash
./plugin.sh restore candidate.w3dp --output plugins/candidate-restore
```

That is useful for:

- manual review of removed plugins
- emergency republishing
- helping maintainers fork and recover abandoned plugins

## Suggested Registry Entry Shape

WPrint 3D resolves `packageUrl` directly, so a minimal entry can look like this:

```json
{
  "plugins": [
    {
      "id": "acme.hello-world",
      "name": "ACME Hello World",
      "description": "Example plugin",
      "author": "ACME",
      "versions": [
        {
          "version": "1.2.3",
          "packageUrl": "https://github.com/acme/hello-world/releases/download/v1.2.3/hello-world.w3dp",
          "releasedAt": "2026-03-15",
          "sha256": "..."
        }
      ]
    }
  ]
}
```

If the registry stores additional metadata, keep it consistent with the submitted manifest and release notes.

The registry should also publish a signer manifest at `signers/index.json`, for example:

```json
{
  "keys": [
    {
      "id": "acme.hello-world",
      "url": "https://raw.githubusercontent.com/wprint3d/plugin-registry/main/signers/acme.hello-world.pub.pem",
      "publicKeySha256": "..."
    }
  ]
}
```

WPrint 3D uses that file when `plugin:sync-trusted-keys` downloads trusted signer keys from the official registry and any marketplace-declared trusted registries.

## Best Practices

- Treat the previous accepted release as the signing trust anchor.
- Prefer stable maintainer-controlled release URLs.
- Keep a local archive of accepted registry packages for continuity checks.
- Record the accepted public-key SHA-256 fingerprint in the PR thread for each first release.
- Do not manually “fix” unsigned packages on behalf of developers. They need to sign and resubmit their own release artifacts.
- If a package is invalid, fail closed and ask for a rebuilt artifact.
