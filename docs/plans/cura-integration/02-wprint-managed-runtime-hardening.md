# WPrint Managed Runtime Hardening Plan

**Goal:** Turn the existing heavyweight-plugin proof of concept into a deterministic, isolated, persistent, update-safe managed-runtime facility suitable for CuraEngine.

**Repository:** `wprint3d-core`.

**Prerequisite:** Accept every item in the architecture checklist in `01-architecture-and-contracts.md`.

**Do not modify yet:** Runtime HTTP proxying, Cura-specific UI, gateway code, or built-in plugin installation belong to later plans.

## Baseline to preserve

The current implementation is centered in:

- `app/Plugins/PluginManifestValidator.php`;
- `app/Plugins/PluginDependencyService.php`;
- `app/Plugins/PluginManagerService.php`;
- `app/Plugins/Runtimes/BridgePluginRuntimeAdapter.php`;
- `config/plugins.php`;
- `tests/Unit/Plugins/PluginManifestValidatorTest.php`;
- `tests/Unit/Plugins/PluginDependencyServiceTest.php`;
- `tests/Unit/Plugins/PluginManagerServiceTest.php`.

The backend image already contains Docker CLI and the backend service mounts the Docker socket. Preserve current lightweight plugins and SDK revisions 0–4.

## Task 1: Add SDK revision 5 manifest tests

**Files:**

- Modify `config/plugins.php`.
- Modify `app/Plugins/PluginManifestValidator.php`.
- Modify `tests/Unit/Plugins/PluginManifestValidatorTest.php`.
- Modify `docs/plugin-sdk-reference.md`.
- Modify `docs/plugin-sdk-changelog.md`.

### Step 1.1: Write failing acceptance tests

Add one valid revision-5 manifest fixture containing:

- `requirements.diskMb`;
- `images[0].pullTimeoutSecs`;
- `service.storage.mountPath` and `retainOnUninstall`;
- `service.resources.memoryMb`, `cpuCores`, and `pids`;
- `service.security.readOnlyRootFilesystem`, `noNewPrivileges`, `capDrop`, `tmpfs`, and `user`;
- `service.stopGracePeriodSecs`;
- `runtime.httpProxy`;
- `runtime.artifactImports`;
- `uiExtensions[0].presentation = workspace` on a `page/custom_bundle` extension;
- `integrity.algorithm` and `integrity.files`.

Assert normalized numeric and boolean types, trimmed strings, normalized methods, and default values.

Add separate rejection tests for:

- SDK revision 4 using any revision-5-only field;
- `workspace` presentation on a non-`page` surface;
- `workspace` presentation on a non-`custom_bundle` mode;
- storage mount path that is relative, `/`, `/var/run/docker.sock`, or includes `..`;
- more than one writable storage mount;
- arbitrary host/source volume paths;
- `capDrop` values outside the host allowlist;
- tmpfs paths outside `/tmp` and `/run`;
- tmpfs size at or below zero;
- service user equal to `root`, `0`, `0:0`, or empty;
- proxy prefix not starting with `/` or containing `..`;
- proxy methods outside `GET`, `POST`, `PUT`, `PATCH`, and `DELETE`;
- proxy upload size exceeding the host maximum;
- artifact pattern that is invalid regex, not anchored with `^` and `$`, or permits a full URL;
- an integrity path containing traversal;
- an integrity digest that is not 64 lowercase hexadecimal characters;
- duplicate image IDs or duplicate artifact-import IDs;
- a release-policy helper accepting an official image without `@sha256:`.

Do not put all cases in one test. Each failure should name the contract it protects. The low-level manifest validator validates `integrity` when present. It may allow the field to be absent in an unpacked development source; the archive packager/installer enforces a complete integrity map for signed revision-5 release packages.

### Step 1.2: Run the focused test and confirm red

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins/PluginManifestValidatorTest.php
```

Expected: failures for unsupported SDK revision and missing normalization.

### Step 1.3: Implement revision-aware normalization

Set SDK revision 5 as current while keeping revisions 0–4 in `config/plugins.php`.

In `PluginManifestValidator`, split normalization into focused private methods instead of expanding `normalizeImages()` indefinitely:

```text
normalizeRequirements
normalizeImages
normalizeImageHealthcheck
normalizeManagedService
normalizeManagedStorage
normalizeManagedResources
normalizeManagedSecurity
normalizeRuntimeHttpProxy
normalizeArtifactImports
normalizeIntegrity
normalizeUiPresentation
```

Pass `sdkRevision` into revision-sensitive methods. Reject new fields on old revisions rather than silently ignoring them.

Host limits belong in `config/plugins.php`, not literals scattered through validation:

```php
'container' => [
    'pull_timeout_secs' => 900,
    'max_pull_timeout_secs' => 1800,
    'max_memory_mb' => 16384,
    'max_cpu_cores' => 16,
    'max_pids' => 4096,
    'max_tmpfs_mb' => 4096,
    'allowed_cap_drops' => ['ALL'],
],
'proxy' => [
    'max_upload_mb' => 512,
    'max_stream_timeout_secs' => 1800,
],
```

Use conservative defaults for revision 5:

- pull timeout: 900 seconds;
- stop grace: 30 seconds;
- read-only root: true;
- no-new-privileges: true;
- capability drop: `ALL`;
- PID limit: 256;
- storage retention: true.

Do not silently clamp a manifest value that exceeds a security limit. Reject it with a clear validation message. Defaults may be normalized.

### Step 1.4: Document the exact schema

Update the SDK reference and changelog with:

- a complete valid revision-5 example;
- which fields are enforced versus advisory;
- the prohibition on arbitrary host mounts;
- digest rules for official/built-in plugins;
- compatibility behavior for revisions 0–4.

### Step 1.5: Run tests and formatter

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins/PluginManifestValidatorTest.php

docker compose -f docker-compose-development.yml exec -T backend \
  ./vendor/bin/pint --test app/Plugins/PluginManifestValidator.php config/plugins.php \
  tests/Unit/Plugins/PluginManifestValidatorTest.php
```

Expected: pass.

## Task 2: Introduce a testable Docker runtime client

**Files:**

- Create `app/Plugins/Containers/ContainerCommandResult.php`.
- Create `app/Plugins/Containers/PluginContainerClient.php`.
- Create `app/Plugins/Containers/DockerPluginContainerClient.php`.
- Create `app/Plugins/Containers/ManagedContainerSpec.php`.
- Create `app/Plugins/Containers/ManagedContainerInspection.php`.
- Modify `app/Providers/AppServiceProvider.php`.
- Create `tests/Unit/Plugins/Containers/DockerPluginContainerClientTest.php`.
- Modify `app/Plugins/PluginDependencyService.php`.

### Step 2.1: Define the interface before implementation

`PluginContainerClient` must expose typed operations, not a generic public `run(array $command)` escape hatch:

```text
pull(image, timeoutSecs)
runImageHealthcheck(image, command, timeoutSecs)
inspectContainer(name)
inspectImageDigest(image)
inspectCurrentContainerNetworks(currentContainerName)
createContainer(spec)
startContainer(name)
stopContainer(name, graceSecs)
removeContainer(name, force)
renameContainer(currentName, nextName)
connectNetwork(name, network, aliases)
disconnectNetwork(name, network)
createVolume(name, labels)
inspectVolume(name)
removeVolume(name)
logs(name, tailLines)
```

`ManagedContainerSpec` contains only normalized values. Its `toDockerArguments()` method may be private to the Docker implementation; other core code should not concatenate flags.

### Step 2.2: Write command-construction tests

Use a fake command runner. Assert commands are argument arrays, never shell strings.

Cover:

- image digest reference remains one argument;
- environment values containing whitespace remain one argument and are never logged verbatim when marked secret;
- named-volume mount is generated without a host path;
- `--read-only`, `--security-opt=no-new-privileges`, `--cap-drop=ALL`, `--tmpfs`, `--memory`, `--cpus`, `--pids-limit`, `--user`, labels, network, and alias are present;
- no `--privileged`, socket mount, device mount, published host port, or arbitrary `--volume` can be introduced;
- pull timeout is applied per operation;
- image inspection obtains the repository digest actually present locally;
- container inspection reads running state, image digest, labels, networks, aliases, and health status;
- failures retain bounded stderr for diagnostics and redact known secrets.

### Step 2.3: Implement the Docker client

Continue using Laravel `Process`, but isolate it inside `DockerPluginContainerClient`. Use per-operation timeouts. Set a maximum diagnostic output length, such as the final 16 KiB.

Container labels must include:

```text
wprint3d.managed=true
wprint3d.plugin.id=<plugin id>
wprint3d.plugin.image_id=<image id>
wprint3d.plugin.version=<plugin version>
wprint3d.plugin.runtime_spec_hash=<sha256>
```

Never log full environment arguments. Log the variable names and redact values.

### Step 2.4: Bind the implementation

Register `PluginContainerClient` as a singleton bound to `DockerPluginContainerClient`. Tests can replace the interface with a fake.

Refactor `PluginDependencyService` to depend on the interface. Preserve existing behavior before adding new lifecycle semantics.

### Step 2.5: Verify

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins/Containers/DockerPluginContainerClientTest.php \
  tests/Unit/Plugins/PluginDependencyServiceTest.php
```

Expected: pass and no direct `Process` calls remain in `PluginDependencyService`.

## Task 3: Add deterministic runtime specs and private storage

**Files:**

- Create `app/Plugins/ManagedRuntimeSpecFactory.php`.
- Create `app/Plugins/PluginRuntimeSecretStore.php`.
- Modify `app/Plugins/PluginDependencyService.php`.
- Modify `app/Models/Plugin.php` only if casts/hidden fields are required.
- Create `tests/Unit/Plugins/ManagedRuntimeSpecFactoryTest.php`.
- Create `tests/Unit/Plugins/PluginRuntimeSecretStoreTest.php`.
- Modify `tests/Unit/Plugins/PluginDependencyServiceTest.php`.

### Step 3.1: Specify deterministic identities

Use sanitized IDs plus a short hash to prevent collisions and length overflow:

```text
canonical container: wprint3d-plugin-<plugin-slug>-<image-slug>-<hash>
candidate container: <canonical>-candidate-<short-version-hash>
private volume: wprint3d-plugin-<plugin-slug>-data-<hash>
```

The hash input includes the original unsanitized plugin ID. Add tests showing that `acme.foo_bar` and `acme.foo-bar` do not collide.

### Step 3.2: Compute `runtimeSpecHash`

Canonicalize normalized runtime fields recursively with stable key ordering, excluding:

- generated bearer plaintext;
- observed status and timestamps;
- transient candidate container name.

Include the current WPrint network name and resolved local image digest. Two semantically identical manifests must produce the same hash regardless of source JSON key order.

### Step 3.3: Create private volume behavior

For `service.storage`:

- create the deterministic named volume if missing;
- label it with plugin ID and `wprint3d.managed=true`;
- mount it only at the validated target path;
- record volume name, mount path, and retention policy in dependency state;
- never expose the volume name through the public plugin API unless the user is an administrator viewing diagnostics;
- do not mount WPrint's main `storage` volume into the sidecar.

### Step 3.4: Create secret behavior

`PluginRuntimeSecretStore` must:

- generate 32 random bytes and encode them URL-safely;
- encrypt at rest with Laravel `Crypt`;
- reuse the token across normal restarts;
- rotate it during explicit security rotation and successful major runtime replacement only when requested;
- return plaintext only to the container spec and bridge HTTP client;
- redact it from serialization, logs, warnings, exceptions, and tests.

Store only encrypted material in `dependency_state.runtime.authTokenEncrypted`. Add a test that serialized plugin responses do not contain either the plaintext token or the encrypted field.

### Step 3.5: Build the enforced spec

The spec factory combines:

- validated manifest service fields;
- host maximums;
- named volume;
- generated WPrint environment variables;
- resolved network;
- image digest;
- labels;
- generated token as a secret environment value.

The manifest may not override reserved variables beginning with `WPRINT3D_`. Reject such manifests during validation.

### Step 3.6: Verify

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins/ManagedRuntimeSpecFactoryTest.php \
  tests/Unit/Plugins/PluginRuntimeSecretStoreTest.php \
  tests/Unit/Plugins/PluginDependencyServiceTest.php
```

## Task 4: Fix prepare and image verification

**Files:**

- Modify `app/Plugins/PluginDependencyService.php`.
- Modify `tests/Unit/Plugins/PluginDependencyServiceTest.php`.
- Modify `config/plugins.php`.

### Step 4.1: Write failing tests

Cover:

- each image uses its `pullTimeoutSecs`;
- a digest-pinned image is inspected after pull and must match the declared digest;
- an official or built-in source without a digest is rejected before pull;
- third-party manifests may use a tag but receive a warning;
- `healthcheck.timeoutSecs` is actually used;
- the image health command runs with an explicit entrypoint strategy so it is not accidentally appended to the service entrypoint;
- failure reports image ID, bounded stderr, and retry guidance without secrets;
- preparing multiple images stops at the first failure and returns no successful installed state.

### Step 4.2: Implement source-aware image policy

Pass install-source trust context into dependency preparation. Do not infer “official” from the plugin manifest. Official/built-in status comes from the WPrint registry source or built-in index.

Accept a digest either as part of the image reference (`name@sha256:...`) or, if a separate manifest field is added, normalize it into the exact pull reference. Prefer one canonical stored representation to avoid conflicting tag/digest fields.

### Step 4.3: Correct image healthcheck execution

The current `docker run IMAGE command...` can append arguments to an image entrypoint instead of running the intended health command. Use an explicit safe mechanism:

- revision-5 image healthchecks must declare an executable path that exists in the image;
- invoke with `--entrypoint <first-command-element>` and pass the remaining arguments;
- apply no network unless the check explicitly needs the plugin network after service startup;
- apply a read-only root and bounded tmpfs for the one-shot check;
- remove the check container automatically.

Runtime HTTP health remains the authoritative readiness gate after starting the service.

### Step 4.4: Verify

Run the dependency tests and confirm timeout values are asserted, not merely normalized.

## Task 5: Implement blue/green activation and rollback

**Files:**

- Create `app/Plugins/ManagedPluginRuntimeService.php`.
- Create `app/Plugins/ManagedRuntimeActivationResult.php`.
- Modify `app/Plugins/PluginDependencyService.php`.
- Modify `app/Plugins/PluginManagerService.php`.
- Modify `app/Plugins/Runtimes/BridgePluginRuntimeAdapter.php`.
- Create `tests/Unit/Plugins/ManagedPluginRuntimeServiceTest.php`.
- Modify `tests/Unit/Plugins/PluginManagerServiceTest.php`.

### Step 5.1: Write the state-transition tests

Cover at least:

1. no container → create canonical → health pass → ready;
2. stopped matching container → start → health pass;
3. running matching container → no recreate → health pass;
4. running stale spec → create candidate → candidate health pass → replace canonical;
5. stale spec → candidate health fails → remove candidate → old remains running and ready;
6. candidate create fails → old remains untouched;
7. canonical rename/switch fails → restore old name/alias and report failure;
8. plugin disabled during candidate activation → candidate is removed;
9. token is sent by `BridgePluginRuntimeAdapter` but never returned publicly;
10. two concurrent activation attempts are serialized with a plugin-scoped lock.

### Step 5.2: Separate desired-state activation from dependency summary

`PluginDependencyService` may continue to summarize and prepare dependencies. Move container replacement logic into `ManagedPluginRuntimeService` so activation and reconciliation share one code path.

Acquire a distributed/cache lock keyed by plugin ID before mutating containers. Choose a TTL longer than pull plus healthcheck time and renew it or use a bounded operation that cannot exceed the TTL.

### Step 5.3: Candidate readiness

Candidate containers must not claim the canonical network alias before they are healthy. Start them with a candidate alias, then call the bridge health endpoint at that alias using the internal token.

After health succeeds:

1. stop the old canonical container with the declared grace period;
2. disconnect/remove its canonical alias or rename it to a rollback name;
3. attach the candidate to the canonical alias;
4. persist new dependency state;
5. remove the old container only after persistence succeeds.

If any switch step fails, restore the previous alias/container when possible and keep the previous plugin version active.

### Step 5.4: Make bridge calls authenticated

Update `BridgePluginRuntimeAdapter` and the managed health client to attach the decrypted bearer token for managed runtimes. Static `baseUrl` bridge plugins retain their current behavior unless they explicitly configure another supported auth mechanism.

### Step 5.5: Preserve public failure semantics

An initial enable failure:

- sets `enabled=false`;
- sets `load_status=failed`;
- records the bounded error and timestamp;
- removes the failed candidate;
- preserves the private volume.

An update failure while a previous version is healthy:

- keeps `enabled=true`;
- keeps `load_status=ready`;
- keeps `current_version` on the previous version;
- records `last_update_error` separately from runtime readiness;
- preserves the previous container and package.

Do not downgrade a healthy plugin to disabled merely because its replacement failed.

### Step 5.6: Verify

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins/ManagedPluginRuntimeServiceTest.php \
  tests/Unit/Plugins/PluginManagerServiceTest.php \
  tests/Unit/Plugins/PluginDependencyServiceTest.php
```

## Task 6: Make package installation and update transactional

**Files:**

- Modify `app/Plugins/PluginArchiveService.php`.
- Modify `app/Plugins/PluginManagerService.php`.
- Create `app/Plugins/PluginInstallTransaction.php` if it keeps cleanup/rollback comprehensible.
- Modify `tests/Unit/Plugins/PluginManagerServiceTest.php`.
- Modify `tests/Unit/Plugins/PluginArchiveServiceTest.php`.

### Step 6.1: Add staging primitives

The archive service needs explicit operations:

```text
extractToStaging(package)
promoteStaging(stagingPath, finalVersionPath)
discardStaging(stagingPath)
```

Staging must be on the same filesystem as final packages so promotion can be an atomic rename. Generate a unique staging path below the plugin package root.

Before extraction, validate archive entries against zip-slip and link attacks:

- no absolute paths;
- no `..` segments;
- no NULs;
- no symlink entries;
- bounded entry count and total uncompressed size;
- exactly one root `plugin.json`.

### Step 6.2: Sign asset integrity

Revision-5 release packages include `integrity.files`. The packager calculates hashes after copying production assets and before signing the manifest. Any source-tree integrity map is advisory and must be recalculated; a mismatch fails packaging instead of being silently signed. Inspection verifies every declared hash and rejects undeclared executable/browser assets according to an explicit allowlist policy.

Implement a focused `PluginAssetIntegrityService` used by both packager and archive inspection. The packager order is:

1. structurally validate the source manifest and declared asset paths;
2. copy the exact package source into staging;
3. calculate the complete integrity map from staged files;
4. compare it with a source-provided map when present and fail on mismatch;
5. insert the authoritative map;
6. run full revision-5 validation;
7. sign the manifest containing that map;
8. write the signed manifest and ZIP the staged tree.

At minimum, hash:

- every file under declared asset paths;
- PHP runtime/action/hook files when present;
- build metadata and licenses.

The signature must cover the integrity map. Add tamper tests that modify `ui/index.html` after signing and verify installation fails.

### Step 6.3: Write transaction failure tests

Cover failures during:

- archive validation;
- extraction;
- image pull;
- image healthcheck;
- package promotion;
- candidate activation;
- Mongo save after candidate readiness.

For new install, assert no plugin record and no promoted directory. For update, assert previous manifest, version path, dependency state, container, and enabled status remain unchanged.

### Step 6.4: Implement transaction order

Follow the exact sequences in `01-architecture-and-contracts.md`. Do not overwrite the current version directory before the replacement is accepted.

Keep old version directories needed for rollback. Add a bounded retention policy, for example current plus one previous successful version, but never clean previous versions in the same transaction that activates a new version. Cleanup happens after success.

### Step 6.5: Verify

Run archive, packager, manager, signature, and managed-runtime tests together.

## Task 7: Reconcile enabled runtimes on application bootstrap

**Files:**

- Create `app/Console/Commands/PluginReconcileRuntimes.php`.
- Add method to `app/Plugins/Contracts/PluginManager.php`.
- Modify `app/Plugins/PluginManagerService.php`.
- Modify `app/Console/Commands/BootstrapRuntime.php`.
- Create `tests/Feature/Plugins/PluginReconcileRuntimesTest.php`.
- Modify `tests/Unit/BootstrapRuntimeTest.php` or the existing bootstrap command test location.

### Step 7.1: Define command output and exit behavior

Command:

```bash
php artisan plugin:reconcile-runtime
```

Return a structured summary:

```json
{
  "checked": 3,
  "ready": 2,
  "repaired": 1,
  "failed": 0,
  "disabledCleaned": 0
}
```

One plugin failure must not prevent reconciliation of the remaining plugins and must not fail WPrint bootstrap globally. The command may exit non-zero only for a host-wide inability to inspect Docker, and bootstrap must convert that into a visible warning rather than stopping printer services.

### Step 7.2: Write tests

Cover:

- enabled missing container recreated;
- enabled stopped container restarted;
- enabled stale container replaced;
- enabled healthy matching container left untouched;
- disabled plugin container removed;
- failed plugin records actionable lifecycle logs;
- lightweight and static bridge plugins are skipped;
- command continues after a single plugin failure;
- no bearer secret appears in command output.

### Step 7.3: Add bootstrap step

Call reconciliation after migrations and configuration declaration, when Mongo and Docker are available, but before the UI reports plugins ready. Do not run it in mapper/streamer roles. Use the server bootstrap path only.

Because Docker's `unless-stopped` is still useful, retain it. Reconciliation supplements restart policy; it does not replace it.

### Step 7.4: Verify with a disposable test container

After unit/feature tests pass, perform an opt-in development-stack smoke test using a tiny pinned image, not the Cura image:

1. install a development managed-bridge fixture;
2. enable it;
3. verify labels/network/health;
4. stop it and run reconcile;
5. verify it restarts;
6. change the fixture image/spec and verify replacement;
7. disable and verify container removal;
8. verify its volume remains.

Do not use `latest` in the fixture committed to the repository.

## Task 8: Add explicit data deletion and diagnostics

**Files:**

- Modify `app/Http/Controllers/PluginController.php`.
- Modify `routes/api.php`.
- Modify `app/Plugins/PluginManagerService.php`.
- Modify `frontend/components/NavBarMenuSettingsModalPlugins.js`.
- Modify `frontend/config/translations.js`.
- Add/modify focused backend and frontend tests.

This is an admin-facing UI change. Before implementation, re-read `DESIGN.md` and inspect the existing plugin inventory in light/dark themes and mobile/desktop layouts.

### Step 8.1: Extend diagnostics

Admin diagnostics may expose:

- desired image reference and resolved digest;
- container running/health state;
- runtime spec hash match;
- image ID;
- volume retained/present status and approximate size if available;
- last healthcheck and bounded log tail;
- last reconciliation/update error.

Never expose environment values, auth token material, raw Docker inspect output, or host filesystem paths.

### Step 8.2: Add delete-data action

Uninstall continues to retain data by default. Add a separate administrator action requiring explicit confirmation to delete retained storage. The request must identify the plugin and expected volume identity; the server resolves the actual deterministic volume itself.

The confirmation copy must state that sliced projects and pending artifacts become unrecoverable. Use WPrint error/destructive styling and preserve the existing plugin manager layout.

### Step 8.3: Verify

Test retained volume on uninstall, explicit deletion, missing volume idempotency, refusal to delete non-WPrint-managed volumes, and frontend confirmation behavior.

## Phase gate

Run:

```bash
docker compose -f docker-compose-development.yml exec -T backend \
  php artisan test tests/Unit/Plugins tests/Feature/Plugins

docker compose -f docker-compose-development.yml exec -T backend \
  ./vendor/bin/pint --test app/Plugins app/Console/Commands app/Http/Controllers/PluginController.php \
  config/plugins.php tests/Unit/Plugins tests/Feature/Plugins
```

Then perform the disposable-container smoke test.

The phase is complete only if:

- revision-5 manifests validate and old revisions still pass;
- a managed volume is created without accepting a host path;
- Docker flags enforce the declared resource/security policy;
- secrets are encrypted and redacted;
- stale image/spec updates replace containers;
- failed updates preserve a healthy previous version;
- bootstrap repairs missing/stopped runtimes;
- disable removes the container and preserves storage;
- explicit data deletion cannot target unmanaged volumes;
- all plugin lifecycle tests pass.
