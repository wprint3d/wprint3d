<?php

namespace App\Plugins;

use App\Enums\DataType;
use App\Jobs\PreparePluginRuntime;
use App\Models\Configuration;
use App\Models\Plugin;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\Contracts\PluginManager;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\Runtimes\BridgePluginRuntimeAdapter;
use App\Services\PrinterSlicingConfigurationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class PluginManagerService implements PluginManager
{
    private const AUTOMATIC_UPDATES_CONFIGURATION_KEY = 'pluginAutomaticUpdatesEnabled';

    public function __construct(
        private PluginArchiveService $archiveService,
        private PluginRegistryClient $registryClient,
        private PluginRuntimeRegistry $runtimeRegistry,
        private PluginDependencyService $dependencyService,
        private PluginLifecycleLogStore $lifecycleLogStore,
        private ?PluginManifestLocalizer $manifestLocalizer = null,
    ) {}

    public function sdkMetadata(): array
    {
        return [
            'current' => [
                'version' => (int) config('plugins.sdk.current.version', config('plugins.sdk_version', 1)),
                'revision' => (int) config('plugins.sdk.current.revision', config('plugins.sdk_revision', 0)),
            ],
            'versions' => config('plugins.sdk.versions', []),
        ];
    }

    public function listInstalled(): array
    {
        return Plugin::query()->orderBy('name')->get()->map(fn (Plugin $plugin) => $this->serializePlugin($plugin))->all();
    }

    public function listRegistry(): array
    {
        return collect($this->registryClient->listPackages())
            ->map(fn (array $package) => $this->serializeRegistryPackage($package))
            ->all();
    }

    public function listRegistrySources(): array
    {
        return $this->registryClient->listSources();
    }

    public function saveRegistrySources(array $sources): array
    {
        return $this->registryClient->saveSources($sources);
    }

    public function getPluginPreferences(): array
    {
        return [
            'automaticUpdatesEnabled' => $this->globalAutomaticUpdatesEnabled(),
        ];
    }

    public function updatePluginPreferences(array $preferences): array
    {
        $automaticUpdatesEnabled = (bool) ($preferences['automaticUpdatesEnabled'] ?? true);
        $this->saveGlobalAutomaticUpdatesPreference($automaticUpdatesEnabled);

        $disabledPluginAutomaticUpdatesCount = 0;

        if (! $automaticUpdatesEnabled) {
            $plugins = Plugin::query()
                ->where('automatic_update_enabled', true)
                ->get();

            foreach ($plugins as $plugin) {
                $plugin->automatic_update_enabled = false;
                $plugin->save();
                $disabledPluginAutomaticUpdatesCount++;
            }
        }

        return [
            'automaticUpdatesEnabled' => $automaticUpdatesEnabled,
            'disabledPluginAutomaticUpdatesCount' => $disabledPluginAutomaticUpdatesCount,
        ];
    }

    public function get(string $pluginId): array
    {
        return $this->serializePlugin($this->requireModel($pluginId));
    }

    public function setPluginAutomaticUpdates(string $pluginId, bool $enabled): array
    {
        $plugin = $this->requireModel($pluginId);
        $supported = $this->supportsAutomaticUpdates($plugin);
        $globallyEnabled = $this->globalAutomaticUpdatesEnabled();

        $plugin->automatic_update_enabled = $supported && $globallyEnabled ? $enabled : false;
        $plugin->save();

        return $this->serializePlugin($plugin->fresh() ?? $plugin);
    }

    public function getSettings(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);

        return $this->resolvedPluginSettings($plugin);
    }

    public function updateSettings(string $pluginId, array $settings): array
    {
        $plugin = $this->requireModel($pluginId);
        $plugin->settings = $this->mergeSettings($this->declaredSettingsDefaults($this->decodedManifest($plugin)), $settings);
        $plugin->save();

        return $this->resolvedPluginSettings($plugin->fresh() ?? $plugin);
    }

    public function getState(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);

        return is_array($plugin->state) ? $plugin->state : [];
    }

    public function getLogs(string $pluginId): array
    {
        $this->requireModel($pluginId);

        return $this->lifecycleLogStore->list($pluginId);
    }

    public function installFromArchive(string $archivePath, string $sourceType = 'local_upload', array $sourceMeta = []): array
    {
        $package = $this->archiveService->inspect($archivePath, $sourceType);
        $this->assertCoreCompatibility($package->manifest);

        $runtimePath = $this->archiveService->extract($archivePath, $package->manifest);
        $storagePath = config('plugins.paths.runtime').DIRECTORY_SEPARATOR.$package->manifest['id'];
        @mkdir($storagePath, 0777, true);
        $plugin = Plugin::firstOrNew(['plugin_id' => $package->manifest['id']]);
        $wasExisting = $plugin->exists;
        $previousState = $wasExisting ? $this->pluginStateSnapshot($plugin) : null;
        $queueBackgroundPreparation = false;

        try {
            // Image pulls/digest checks happen before the model is changed. If
            // any dependency fails, the staged package and prior DB state are
            // removed/restored together; retained runtime volumes are untouched.
            $backgroundPreparation = $this->usesBackgroundPreparation($package->manifest)
                && (! $wasExisting || ! (bool) $plugin->enabled);
            $existingDependencyState = $wasExisting && is_array($plugin->dependency_state)
                ? $plugin->dependency_state
                : [];
            $dependencyState = $backgroundPreparation
                ? $this->dependencyService->summarize($package->manifest)
                : $this->dependencyService->prepare($package->manifest, $existingDependencyState);
            [$requirementsBlocked, $dependencyState] = $this->evaluateActivationRequirements(
                $package->manifest,
                $dependencyState,
            );
            if ($backgroundPreparation) {
                $dependencyState['preparation'] = [
                    'status' => $requirementsBlocked ? 'requirements_unmet' : 'queued',
                    'queuedAt' => now()->toAtomString(),
                    'version' => $package->manifest['version'],
                ];
                $queueBackgroundPreparation = ! $requirementsBlocked;
            }

            $versions = $plugin->versions ?? [];
            $versions[$package->manifest['version']] = [
                'path' => $runtimePath,
                'archive_sha256' => $package->archiveSha256,
                'installed_at' => now()->toAtomString(),
                'source' => array_merge(['type' => $sourceType], $sourceMeta),
                'trust_level' => $package->trustLevel,
            ];

            $plugin->fill([
                'plugin_id' => $package->manifest['id'],
                'name' => $package->manifest['name'],
                'description' => $package->manifest['description'] ?? null,
                'author' => $package->manifest['author'] ?? null,
                'current_version' => $package->manifest['version'],
                'enabled' => $wasExisting ? (bool) $plugin->enabled : false,
                'trust_level' => $package->trustLevel,
                'install_source' => array_merge(['type' => $sourceType], $sourceMeta),
                'manifest' => $this->encodeMongoSafeValue($package->manifest),
                'permissions' => $package->manifest['permissions'] ?? [],
                'hooks' => array_keys($package->manifest['hooks'] ?? []),
                'actions' => $package->manifest['actions'] ?? [],
                'ui_extensions' => $this->encodeMongoSafeValue($package->manifest['uiExtensions'] ?? []),
                'versions' => $versions,
                'warnings' => $this->mergeWarnings($package->warnings, $dependencyState['warnings'] ?? []),
                'dependency_state' => $dependencyState,
                'settings' => $this->mergeSettings(
                    $this->declaredSettingsDefaults($package->manifest),
                    $plugin->settings ?? []
                ),
                'state' => is_array($plugin->state) ? $plugin->state : [],
                'logs' => is_array($plugin->logs) ? $plugin->logs : [],
                'load_status' => ($wasExisting && $plugin->enabled)
                    ? 'ready'
                    : ($backgroundPreparation
                        ? ($requirementsBlocked ? 'requirements_unmet' : 'preparing')
                        : 'disabled'),
                'load_error_at' => null,
                'automatic_update_enabled' => $this->defaultAutomaticUpdatesEnabledForSource(
                    $sourceType,
                    $package->manifest,
                ),
                'update_available' => false,
                'latest_version' => $package->manifest['version'],
                'last_update_checked_at' => null,
            ]);
            $plugin->save();
        } catch (\Throwable $exception) {
            if (is_dir($runtimePath)) {
                $this->deleteDirectory($runtimePath);
            }

            if ($wasExisting && is_array($previousState)) {
                $plugin->fill($previousState);
                $plugin->save();
            } elseif ($plugin->exists) {
                $plugin->delete();
            }

            throw $exception;
        }
        $this->lifecycleLogStore->append(
            $plugin,
            'info',
            'install',
            "Installed plugin {$package->manifest['name']} {$package->manifest['version']}.",
            ['sourceType' => $sourceType]
        );

        if ($queueBackgroundPreparation) {
            PreparePluginRuntime::dispatch(
                $package->manifest['id'],
                $package->manifest['version'],
            )->onQueue('default');
        }

        return $this->serializePlugin($plugin->fresh());
    }

    public function installFromUrl(string $url): array
    {
        $tempPath = config('plugins.paths.tmp').'/plugin-'.uniqid().'.w3dp';
        @mkdir(dirname($tempPath), 0777, true);

        Http::timeout(config('plugins.runtime.bridge_timeout_secs', 5))
            ->sink($tempPath)
            ->get($url)
            ->throw();

        return $this->installFromArchive($tempPath, 'remote_url', ['url' => $url]);
    }

    public function installFromRegistry(string $pluginId, ?string $version = null, ?string $sourceId = null): array
    {
        $package = $this->registryClient->getPackage($pluginId, $version, $sourceId);
        $url = $this->registryClient->resolvePackageUrl($package);

        $source = $package['registrySource'] ?? [];
        $sourceType = ($source['official'] ?? false) ? 'official_registry' : 'trusted_registry';

        return $this->installFromUrlWithSource($url, [
            'type' => $sourceType,
            'registry' => array_merge(
                Arr::only($package, ['id', 'version', 'packageUrl']),
                ['source' => Arr::only($source, ['id', 'name', 'indexUrl', 'websiteUrl', 'official', 'trustLevel'])]
            ),
        ]);
    }

    public function installFromDevelopmentPath(string $path): array
    {
        $resolvedPath = $this->resolveDevelopmentPath($path);
        $mountContext = $this->developmentMountContextForPath($resolvedPath);
        $package = $this->archiveService->inspectDirectory($resolvedPath, 'development_mount');
        $this->assertCoreCompatibility($package->manifest);

        $plugin = Plugin::firstOrNew(['plugin_id' => $package->manifest['id']]);
        $storagePath = config('plugins.paths.runtime').DIRECTORY_SEPARATOR.$package->manifest['id'];
        @mkdir($storagePath, 0777, true);
        $backgroundPreparation = $this->usesBackgroundPreparation($package->manifest)
            && (! $plugin->exists || ! (bool) $plugin->enabled);
        $dependencyState = $backgroundPreparation
            ? $this->dependencyService->summarize($package->manifest)
            : $this->dependencyService->prepare($package->manifest);
        [$requirementsBlocked, $dependencyState] = $this->evaluateActivationRequirements(
            $package->manifest,
            $dependencyState,
        );
        if ($backgroundPreparation) {
            $dependencyState['preparation'] = [
                'status' => $requirementsBlocked ? 'requirements_unmet' : 'queued',
                'queuedAt' => now()->toAtomString(),
                'version' => $package->manifest['version'],
            ];
        }

        $source = [
            'type' => 'development_mount',
            'mount_path' => $mountContext['path'],
            'path' => $resolvedPath,
            'relative_path' => $mountContext['relativePath'],
            'live' => true,
        ];

        $versions = $plugin->versions ?? [];
        $versions[$package->manifest['version']] = [
            'path' => $resolvedPath,
            'archive_sha256' => $package->archiveSha256,
            'installed_at' => $versions[$package->manifest['version']]['installed_at'] ?? now()->toAtomString(),
            'source' => $source,
            'trust_level' => $package->trustLevel,
        ];

        $plugin->fill([
            'plugin_id' => $package->manifest['id'],
            'name' => $package->manifest['name'],
            'description' => $package->manifest['description'] ?? null,
            'author' => $package->manifest['author'] ?? null,
            'current_version' => $package->manifest['version'],
            'enabled' => $plugin->exists ? (bool) $plugin->enabled : false,
            'trust_level' => $package->trustLevel,
            'install_source' => $source,
            'manifest' => $this->encodeMongoSafeValue($package->manifest),
            'permissions' => $package->manifest['permissions'] ?? [],
            'hooks' => array_keys($package->manifest['hooks'] ?? []),
            'actions' => $package->manifest['actions'] ?? [],
            'ui_extensions' => $this->encodeMongoSafeValue($package->manifest['uiExtensions'] ?? []),
            'versions' => $versions,
            'warnings' => $this->mergeWarnings($package->warnings, $dependencyState['warnings'] ?? []),
            'dependency_state' => $dependencyState,
            'settings' => $this->mergeSettings(
                $this->declaredSettingsDefaults($package->manifest),
                $plugin->settings ?? []
            ),
            'state' => is_array($plugin->state) ? $plugin->state : [],
            'last_error' => null,
            'logs' => is_array($plugin->logs) ? $plugin->logs : [],
            'load_status' => ($plugin->exists && $plugin->enabled)
                ? 'ready'
                : ($backgroundPreparation
                    ? ($requirementsBlocked ? 'requirements_unmet' : 'preparing')
                    : 'disabled'),
            'load_error_at' => null,
            'automatic_update_enabled' => false,
            'update_available' => false,
            'latest_version' => $package->manifest['version'],
            'last_update_checked_at' => null,
        ]);
        $plugin->save();
        $this->lifecycleLogStore->append(
            $plugin,
            'info',
            'install',
            "Installed development plugin {$package->manifest['name']} {$package->manifest['version']} from the live source mount.",
            ['path' => $resolvedPath]
        );

        if ($backgroundPreparation && ! $requirementsBlocked) {
            PreparePluginRuntime::dispatch(
                $package->manifest['id'],
                $package->manifest['version'],
            )->onQueue('default');
        }

        return $this->serializePlugin($plugin->fresh());
    }

    public function enable(string $pluginId, bool $overrideRequirements = false, ?string $overrideBy = null): array
    {
        $plugin = $this->requireModel($pluginId);
        $this->lifecycleLogStore->append($plugin, 'info', 'startup', 'Plugin startup requested.');

        try {
            if (! $this->currentRuntimePathIsAvailable($plugin)) {
                throw new PluginRuntimeException("Plugin {$pluginId} runtime path is unavailable.");
            }

            $manifest = $this->decodedManifest($plugin);
            [$requirementsBlocked, $dependencyState] = $this->evaluateActivationRequirements(
                $manifest,
                is_array($plugin->dependency_state) ? $plugin->dependency_state : [],
                $overrideRequirements,
                $overrideBy,
            );
            $plugin->dependency_state = $dependencyState;
            $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], $dependencyState['warnings'] ?? []);

            if ($requirementsBlocked) {
                $plugin->enabled = false;
                $plugin->load_status = 'requirements_unmet';
                $plugin->last_error = null;
                $plugin->load_error_at = null;
                $plugin->save();
                $this->lifecycleLogStore->append(
                    $plugin,
                    'warning',
                    'startup',
                    'Plugin activation was blocked because the host does not meet its declared requirements.',
                );

                return $this->serializePlugin($plugin->fresh() ?? $plugin);
            }

            if (
                $this->usesBackgroundPreparation($manifest)
                && data_get($dependencyState, 'preparation.status') !== 'ready'
            ) {
                $dependencyState = $this->dependencyService->prepare($manifest, $dependencyState);
                $dependencyState['preparation'] = [
                    'status' => 'ready',
                    'preparedAt' => now()->toAtomString(),
                    'version' => $plugin->current_version,
                ];
                $plugin->dependency_state = $dependencyState;
                $plugin->load_status = 'prepared';
                $plugin->save();
            }

            $payload = $this->serializePlugin($plugin->fresh() ?? $plugin);

            $dependencyState = $this->dependencyService->activate($payload, $plugin->dependency_state ?? []);
            $plugin->dependency_state = $dependencyState;
            $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], $dependencyState['warnings'] ?? []);
            $plugin->save();
            $payload = $this->serializePlugin($plugin->fresh() ?? $plugin);

            if (($payload['manifest']['runtime']['type'] ?? null) === 'bridge') {
                $adapter = $this->runtimeRegistry->resolve('bridge');

                if ($adapter instanceof BridgePluginRuntimeAdapter) {
                    $this->lifecycleLogStore->append($plugin, 'info', 'startup', 'Running bridge healthcheck.');
                    $this->healthcheckBridgeWithRetry($adapter, $payload);
                }
            }

            if ($this->dependencyService->hasCandidate($dependencyState)) {
                $dependencyState = $this->dependencyService->promoteCandidate($payload, $dependencyState);
                $plugin->dependency_state = $dependencyState;
                $plugin->save();
            }

            $plugin->enabled = true;
            $plugin->load_status = 'ready';
            $plugin->last_error = null;
            $plugin->load_error_at = null;
            $plugin->last_healthcheck_at = now()->toAtomString();
            $plugin->save();
            $this->lifecycleLogStore->append($plugin, 'info', 'startup', 'Plugin startup completed.');

            return $this->serializePlugin($plugin->fresh());
        } catch (\Throwable $exception) {
            try {
                $failedPlugin = $plugin->fresh() ?? $plugin;
                $failedPayload = $this->serializePlugin($failedPlugin);
                $failedDependencyState = is_array($failedPlugin->dependency_state)
                    ? $failedPlugin->dependency_state
                    : [];
                if ($this->dependencyService->hasCandidate($failedDependencyState)) {
                    $this->dependencyService->discardCandidate($failedDependencyState);
                } else {
                    $this->dependencyService->deactivate($failedPayload);
                }
            } catch (\Throwable) {
            }

            $plugin->enabled = false;
            $plugin->load_status = 'failed';
            $plugin->last_error = $exception->getMessage();
            $plugin->load_error_at = now()->toAtomString();
            $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], [$exception->getMessage()]);
            $plugin->save();
            $this->lifecycleLogStore->append(
                $plugin,
                'error',
                'startup',
                $exception->getMessage(),
                ['exception' => $exception::class]
            );

            return $this->serializePlugin($plugin->fresh() ?? $plugin);
        }
    }

    public function prepareRuntime(string $pluginId, string $expectedVersion): array
    {
        $plugin = $this->requireModel($pluginId);

        if ((string) $plugin->current_version !== $expectedVersion) {
            return array_merge($this->serializePlugin($plugin), [
                'preparationStatus' => 'stale',
            ]);
        }

        $manifest = $this->decodedManifest($plugin);
        if (! $this->usesBackgroundPreparation($manifest)) {
            return array_merge($this->serializePlugin($plugin), [
                'preparationStatus' => 'not_required',
            ]);
        }

        [$requirementsBlocked, $dependencyState] = $this->evaluateActivationRequirements(
            $manifest,
            is_array($plugin->dependency_state) ? $plugin->dependency_state : [],
        );

        if ($requirementsBlocked) {
            $dependencyState['preparation'] = [
                'status' => 'requirements_unmet',
                'checkedAt' => now()->toAtomString(),
                'version' => $expectedVersion,
            ];
            $plugin->dependency_state = $dependencyState;
            $plugin->enabled = false;
            $plugin->load_status = 'requirements_unmet';
            $plugin->save();

            return array_merge($this->serializePlugin($plugin->fresh() ?? $plugin), [
                'preparationStatus' => 'requirements_unmet',
            ]);
        }

        try {
            $dependencyState['preparation'] = [
                'status' => 'preparing',
                'startedAt' => now()->toAtomString(),
                'version' => $expectedVersion,
            ];
            $plugin->dependency_state = $dependencyState;
            $plugin->load_status = 'preparing';
            $plugin->save();

            $dependencyState = $this->dependencyService->prepare($manifest, $dependencyState);
            $dependencyState['preparation'] = [
                'status' => 'ready',
                'preparedAt' => now()->toAtomString(),
                'version' => $expectedVersion,
            ];
            $plugin->dependency_state = $dependencyState;
            $plugin->load_status = 'prepared';
            $plugin->last_error = null;
            $plugin->load_error_at = null;
            $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], $dependencyState['warnings'] ?? []);
            $plugin->save();
            $this->lifecycleLogStore->append(
                $plugin,
                'info',
                'prepare',
                "Prepared plugin runtime dependencies for {$expectedVersion}.",
            );

            if ((bool) data_get($manifest, 'activation.autoEnableWhenReady', false)) {
                return array_merge($this->enable($pluginId), [
                    'preparationStatus' => 'ready',
                ]);
            }

            return array_merge($this->serializePlugin($plugin->fresh() ?? $plugin), [
                'preparationStatus' => 'ready',
            ]);
        } catch (\Throwable $exception) {
            $dependencyState['preparation'] = [
                'status' => 'failed',
                'failedAt' => now()->toAtomString(),
                'version' => $expectedVersion,
                'error' => $exception->getMessage(),
            ];
            $plugin->dependency_state = $dependencyState;
            $plugin->enabled = false;
            $plugin->load_status = 'failed';
            $plugin->last_error = $exception->getMessage();
            $plugin->load_error_at = now()->toAtomString();
            $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], [$exception->getMessage()]);
            $plugin->save();
            $this->lifecycleLogStore->append(
                $plugin,
                'error',
                'prepare',
                $exception->getMessage(),
                ['exception' => $exception::class],
            );

            return array_merge($this->serializePlugin($plugin->fresh() ?? $plugin), [
                'preparationStatus' => 'failed',
            ]);
        }
    }

    public function disable(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $this->dependencyService->deactivate($this->serializePlugin($plugin));
        $plugin->enabled = false;
        $plugin->load_status = 'disabled';
        $plugin->save();
        $this->lifecycleLogStore->append($plugin, 'info', 'shutdown', 'Plugin disabled.');

        return $this->serializePlugin($plugin->fresh());
    }

    /**
     * A freshly created managed container can need a short warm-up before its
     * HTTP listener accepts connections. Retry transport-level failures while
     * keeping application/authentication failures fail-fast.
     */
    private function healthcheckBridgeWithRetry(BridgePluginRuntimeAdapter $adapter, array $payload): void
    {
        $attempts = max(1, (int) config('plugins.runtime.healthcheck_retries', 120));
        $delayMicroseconds = max(0, (int) config('plugins.runtime.healthcheck_delay_ms', 500)) * 1000;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $adapter->healthcheck($payload);

                return;
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw $exception;
                }

                if ($delayMicroseconds > 0) {
                    usleep($delayMicroseconds);
                }
            }
        }
    }

    public function uninstall(string $pluginId): bool
    {
        $plugin = $this->findModel($pluginId);

        if (! $plugin) {
            return false;
        }

        $this->dependencyService->deactivate($this->serializePlugin($plugin));

        foreach (($plugin->versions ?? []) as $version) {
            if (($version['source']['type'] ?? null) === 'development_mount') {
                continue;
            }

            if (! empty($version['path']) && is_dir($version['path'])) {
                $this->deleteDirectory($version['path']);
            }
        }

        $plugin->delete();

        return true;
    }

    public function update(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $sourceType = $plugin->install_source['type'] ?? null;

        if ($sourceType === 'development_mount') {
            $plugin->automatic_update_enabled = false;
            $plugin->update_available = false;
            $plugin->latest_version = $plugin->current_version;
            $plugin->last_update_checked_at = now()->toAtomString();
            $plugin->save();

            return array_merge(
                $this->installFromDevelopmentPath((string) ($plugin->install_source['path'] ?? $plugin->getCurrentRuntimePath())),
                ['updateStatus' => 'refreshed']
            );
        }

        if (! in_array($sourceType, ['official_registry', 'trusted_registry'], true)) {
            $plugin->update_available = false;
            $plugin->latest_version = $plugin->current_version;
            $plugin->last_update_checked_at = now()->toAtomString();
            $plugin->save();

            return array_merge(
                $this->serializePlugin($plugin->fresh() ?? $plugin),
                ['updateStatus' => 'unsupported']
            );
        }

        $sourceId = $plugin->install_source['registry']['source']['id'] ?? null;
        $package = $this->registryClient->getPackage($pluginId, null, $sourceId);
        $latestVersion = (string) ($package['latestVersion'] ?? $package['version'] ?? '');
        $currentVersion = (string) ($plugin->current_version ?? '');
        $runtimePathAvailable = $this->currentRuntimePathIsAvailable($plugin);

        if ($latestVersion !== '' && $latestVersion === $currentVersion && $runtimePathAvailable) {
            $plugin->update_available = false;
            $plugin->latest_version = $latestVersion;
            $plugin->last_update_checked_at = now()->toAtomString();
            $plugin->save();

            return array_merge(
                $this->serializePlugin($plugin->fresh() ?? $plugin),
                [
                    'updateStatus' => 'noop',
                    'latestVersion' => $latestVersion,
                ]
            );
        }

        $wasEnabled = (bool) $plugin->enabled;
        $managedBridge = ($plugin->manifest['runtime']['type'] ?? null) === 'bridge'
            && ! empty($plugin->manifest['runtime']['managedImageId']);
        $previousState = $this->pluginStateSnapshot($plugin);
        $previousVersionPaths = collect($plugin->versions ?? [])
            ->map(fn (array $version) => (string) ($version['path'] ?? ''))
            ->filter()
            ->values()
            ->all();

        if ($wasEnabled && $managedBridge && ! (bool) config('plugins.runtime.blue_green_updates', true)) {
            $this->dependencyService->deactivate([
                'id' => $plugin->plugin_id,
                'manifest' => $this->decodedManifest($plugin),
            ]);
        }

        try {
            $updated = $this->installFromRegistry($pluginId, $latestVersion !== '' ? $latestVersion : null, $sourceId);

            if ($wasEnabled && $managedBridge) {
                $updated = $this->enable($pluginId);
                if (! ($updated['enabled'] ?? false)) {
                    throw new PluginRuntimeException(
                        'Updated plugin failed its startup healthcheck: '.($updated['lastError'] ?? 'unknown error')
                    );
                }
            }
        } catch (\Throwable $exception) {
            $this->restorePluginState($plugin, $previousState, $previousVersionPaths, $wasEnabled);
            $this->lifecycleLogStore->append(
                $plugin,
                'error',
                'update',
                "Update rolled back: {$exception->getMessage()}",
                ['exception' => $exception::class]
            );

            return array_merge(
                $this->serializePlugin($plugin->fresh() ?? $plugin),
                [
                    'updateStatus' => 'rollback',
                    'previousVersion' => $currentVersion,
                    'latestVersion' => $latestVersion !== '' ? $latestVersion : null,
                    'lastError' => $exception->getMessage(),
                ]
            );
        }

        return array_merge(
            $updated,
            [
                'updateStatus' => $runtimePathAvailable ? 'updated' : 'repaired',
                'previousVersion' => $currentVersion,
                'latestVersion' => $latestVersion !== '' ? $latestVersion : null,
            ]
        );
    }

    /** @return array<string, mixed> */
    private function pluginStateSnapshot(Plugin $plugin): array
    {
        return collect([
            'name', 'description', 'author', 'current_version', 'enabled', 'trust_level', 'install_source',
            'manifest', 'permissions', 'hooks', 'actions', 'ui_extensions', 'versions', 'warnings',
            'dependency_state', 'settings', 'state', 'logs', 'load_status', 'last_error', 'load_error_at',
            'automatic_update_enabled', 'update_available', 'latest_version', 'last_update_checked_at',
        ])->mapWithKeys(fn (string $key) => [$key => $plugin->{$key}])->all();
    }

    /**
     * Restore the previous package/DB/runtime state after an update healthcheck
     * failure. Retained data is never removed as part of this rollback.
     *
     * @param  array<string, mixed>  $previousState
     * @param  array<int, string>  $previousVersionPaths
     */
    private function restorePluginState(Plugin $plugin, array $previousState, array $previousVersionPaths, bool $wasEnabled): void
    {
        $currentVersions = $plugin->versions ?? [];
        foreach ($currentVersions as $version) {
            $path = (string) ($version['path'] ?? '');
            if ($path !== '' && ! in_array($path, $previousVersionPaths, true) && is_dir($path)) {
                $this->deleteDirectory($path);
            }
        }

        $plugin->fill($previousState);
        $plugin->save();

        if (! $wasEnabled) {
            return;
        }

        try {
            $restored = $this->enable($plugin->plugin_id);
            if (! ($restored['enabled'] ?? false)) {
                $this->lifecycleLogStore->append(
                    $plugin,
                    'error',
                    'update',
                    'Previous plugin version could not be re-enabled after rollback.',
                );
            }
        } catch (\Throwable $exception) {
            $this->lifecycleLogStore->append(
                $plugin,
                'error',
                'update',
                "Previous plugin version could not be re-enabled after rollback: {$exception->getMessage()}",
                ['exception' => $exception::class]
            );
        }
    }

    public function checkForPluginUpdates(bool $automaticOnly = false): array
    {
        $summary = [
            'checkedCount' => 0,
            'updatesAvailableCount' => 0,
            'upToDateCount' => 0,
            'unsupportedCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
            'plugins' => [],
        ];

        foreach (Plugin::query()->orderBy('name')->get() as $plugin) {
            if ($automaticOnly && ! $this->shouldAutomaticallyUpdate($plugin)) {
                $summary['skippedCount']++;
                $summary['plugins'][] = [
                    'id' => $plugin->plugin_id,
                    'status' => 'skipped',
                ];

                continue;
            }

            $summary['checkedCount']++;
            $inspection = $this->inspectPluginUpdate($plugin);
            $summary['plugins'][] = $inspection;

            if ($inspection['status'] === 'update_available') {
                $summary['updatesAvailableCount']++;
            } elseif ($inspection['status'] === 'up_to_date') {
                $summary['upToDateCount']++;
            } elseif ($inspection['status'] === 'unsupported') {
                $summary['unsupportedCount']++;
            } elseif ($inspection['status'] === 'failed') {
                $summary['failedCount']++;
            }
        }

        return $summary;
    }

    public function updateAllPlugins(bool $automaticOnly = false): array
    {
        $summary = [
            'checkedCount' => 0,
            'updatedCount' => 0,
            'noopCount' => 0,
            'unsupportedCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
            'plugins' => [],
        ];

        foreach (Plugin::query()->orderBy('name')->get() as $plugin) {
            if ($automaticOnly && ! $this->shouldAutomaticallyUpdate($plugin)) {
                $summary['skippedCount']++;
                $summary['plugins'][] = [
                    'id' => $plugin->plugin_id,
                    'updateStatus' => 'skipped',
                ];

                continue;
            }

            $summary['checkedCount']++;

            $inspection = $this->inspectPluginUpdate($plugin);

            if (($inspection['status'] ?? null) === 'unsupported') {
                $summary['unsupportedCount']++;
                $summary['plugins'][] = [
                    'id' => $plugin->plugin_id,
                    'updateStatus' => 'unsupported',
                ];

                continue;
            }

            if (($inspection['status'] ?? null) === 'failed') {
                $summary['failedCount']++;
                $summary['plugins'][] = [
                    'id' => $plugin->plugin_id,
                    'updateStatus' => 'failed',
                    'message' => $inspection['message'] ?? 'Update check failed.',
                ];

                continue;
            }

            if (($inspection['status'] ?? null) === 'up_to_date') {
                $summary['noopCount']++;
                $summary['plugins'][] = [
                    'id' => $plugin->plugin_id,
                    'updateStatus' => 'noop',
                    'latestVersion' => $inspection['latestVersion'] ?? $plugin->current_version,
                ];

                continue;
            }

            try {
                $result = $this->update($plugin->plugin_id);
            } catch (\Throwable $exception) {
                $summary['failedCount']++;
                $summary['plugins'][] = [
                    'id' => $plugin->plugin_id,
                    'updateStatus' => 'failed',
                    'message' => $exception->getMessage(),
                ];

                continue;
            }

            $summary['plugins'][] = $result;
            $summary['updatedCount']++;
        }

        return $summary;
    }

    public function disableAll(): array
    {
        $disabledCount = 0;

        foreach (Plugin::enabled()->get() as $plugin) {
            $this->disable($plugin->plugin_id);
            $disabledCount++;
        }

        return [
            'disabledCount' => $disabledCount,
        ];
    }

    public function enableAll(): array
    {
        $enabledCount = 0;
        $failedCount = 0;

        foreach (Plugin::query()->where('enabled', false)->get() as $plugin) {
            $result = $this->enable($plugin->plugin_id);

            if (($result['enabled'] ?? false) && ($result['loadStatus'] ?? null) !== 'failed') {
                $enabledCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'enabledCount' => $enabledCount,
            'failedCount' => $failedCount,
        ];
    }

    public function runAutomaticUpdates(): array
    {
        return $this->updateAllPlugins(true);
    }

    public function listUiExtensions(?string $surface = null): array
    {
        $extensions = [];

        foreach (Plugin::enabled()->get() as $plugin) {
            $payload = $this->serializePlugin($plugin);

            if (($payload['loadStatus'] ?? null) !== 'ready') {
                continue;
            }

            foreach (($payload['uiExtensions'] ?? []) as $extension) {
                if ($surface !== null && ($extension['surface'] ?? null) !== $surface) {
                    continue;
                }

                $extensions[] = array_merge($extension, [
                    'pluginId' => $payload['id'],
                    'pluginName' => $payload['name'],
                    'pluginTrustLevel' => $payload['trustLevel'],
                    'runtimeArtifactImports' => data_get($payload, 'manifest.runtime.artifactImports', []),
                    'warnings' => array_values(array_filter([
                        $payload['trustLevel'] === 'unsigned' ? 'Unsigned sideloaded plugin' : null,
                        in_array($extension['mode'] ?? 'declarative', ['webview', 'custom_bundle'], true)
                            ? 'This plugin uses an elevated UI mode.'
                            : null,
                    ])),
                ]);
            }
        }

        return $extensions;
    }

    public function hostContext(string $pluginId, ?User $user = null, ?string $locale = null): array
    {
        $plugin = $this->requireModel($pluginId);
        $payload = $this->serializePlugin($plugin);

        if (! $payload['enabled'] || ($payload['loadStatus'] ?? null) !== 'ready') {
            throw new PluginRuntimeException("Plugin {$pluginId} is not ready.");
        }

        $permissions = array_fill_keys(array_map('strval', $payload['permissions'] ?? []), true);
        if (! isset($permissions['ui.custom_bundle'])) {
            throw new PluginRuntimeException("Plugin {$pluginId} is not allowed to request embedded host context.");
        }

        $runtimeBase = "/backend/api/plugins/{$pluginId}/runtime";
        $proxyEnabled = (bool) config('plugins.rollout.runtime_proxy_enabled', true)
            && (bool) data_get($payload, 'manifest.runtime.proxy.enabled', false);
        $context = [
            'apiVersion' => '2.0',
            'pluginId' => $pluginId,
            'hostMode' => 'embedded',
            'runtimeBase' => $proxyEnabled ? $runtimeBase : null,
            'artifactImportBase' => $proxyEnabled && isset($permissions['storage.write'])
                ? "/backend/api/plugins/{$pluginId}/runtime-artifacts"
                : null,
            'locale' => $this->normalizeLocale($locale ?: (string) config('app.locale', 'en')),
            'presentation' => 'split-pane',
            'themeTokens' => (object) [],
            'features' => [
                'printerRead' => isset($permissions['printer.read']),
                'storageRead' => isset($permissions['storage.read']),
                'storageWrite' => isset($permissions['storage.write']),
            ],
            'hostActions' => $this->embeddedHostActions($permissions),
            'currentPrinterId' => null,
            'currentPrinter' => null,
        ];

        if (isset($permissions['printer.read']) && $user) {
            $context['currentPrinterId'] = $user->getActivePrinterId();
            $printer = $user->getActivePrinter('_id', 'node', 'machine', 'slicing');
            if ($printer instanceof Printer) {
                $context['currentPrinterId'] = (string) $printer->getKey();
                $context['currentPrinter'] = app(PrinterSlicingConfigurationService::class)
                    ->hostPrinterContext($printer);
            }
        }

        return $context;
    }

    private function embeddedHostActions(array $permissions): array
    {
        $printerRead = isset($permissions['printer.read']);
        $storageWrite = isset($permissions['storage.write']);

        return [
            ['id' => 'printer.slicing.open', 'version' => 2, 'available' => $printerRead],
            ['id' => 'files.open', 'version' => 2, 'available' => true],
            ['id' => 'preview.open', 'version' => 2, 'available' => $printerRead],
            ['id' => 'artifact.import', 'version' => 2, 'available' => $storageWrite],
            ['id' => 'artifact.print', 'version' => 2, 'available' => $storageWrite && $printerRead],
            ['id' => 'fullscreen.enter', 'version' => 2, 'available' => true],
            ['id' => 'fullscreen.exit', 'version' => 2, 'available' => true],
            ['id' => 'transfer.progress', 'version' => 2, 'available' => true],
            ['id' => 'transfer.result', 'version' => 2, 'available' => true],
        ];
    }

    public function invokeAction(string $pluginId, string $actionId, array $payload = [], array $context = []): array
    {
        $plugin = $this->serializePlugin($this->requireModel($pluginId));

        if (! $plugin['enabled']) {
            throw new PluginRuntimeException("Plugin {$pluginId} is disabled.");
        }

        $action = collect($plugin['actions'])->firstWhere('id', $actionId);

        if (! $action) {
            throw new PluginRuntimeException("Plugin action {$actionId} does not exist.");
        }

        try {
            $adapter = $this->runtimeRegistry->resolve($plugin['manifest']['runtime']['type']);
            $result = $adapter->invokeAction($plugin, $action, $payload, $context);

            app(PluginEffectExecutor::class)->execute($plugin, $result['effects'] ?? []);

            return $result;
        } catch (\Throwable $exception) {
            $model = $this->findModel($pluginId);

            if ($model) {
                $model->last_error = $exception->getMessage();
                $model->load_error_at = now()->toAtomString();
                $model->save();
                $this->lifecycleLogStore->append(
                    $model,
                    'error',
                    'action',
                    "Action {$actionId} failed: {$exception->getMessage()}",
                    ['exception' => $exception::class]
                );
            }

            throw $exception;
        }
    }

    public function getEnabledPluginsForHook(string $hook): array
    {
        return Plugin::enabled()->get()->map(function (Plugin $plugin) use ($hook) {
            $payload = $this->serializePlugin($plugin);

            return isset($payload['manifest']['hooks'][$hook]) ? $payload : null;
        })->filter()->values()->all();
    }

    public function doctor(): array
    {
        return Plugin::query()->get()->map(function (Plugin $plugin) {
            $payload = $this->serializePlugin($plugin);

            return [
                'id' => $payload['id'],
                'enabled' => $payload['enabled'],
                'loadStatus' => $payload['loadStatus'] ?? 'unknown',
                'runtimePathExists' => is_dir($payload['runtimePath'] ?? ''),
                'trustLevel' => $payload['trustLevel'],
                'warnings' => $payload['warnings'],
                'classification' => $payload['classification'] ?? 'lightweight',
                'lastError' => $payload['lastError'] ?? null,
                'lastHealthcheckAt' => $payload['lastHealthcheckAt'] ?? null,
                'latestLog' => $payload['latestLog'] ?? null,
                'runtimeDiagnostics' => $this->dependencyService->diagnostics($payload),
            ];
        })->all();
    }

    public function safeModeDisableAll(): int
    {
        $enabledPlugins = Plugin::enabled()->get();
        $count = $enabledPlugins->count();

        foreach ($enabledPlugins as $plugin) {
            $this->dependencyService->deactivate($this->serializePlugin($plugin));
            $plugin->enabled = false;
            $plugin->load_status = 'disabled';
            $plugin->save();
            $this->lifecycleLogStore->append($plugin, 'info', 'shutdown', 'Plugin disabled by safe mode.');
        }

        return $count;
    }

    public function findModel(string $pluginId): ?Plugin
    {
        return Plugin::where('plugin_id', $pluginId)->first();
    }

    public function listDevelopmentPlugins(): array
    {
        if (! $this->developmentModeEnabled()) {
            return [];
        }

        $mounts = $this->developmentMountRoots();

        if ($mounts === []) {
            return [];
        }

        $plugins = [];
        $seenPluginIds = [];

        foreach ($mounts as $mount) {
            $root = $mount['path'];
            $entries = @scandir($root);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (in_array($entry, ['.', '..'], true)) {
                    continue;
                }

                $directory = $root.DIRECTORY_SEPARATOR.$entry;

                if (! is_dir($directory)) {
                    continue;
                }

                $manifestPath = $directory.DIRECTORY_SEPARATOR.'plugin.json';

                if (! is_file($manifestPath)) {
                    continue;
                }

                try {
                    $package = $this->archiveService->inspectDirectory($directory, 'development_mount');
                } catch (\Throwable $exception) {
                    $plugins[] = [
                        'id' => $entry,
                        'name' => $entry,
                        'path' => $directory,
                        'relativePath' => $entry,
                        'mountPath' => $root,
                        'mountLabel' => $mount['label'],
                        'warning' => $exception->getMessage(),
                    ];

                    continue;
                }

                if (isset($seenPluginIds[$package->manifest['id']])) {
                    continue;
                }

                $dependencies = $this->dependencyService->summarize($package->manifest);

                $plugins[] = [
                    'id' => $package->manifest['id'],
                    'name' => $package->manifest['name'],
                    'description' => $package->manifest['description'] ?? null,
                    'version' => $package->manifest['version'],
                    'path' => $directory,
                    'relativePath' => $entry,
                    'mountPath' => $root,
                    'mountLabel' => $mount['label'],
                    'trustLevel' => $package->trustLevel,
                    'warnings' => $this->mergeWarnings($package->warnings, $dependencies['warnings'] ?? []),
                    'classification' => $dependencies['classification'] ?? 'lightweight',
                    'dependencies' => $dependencies,
                ];
                $seenPluginIds[$package->manifest['id']] = true;
            }
        }

        return collect($plugins)
            ->sortBy(fn (array $plugin) => sprintf(
                '%d-%s',
                ($plugin['mountLabel'] ?? null) === 'Local plugins' ? 0 : 1,
                strtolower((string) ($plugin['name'] ?? $plugin['id'] ?? ''))
            ))
            ->values()
            ->all();
    }

    public function installFromUploadedFile(UploadedFile $file): array
    {
        return $this->installFromArchive($file->getRealPath(), 'local_upload', [
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    public function resolveAsset(string $pluginId, string $assetPath): array
    {
        $plugin = $this->serializePlugin($this->requireModel($pluginId));
        $runtimePath = rtrim((string) ($plugin['runtimePath'] ?? ''), DIRECTORY_SEPARATOR);

        if ($runtimePath === '' || ! is_dir($runtimePath)) {
            throw new PluginRuntimeException("Plugin {$pluginId} runtime path is unavailable.");
        }

        $normalizedAssetPath = ltrim(urldecode($assetPath), DIRECTORY_SEPARATOR);
        $declaredAssets = collect($plugin['manifest']['assets'] ?? []);

        if (! $declaredAssets->contains(function (array $asset) use ($normalizedAssetPath): bool {
            $declaredPath = trim((string) ($asset['path'] ?? ''), DIRECTORY_SEPARATOR);

            return $declaredPath !== '' && (
                $normalizedAssetPath === $declaredPath
                || str_starts_with($normalizedAssetPath, $declaredPath.DIRECTORY_SEPARATOR)
            );
        })) {
            throw new PluginRuntimeException("Plugin asset {$normalizedAssetPath} is not declared by {$pluginId}.");
        }

        $candidatePath = realpath($runtimePath.DIRECTORY_SEPARATOR.$normalizedAssetPath);
        $normalizedRuntimePath = $runtimePath.DIRECTORY_SEPARATOR;

        if (! $candidatePath || ! is_file($candidatePath) || ! str_starts_with($candidatePath, $normalizedRuntimePath)) {
            throw new PluginRuntimeException("Plugin asset {$normalizedAssetPath} could not be resolved.");
        }

        return [
            'path' => $candidatePath,
            'mimeType' => $this->detectAssetMimeType($candidatePath),
        ];
    }

    private function installFromUrlWithSource(string $url, array $source): array
    {
        $tempPath = config('plugins.paths.tmp').'/plugin-'.uniqid().'.w3dp';
        @mkdir(dirname($tempPath), 0777, true);

        Http::timeout(config('plugins.runtime.bridge_timeout_secs', 5))
            ->sink($tempPath)
            ->get($url)
            ->throw();

        return $this->installFromArchive($tempPath, $source['type'], $source);
    }

    private function assertCoreCompatibility(array $manifest): void
    {
        $minCoreVersion = $manifest['minCoreVersion'] ?? null;
        $coreVersion = config('plugins.core_version', '0.0.0');

        if (! $minCoreVersion || $coreVersion === '0.0.0') {
            return;
        }

        if (version_compare($coreVersion, $minCoreVersion, '<')) {
            throw new PluginRuntimeException("Plugin {$manifest['id']} requires WPrint3D {$minCoreVersion} or newer.");
        }
    }

    private function usesBackgroundPreparation(array $manifest): bool
    {
        return (int) ($manifest['sdkRevision'] ?? 0) >= 6
            && data_get($manifest, 'activation.prepare') === 'background';
    }

    /**
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function evaluateActivationRequirements(
        array $manifest,
        array $state,
        bool $acceptOverride = false,
        ?string $overrideBy = null,
    ): array {
        $summary = $this->dependencyService->summarize($manifest, $state);
        $requirements = is_array($manifest['requirements'] ?? null) ? $manifest['requirements'] : [];
        $enforced = (int) ($manifest['sdkRevision'] ?? 0) >= 6
            && ($requirements['policy'] ?? null) === 'disable-by-default-when-unmet';

        if (! $enforced) {
            return [false, $summary];
        }

        $meetsRequirements = (bool) data_get($summary, 'host.meetsRequirements', false);
        $allowAdminOverride = (bool) ($requirements['allowAdminOverride'] ?? false);
        $existingOverride = data_get($state, 'activation.requirementsOverride', []);
        $overrideAccepted = $allowAdminOverride
            && is_array($existingOverride)
            && ($existingOverride['accepted'] ?? false) === true;

        if ($acceptOverride && ! $allowAdminOverride) {
            throw new PluginRuntimeException('This plugin does not allow an administrator requirement override.');
        }

        if ($acceptOverride && ! $meetsRequirements) {
            $existingOverride = [
                'accepted' => true,
                'acceptedAt' => now()->toAtomString(),
                'acceptedBy' => $overrideBy,
            ];
            $overrideAccepted = true;
        }

        $blocked = ! $meetsRequirements && ! $overrideAccepted;
        $activation = is_array($summary['activation'] ?? null) ? $summary['activation'] : [];
        $activation['requirementsBlocked'] = $blocked;
        $activation['requirementsCheckedAt'] = now()->toAtomString();
        if ($overrideAccepted) {
            $activation['requirementsOverride'] = $existingOverride;
        }
        $summary['activation'] = $activation;

        if ($blocked) {
            $summary['warnings'] = $this->mergeWarnings(
                $summary['warnings'] ?? [],
                ['Activation is disabled until this host meets the declared requirements or an administrator accepts the override.'],
            );
        } elseif ($overrideAccepted && ! $meetsRequirements) {
            $summary['warnings'] = $this->mergeWarnings(
                $summary['warnings'] ?? [],
                ['Administrator requirement override is active while this host remains below the declared minimum.'],
            );
        }

        return [$blocked, $summary];
    }

    private function requireModel(string $pluginId): Plugin
    {
        $plugin = $this->findModel($pluginId);

        if (! $plugin) {
            throw new PluginRuntimeException("Plugin {$pluginId} is not installed.");
        }

        return $plugin;
    }

    private function serializePlugin(Plugin $plugin): array
    {
        $plugin = $this->synchronizeDevelopmentPlugin($plugin);
        $plugin = $this->synchronizePackagedPluginTrust($plugin);
        $manifest = $this->decodedManifest($plugin);
        $manifest = $this->localizeManifest($plugin, $manifest);
        $manifest['uiExtensions'] = $this->normalizeExtensionAssetUrls($plugin->plugin_id, $manifest['uiExtensions'] ?? []);
        $manifest['components'] = $this->normalizeManifestComponents($plugin->plugin_id, $manifest['components'] ?? []);
        $uiExtensions = $manifest['uiExtensions'] ?? [];
        $dependencies = $this->dependencyService->summarize($manifest, $plugin->dependency_state ?? []);
        $manifest['runtime'] = $this->resolveManagedRuntime($manifest['runtime'] ?? [], $dependencies);

        return [
            'id' => $plugin->plugin_id,
            'name' => $manifest['name'] ?? $plugin->name,
            'description' => $manifest['description'] ?? $plugin->description,
            'author' => $plugin->author,
            'version' => $plugin->current_version,
            'enabled' => (bool) $plugin->enabled,
            'loadStatus' => $this->resolveLoadStatus($plugin),
            'trustLevel' => $plugin->trust_level ?? 'unsigned',
            'installSource' => $plugin->install_source ?? [],
            'automaticUpdatesEnabled' => (bool) ($plugin->automatic_update_enabled ?? false),
            'automaticUpdatesSupported' => $this->supportsAutomaticUpdates($plugin),
            'updateAvailable' => (bool) ($plugin->update_available ?? false),
            'latestVersion' => $plugin->latest_version ?? $plugin->current_version,
            'lastUpdateCheckedAt' => $plugin->last_update_checked_at ?? null,
            'manifest' => $manifest,
            'permissions' => $plugin->permissions ?? [],
            'hooks' => $plugin->hooks ?? [],
            'actions' => $manifest['actions'] ?? [],
            'uiExtensions' => $uiExtensions,
            'warnings' => $this->mergeWarnings($plugin->warnings ?? [], $dependencies['warnings'] ?? []),
            'classification' => $dependencies['classification'] ?? 'lightweight',
            'dependencies' => $dependencies,
            'settings' => $this->resolvedPluginSettings($plugin),
            'state' => is_array($plugin->state) ? $plugin->state : [],
            'runtimePath' => $plugin->getCurrentRuntimePath(),
            'runtime_path' => $plugin->getCurrentRuntimePath(),
            'versions' => $plugin->versions ?? [],
            'lastError' => $plugin->last_error ?? null,
            'loadErrorAt' => $plugin->load_error_at ?? null,
            'lastHealthcheckAt' => $plugin->last_healthcheck_at ?? null,
            'logCount' => count(is_array($plugin->logs) ? $plugin->logs : []),
            'latestLog' => $this->latestLogEntry($plugin),
            'storagePath' => config('plugins.paths.runtime').DIRECTORY_SEPARATOR.$plugin->plugin_id,
            'storage_path' => config('plugins.paths.runtime').DIRECTORY_SEPARATOR.$plugin->plugin_id,
        ];
    }

    private function synchronizeDevelopmentPlugin(Plugin $plugin): Plugin
    {
        if (($plugin->install_source['type'] ?? null) !== 'development_mount') {
            return $plugin;
        }

        $runtimePath = (string) ($plugin->install_source['path'] ?? $plugin->getCurrentRuntimePath() ?? '');

        if ($runtimePath === '' || ! is_dir($runtimePath)) {
            $warnings = collect($plugin->warnings ?? [])
                ->push('The mounted source directory for this development plugin is no longer available.')
                ->unique()
                ->values()
                ->all();

            if (($plugin->warnings ?? []) !== $warnings || $plugin->last_error !== 'Development plugin source directory is unavailable.') {
                $plugin->warnings = $warnings;
                $plugin->last_error = 'Development plugin source directory is unavailable.';
                $plugin->load_status = 'failed';
                $plugin->load_error_at = now()->toAtomString();
                $plugin->enabled = false;
                $plugin->save();
                $this->lifecycleLogStore->append($plugin, 'error', 'startup', 'Development plugin source directory is unavailable.');
            }
        }

        try {
            $package = $this->archiveService->inspectDirectory($runtimePath, 'development_mount');
            $this->assertCoreCompatibility($package->manifest);
            $dependencyState = $this->dependencyService->summarize($package->manifest, $plugin->dependency_state ?? []);
            $mountContext = $this->developmentMountContextForPath($runtimePath) ?? [
                'path' => (string) ($plugin->install_source['mount_path'] ?? ''),
                'label' => 'Development source',
                'relativePath' => (string) ($plugin->install_source['relative_path'] ?? basename($runtimePath)),
            ];
            $source = array_merge($plugin->install_source ?? [], [
                'type' => 'development_mount',
                'mount_path' => $mountContext['path'],
                'path' => $runtimePath,
                'relative_path' => $mountContext['relativePath'],
                'live' => true,
            ]);

            $versions = $plugin->versions ?? [];
            $versions[$package->manifest['version']] = array_merge($versions[$package->manifest['version']] ?? [], [
                'path' => $runtimePath,
                'archive_sha256' => $package->archiveSha256,
                'installed_at' => $versions[$package->manifest['version']]['installed_at'] ?? now()->toAtomString(),
                'source' => $source,
                'trust_level' => $package->trustLevel,
            ]);

            $plugin->fill([
                'name' => $package->manifest['name'],
                'description' => $package->manifest['description'] ?? null,
                'author' => $package->manifest['author'] ?? null,
                'current_version' => $package->manifest['version'],
                'trust_level' => $package->trustLevel,
                'install_source' => $source,
                'manifest' => $this->encodeMongoSafeValue($package->manifest),
                'permissions' => $package->manifest['permissions'] ?? [],
                'hooks' => array_keys($package->manifest['hooks'] ?? []),
                'actions' => $package->manifest['actions'] ?? [],
                'ui_extensions' => $this->encodeMongoSafeValue($package->manifest['uiExtensions'] ?? []),
                'versions' => $versions,
                'warnings' => $this->mergeWarnings($package->warnings, $dependencyState['warnings'] ?? []),
                'dependency_state' => $dependencyState,
                'settings' => $this->mergeSettings(
                    $this->declaredSettingsDefaults($package->manifest),
                    $plugin->settings ?? []
                ),
                'state' => is_array($plugin->state) ? $plugin->state : [],
                'last_error' => null,
                'load_status' => $plugin->enabled ? 'ready' : 'disabled',
                'load_error_at' => null,
            ]);

            if ($plugin->isDirty()) {
                $plugin->save();
                $this->lifecycleLogStore->append($plugin, 'info', 'sync', 'Development plugin source synchronized from disk.');
            }
        } catch (\Throwable $exception) {
            $warnings = collect($plugin->warnings ?? [])
                ->push('The mounted development plugin could not be refreshed from disk.')
                ->unique()
                ->values()
                ->all();

            if (($plugin->warnings ?? []) !== $warnings || $plugin->last_error !== $exception->getMessage()) {
                $plugin->warnings = $warnings;
                $plugin->last_error = $exception->getMessage();
                $plugin->load_status = 'failed';
                $plugin->load_error_at = now()->toAtomString();
                $plugin->enabled = false;
                $plugin->save();
                $this->lifecycleLogStore->append(
                    $plugin,
                    'error',
                    'startup',
                    $exception->getMessage(),
                    ['exception' => $exception::class]
                );
            }
        }

        return $plugin->fresh() ?? $plugin;
    }

    private function synchronizePackagedPluginTrust(Plugin $plugin): Plugin
    {
        if (($plugin->install_source['type'] ?? null) === 'development_mount') {
            return $plugin;
        }

        $runtimePath = (string) ($plugin->getCurrentRuntimePath() ?? '');

        if ($runtimePath === '' || ! is_dir($runtimePath)) {
            return $plugin;
        }

        try {
            $package = $this->archiveService->inspectDirectory($runtimePath, 'installed_runtime');
        } catch (\Throwable) {
            return $plugin;
        }

        $warnings = $this->replaceTrustWarnings($plugin->warnings ?? [], $package->warnings);
        $currentVersion = $plugin->current_version;
        $versions = $plugin->versions ?? [];
        $versionRecord = is_string($currentVersion) ? ($versions[$currentVersion] ?? null) : null;

        $versionTrustChanged = is_array($versionRecord)
            && (($versionRecord['trust_level'] ?? null) !== $package->trustLevel);

        if (
            $plugin->trust_level === $package->trustLevel
            && $warnings === ($plugin->warnings ?? [])
            && ! $versionTrustChanged
        ) {
            return $plugin;
        }

        $plugin->trust_level = $package->trustLevel;
        $plugin->warnings = $warnings;

        if ($versionTrustChanged && is_string($currentVersion)) {
            $versions[$currentVersion]['trust_level'] = $package->trustLevel;
            $plugin->versions = $versions;
        }

        $plugin->save();

        return $plugin->fresh() ?? $plugin;
    }

    private function replaceTrustWarnings(array $warnings, array $packageWarnings): array
    {
        $filteredWarnings = array_values(array_filter(
            $warnings,
            fn ($warning) => ! in_array($warning, [
                'This plugin is not signed. Treat it as a sideloaded package.',
                'Plugin signature could not be verified with the configured or synced trusted keys.',
            ], true)
        ));

        return $this->mergeWarnings($filteredWarnings, $packageWarnings);
    }

    private function decodedManifest(Plugin $plugin): array
    {
        $manifest = $this->decodeMongoSafeValue($plugin->manifest ?? []);

        return is_array($manifest) ? $manifest : [];
    }

    private function localizeManifest(Plugin $plugin, array $manifest): array
    {
        $translations = $this->loadManifestTranslations($plugin, $manifest);

        if ($translations === []) {
            return $manifest;
        }

        $localizer = $this->manifestLocalizer ??= new PluginManifestLocalizer;

        return $localizer->localizeManifest(
            $manifest,
            $translations,
            app()->getLocale(),
            (string) ($manifest['i18n']['defaultLocale'] ?? config('app.fallback_locale', 'en'))
        );
    }

    private function loadManifestTranslations(Plugin $plugin, array $manifest): array
    {
        $runtimePath = $plugin->getCurrentRuntimePath();
        $translationFiles = $manifest['i18n']['files'] ?? [];

        if (! is_string($runtimePath) || $runtimePath === '' || ! is_array($translationFiles)) {
            return [];
        }

        $translations = [];

        foreach ($translationFiles as $locale => $reference) {
            if (! is_string($reference) || ! str_starts_with($reference, 'asset://')) {
                continue;
            }

            $assetPath = ltrim(substr($reference, strlen('asset://')), DIRECTORY_SEPARATOR);
            $absolutePath = rtrim($runtimePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$assetPath;

            if (! is_file($absolutePath)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($absolutePath), true);

            if (is_array($decoded)) {
                $translations[$this->normalizeLocale((string) $locale)] = $decoded;
            }
        }

        return $translations;
    }

    private function encodeMongoSafeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->encodeMongoSafeValue($item), $value);
        }

        $encoded = [];

        foreach ($value as $key => $item) {
            $encodedKey = str_replace(
                ['$', '.'],
                ["\u{FF04}", "\u{FF0E}"],
                (string) $key
            );
            $encoded[$encodedKey] = $this->encodeMongoSafeValue($item);
        }

        return $encoded;
    }

    private function decodeMongoSafeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->decodeMongoSafeValue($item), $value);
        }

        $decoded = [];

        foreach ($value as $key => $item) {
            $decodedKey = str_replace(
                ["\u{FF04}", "\u{FF0E}"],
                ['$', '.'],
                (string) $key
            );
            $decoded[$decodedKey] = $this->decodeMongoSafeValue($item);
        }

        return $decoded;
    }

    private function globalAutomaticUpdatesEnabled(): bool
    {
        $value = Configuration::get(self::AUTOMATIC_UPDATES_CONFIGURATION_KEY, true);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function saveGlobalAutomaticUpdatesPreference(bool $enabled): void
    {
        $configuration = Configuration::firstOrNew(['key' => self::AUTOMATIC_UPDATES_CONFIGURATION_KEY]);
        $configuration->forceFill([
            'key' => self::AUTOMATIC_UPDATES_CONFIGURATION_KEY,
            'value' => $enabled,
            'default' => true,
            'hint' => 'Global automatic plugin updates',
            'type' => DataType::BOOLEAN,
            'section' => 'Plugins',
            'description' => 'Automatically check for and install updates for registry plugins that opt in.',
            'visible' => false,
            'writeable' => true,
        ]);
        $configuration->save();
    }

    private function supportsAutomaticUpdates(Plugin $plugin): bool
    {
        $manifest = $this->decodedManifest($plugin);
        if (
            (int) ($manifest['sdkRevision'] ?? 0) >= 6
            && data_get($manifest, 'updates.managedByCore') === true
        ) {
            return false;
        }

        return in_array($plugin->install_source['type'] ?? null, ['official_registry', 'trusted_registry'], true);
    }

    private function defaultAutomaticUpdatesEnabledForSource(string $sourceType, array $manifest = []): bool
    {
        if (
            (int) ($manifest['sdkRevision'] ?? 0) >= 6
            && data_get($manifest, 'updates.managedByCore') === true
        ) {
            return false;
        }

        return in_array($sourceType, ['official_registry', 'trusted_registry'], true)
            && $this->globalAutomaticUpdatesEnabled();
    }

    private function shouldAutomaticallyUpdate(Plugin $plugin): bool
    {
        if (! $this->globalAutomaticUpdatesEnabled()) {
            return false;
        }

        if (! $this->supportsAutomaticUpdates($plugin)) {
            return false;
        }

        return (bool) ($plugin->automatic_update_enabled ?? false);
    }

    private function inspectPluginUpdate(Plugin $plugin): array
    {
        if (! $this->supportsAutomaticUpdates($plugin)) {
            $plugin->update_available = false;
            $plugin->latest_version = $plugin->current_version;
            $plugin->last_update_checked_at = now()->toAtomString();
            $plugin->save();

            return [
                'id' => $plugin->plugin_id,
                'status' => 'unsupported',
                'currentVersion' => $plugin->current_version,
                'latestVersion' => $plugin->current_version,
            ];
        }

        $sourceId = $plugin->install_source['registry']['source']['id'] ?? null;

        try {
            $package = $this->registryClient->getPackage($plugin->plugin_id, null, $sourceId);
        } catch (\Throwable $exception) {
            $plugin->last_update_checked_at = now()->toAtomString();
            $plugin->save();

            return [
                'id' => $plugin->plugin_id,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }

        $latestVersion = (string) ($package['latestVersion'] ?? $package['version'] ?? $plugin->current_version ?? '');
        $currentVersion = (string) ($plugin->current_version ?? '');
        $repairRequired = ! $this->currentRuntimePathIsAvailable($plugin);
        $updateAvailable = ($latestVersion !== '' && $latestVersion !== $currentVersion) || $repairRequired;

        $plugin->latest_version = $latestVersion !== '' ? $latestVersion : $currentVersion;
        $plugin->update_available = $updateAvailable;
        $plugin->last_update_checked_at = now()->toAtomString();
        $plugin->save();

        return [
            'id' => $plugin->plugin_id,
            'status' => $updateAvailable ? 'update_available' : 'up_to_date',
            'currentVersion' => $currentVersion,
            'latestVersion' => $plugin->latest_version,
            'repairRequired' => $repairRequired,
        ];
    }

    private function normalizeExtensionAssetUrls(string $pluginId, array $extensions): array
    {
        return array_map(function (array $extension) use ($pluginId) {
            if (! empty($extension['url'])) {
                $extension['url'] = $this->normalizeAssetReference($pluginId, (string) $extension['url']);
            }

            if (! empty($extension['bundle']['url'])) {
                $extension['bundle']['url'] = $this->normalizeAssetReference($pluginId, (string) $extension['bundle']['url']);
            }

            return $extension;
        }, $extensions);
    }

    private function normalizeAssetReference(string $pluginId, string $reference): string
    {
        if (! str_starts_with($reference, 'asset://')) {
            return $reference;
        }

        $assetPath = ltrim(substr($reference, strlen('asset://')), DIRECTORY_SEPARATOR);

        return '/backend/api/plugins/'.rawurlencode($pluginId).'/assets/'.implode('/', array_map('rawurlencode', explode('/', $assetPath)));
    }

    private function resolveManagedRuntime(array $runtime, array $dependencies): array
    {
        if (! empty($dependencies['runtime']['baseUrl'])) {
            $runtime['baseUrl'] = $dependencies['runtime']['baseUrl'];
        }

        return $runtime;
    }

    private function mergeWarnings(array ...$warningSets): array
    {
        return collect($warningSets)
            ->flatten(1)
            ->filter(fn ($warning) => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning) => trim($warning))
            ->unique()
            ->values()
            ->all();
    }

    private function resolveLoadStatus(Plugin $plugin): string
    {
        if (! $this->currentRuntimePathIsAvailable($plugin)) {
            return 'failed';
        }

        if (is_string($plugin->load_status) && $plugin->load_status !== '') {
            return $plugin->load_status;
        }

        return $plugin->enabled ? 'ready' : 'disabled';
    }

    private function currentRuntimePathIsAvailable(Plugin $plugin): bool
    {
        $runtimePath = (string) ($plugin->getCurrentRuntimePath() ?? '');

        return $runtimePath !== '' && is_dir($runtimePath);
    }

    private function latestLogEntry(Plugin $plugin): ?array
    {
        $logs = is_array($plugin->logs) ? $plugin->logs : [];

        if ($logs === []) {
            return null;
        }

        return $logs[count($logs) - 1];
    }

    private function serializeRegistryPackage(array $package): array
    {
        $dependencies = $this->dependencyService->summarize($package['manifest'] ?? $package);

        return array_merge($package, [
            'classification' => $dependencies['classification'] ?? 'lightweight',
            'dependencies' => $dependencies,
            'warnings' => $this->mergeWarnings($package['warnings'] ?? [], $dependencies['warnings'] ?? []),
        ]);
    }

    private function declaredSettingsDefaults(array $manifest): array
    {
        $defaults = data_get($manifest, 'settings.defaults', []);

        return is_array($defaults) ? $defaults : [];
    }

    private function resolvedPluginSettings(Plugin $plugin): array
    {
        return $this->mergeSettings(
            $this->declaredSettingsDefaults($this->decodedManifest($plugin)),
            is_array($plugin->settings) ? $plugin->settings : []
        );
    }

    private function mergeSettings(array $defaults, array $overrides): array
    {
        $merged = $defaults;

        foreach ($overrides as $key => $value) {
            if (is_array($value) && is_array($merged[$key] ?? null)) {
                $merged[$key] = $this->mergeSettings($merged[$key], $value);

                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function normalizeManifestComponents(string $pluginId, array $components): array
    {
        return array_map(function (array $component) use ($pluginId) {
            if (! empty($component['entry'])) {
                $component['entry'] = $this->normalizeAssetReference($pluginId, (string) $component['entry']);
            }

            return $component;
        }, $components);
    }

    private function normalizeLocale(string $locale): string
    {
        [$language, $region] = array_pad(explode('_', str_replace('-', '_', trim($locale)), 2), 2, null);
        $language = strtolower((string) $language);
        $region = $region ? strtoupper($region) : null;

        return $region ? "{$language}_{$region}" : $language;
    }

    private function detectAssetMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'js', 'mjs' => 'text/javascript',
            'css' => 'text/css',
            'html', 'htm' => 'text/html',
            'json' => 'application/json',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    private function resolveDevelopmentPath(string $path): string
    {
        $mounts = $this->developmentMountRoots();

        if ($mounts === []) {
            throw new PluginRuntimeException('The live development plugin mount is unavailable. Start WPrint 3D with ./run.sh -e dev.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $resolvedPath = realpath($path);

            if (! $resolvedPath || ! is_dir($resolvedPath)) {
                throw new PluginRuntimeException("Development plugin directory not found: {$path}");
            }

            $mountContext = $this->developmentMountContextForPath($resolvedPath);

            if ($mountContext === null) {
                throw new PluginRuntimeException('Development plugins must live inside the configured development mount paths.');
            }

            return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
        }

        foreach ($mounts as $mount) {
            $candidate = $mount['path'].DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
            $resolvedPath = realpath($candidate);

            if ($resolvedPath && is_dir($resolvedPath)) {
                return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
            }
        }

        throw new PluginRuntimeException("Development plugin directory not found: {$path}");
    }

    private function developmentMountRoot(bool $includeImplicitRoots = true): ?string
    {
        return $this->developmentMountRoots($includeImplicitRoots)[0]['path'] ?? null;
    }

    private function developmentModeEnabled(): bool
    {
        if ($this->developmentModeExplicitlyEnabled()) {
            return true;
        }

        return $this->developmentMountRoot(false) !== null;
    }

    private function developmentModeExplicitlyEnabled(): bool
    {
        if ((bool) config('plugins.development.enabled', false)) {
            return true;
        }

        $envValue = env('DEVELOPER_MODE');

        if ($envValue !== null && filter_var($envValue, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $serverValue = $_SERVER['DEVELOPER_MODE'] ?? $_ENV['DEVELOPER_MODE'] ?? getenv('DEVELOPER_MODE');

        return filter_var($serverValue, FILTER_VALIDATE_BOOL);
    }

    private function developmentMountRoots(bool $includeImplicitRoots = true): array
    {
        $candidates = [];

        if ($includeImplicitRoots) {
            $candidates[] = base_path('plugins');
        }

        foreach ($this->configuredDevelopmentMountPaths() as $path) {
            $candidates[] = $path;
        }

        $mounts = [];
        $seenPaths = [];

        foreach ($candidates as $candidate) {
            $normalizedPath = $this->resolveDevelopmentMountCandidate($candidate);

            if ($normalizedPath === null || isset($seenPaths[$normalizedPath])) {
                continue;
            }

            $mounts[] = [
                'path' => $normalizedPath,
                'label' => $this->developmentMountLabel($normalizedPath),
            ];
            $seenPaths[$normalizedPath] = true;
        }

        return $mounts;
    }

    private function configuredDevelopmentMountPaths(): array
    {
        $configured = config('plugins.development.mount_paths', []);

        if (! is_array($configured)) {
            $configured = array_map('trim', explode(',', (string) $configured));
        }

        $paths = [
            (string) config('plugins.development.mount_path', ''),
            ...$configured,
        ];

        return array_values(array_unique(array_filter(array_map(
            fn ($path) => rtrim((string) $path, DIRECTORY_SEPARATOR),
            $paths
        ))));
    }

    private function resolveDevelopmentMountCandidate(string $candidate): ?string
    {
        $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);

        if ($candidate === '') {
            return null;
        }

        $resolvedPath = realpath($candidate);

        if ($resolvedPath && is_dir($resolvedPath)) {
            return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
        }

        if (is_dir($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function developmentMountContextForPath(string $path): ?array
    {
        $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR);

        foreach ($this->developmentMountRoots() as $mount) {
            $normalizedRoot = rtrim($mount['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $normalizedResolvedPath = $normalizedPath.DIRECTORY_SEPARATOR;

            if (str_starts_with($normalizedResolvedPath, $normalizedRoot)) {
                return [
                    'path' => $mount['path'],
                    'label' => $mount['label'],
                    'relativePath' => ltrim(substr($normalizedPath, strlen($mount['path'])), DIRECTORY_SEPARATOR),
                ];
            }
        }

        return null;
    }

    private function developmentMountLabel(string $path): string
    {
        $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR);

        if ($normalizedPath === rtrim(base_path('plugins'), DIRECTORY_SEPARATOR)) {
            return 'Local plugins';
        }

        return 'Example plugins';
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $target = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($target)) {
                $this->deleteDirectory($target);

                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
