<?php

namespace App\Plugins;

use App\Enums\DataType;
use App\Models\Configuration;
use App\Models\Plugin;
use App\Plugins\Contracts\PluginManager;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\Runtimes\BridgePluginRuntimeAdapter;
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
        $dependencyState = $this->dependencyService->prepare($package->manifest);

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
            'enabled' => $plugin->exists ? (bool) $plugin->enabled : false,
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
            'load_status' => ($plugin->exists && $plugin->enabled) ? 'ready' : 'disabled',
            'load_error_at' => null,
            'automatic_update_enabled' => $this->defaultAutomaticUpdatesEnabledForSource($sourceType),
            'update_available' => false,
            'latest_version' => $package->manifest['version'],
            'last_update_checked_at' => null,
        ]);
        $plugin->save();
        $this->lifecycleLogStore->append(
            $plugin,
            'info',
            'install',
            "Installed plugin {$package->manifest['name']} {$package->manifest['version']}.",
            ['sourceType' => $sourceType]
        );

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
        $dependencyState = $this->dependencyService->prepare($package->manifest);

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
            'load_status' => ($plugin->exists && $plugin->enabled) ? 'ready' : 'disabled',
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

        return $this->serializePlugin($plugin->fresh());
    }

    public function enable(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $this->lifecycleLogStore->append($plugin, 'info', 'startup', 'Plugin startup requested.');

        try {
            $payload = $this->serializePlugin($plugin);
            $dependencyState = $this->dependencyService->activate($payload, $plugin->dependency_state ?? []);
            $plugin->dependency_state = $dependencyState;
            $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], $dependencyState['warnings'] ?? []);
            $plugin->save();
            $payload = $this->serializePlugin($plugin->fresh() ?? $plugin);

            if (($payload['manifest']['runtime']['type'] ?? null) === 'bridge') {
                $adapter = $this->runtimeRegistry->resolve('bridge');

                if ($adapter instanceof BridgePluginRuntimeAdapter) {
                    $this->lifecycleLogStore->append($plugin, 'info', 'startup', 'Running bridge healthcheck.');
                    $adapter->healthcheck($payload);
                }
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
                $this->dependencyService->deactivate($this->serializePlugin($plugin->fresh() ?? $plugin));
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

        if ($latestVersion !== '' && $latestVersion === $currentVersion) {
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

        return array_merge(
            $this->installFromRegistry($pluginId, $latestVersion !== '' ? $latestVersion : null, $sourceId),
            [
                'updateStatus' => 'updated',
                'previousVersion' => $currentVersion,
                'latestVersion' => $latestVersion !== '' ? $latestVersion : null,
            ]
        );
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

            foreach (($payload['uiExtensions'] ?? []) as $extension) {
                if ($surface !== null && ($extension['surface'] ?? null) !== $surface) {
                    continue;
                }

                $extensions[] = array_merge($extension, [
                    'pluginId' => $payload['id'],
                    'pluginName' => $payload['name'],
                    'pluginTrustLevel' => $payload['trustLevel'],
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

        if (! $declaredAssets->contains(fn (array $asset) => ($asset['path'] ?? null) === $normalizedAssetPath)) {
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
        return in_array($plugin->install_source['type'] ?? null, ['official_registry', 'trusted_registry'], true);
    }

    private function defaultAutomaticUpdatesEnabledForSource(string $sourceType): bool
    {
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
        $updateAvailable = $latestVersion !== '' && $latestVersion !== $currentVersion;

        $plugin->latest_version = $latestVersion !== '' ? $latestVersion : $currentVersion;
        $plugin->update_available = $updateAvailable;
        $plugin->last_update_checked_at = now()->toAtomString();
        $plugin->save();

        return [
            'id' => $plugin->plugin_id,
            'status' => $updateAvailable ? 'update_available' : 'up_to_date',
            'currentVersion' => $currentVersion,
            'latestVersion' => $plugin->latest_version,
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
        if (is_string($plugin->load_status) && $plugin->load_status !== '') {
            return $plugin->load_status;
        }

        return $plugin->enabled ? 'ready' : 'disabled';
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
