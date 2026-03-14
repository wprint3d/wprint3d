<?php

namespace App\Plugins;

use App\Models\Plugin;
use App\Plugins\Contracts\PluginManager;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\Runtimes\BridgePluginRuntimeAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class PluginManagerService implements PluginManager
{
    public function __construct(
        private PluginArchiveService $archiveService,
        private PluginRegistryClient $registryClient,
        private PluginRuntimeRegistry $runtimeRegistry,
        private PluginDependencyService $dependencyService,
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

    public function get(string $pluginId): array
    {
        return $this->serializePlugin($this->requireModel($pluginId));
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
            'manifest' => $package->manifest,
            'permissions' => $package->manifest['permissions'] ?? [],
            'hooks' => array_keys($package->manifest['hooks'] ?? []),
            'actions' => $package->manifest['actions'] ?? [],
            'ui_extensions' => $package->manifest['uiExtensions'] ?? [],
            'versions' => $versions,
            'warnings' => $this->mergeWarnings($package->warnings, $dependencyState['warnings'] ?? []),
            'dependency_state' => $dependencyState,
        ]);
        $plugin->save();

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
            'manifest' => $package->manifest,
            'permissions' => $package->manifest['permissions'] ?? [],
            'hooks' => array_keys($package->manifest['hooks'] ?? []),
            'actions' => $package->manifest['actions'] ?? [],
            'ui_extensions' => $package->manifest['uiExtensions'] ?? [],
            'versions' => $versions,
            'warnings' => $this->mergeWarnings($package->warnings, $dependencyState['warnings'] ?? []),
            'dependency_state' => $dependencyState,
            'last_error' => null,
        ]);
        $plugin->save();

        return $this->serializePlugin($plugin->fresh());
    }

    public function enable(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $payload = $this->serializePlugin($plugin);
        $dependencyState = $this->dependencyService->activate($payload, $plugin->dependency_state ?? []);
        $plugin->dependency_state = $dependencyState;
        $plugin->warnings = $this->mergeWarnings($plugin->warnings ?? [], $dependencyState['warnings'] ?? []);
        $plugin->save();
        $payload = $this->serializePlugin($plugin->fresh() ?? $plugin);

        if (($payload['manifest']['runtime']['type'] ?? null) === 'bridge') {
            $adapter = $this->runtimeRegistry->resolve('bridge');

            if ($adapter instanceof BridgePluginRuntimeAdapter) {
                $adapter->healthcheck($payload);
            }
        }

        $plugin->enabled = true;
        $plugin->last_healthcheck_at = now()->toAtomString();
        $plugin->save();

        return $this->serializePlugin($plugin->fresh());
    }

    public function disable(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $this->dependencyService->deactivate($this->serializePlugin($plugin));
        $plugin->enabled = false;
        $plugin->save();

        return $this->serializePlugin($plugin->fresh());
    }

    public function uninstall(string $pluginId): void
    {
        $plugin = $this->requireModel($pluginId);
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
    }

    public function update(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $sourceType = $plugin->install_source['type'] ?? null;

        if ($sourceType === 'development_mount') {
            return $this->installFromDevelopmentPath((string) ($plugin->install_source['path'] ?? $plugin->getCurrentRuntimePath()));
        }

        return $this->installFromRegistry($pluginId);
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

        $adapter = $this->runtimeRegistry->resolve($plugin['manifest']['runtime']['type']);
        $result = $adapter->invokeAction($plugin, $action, $payload, $context);

        app(PluginEffectExecutor::class)->execute($plugin, $result['effects'] ?? []);

        return $result;
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
                'runtimePathExists' => is_dir($payload['runtimePath'] ?? ''),
                'trustLevel' => $payload['trustLevel'],
                'warnings' => $payload['warnings'],
                'classification' => $payload['classification'] ?? 'lightweight',
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
            $plugin->save();
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
        $manifest = $plugin->manifest ?? [];
        $uiExtensions = $this->normalizeExtensionAssetUrls($plugin->plugin_id, $plugin->ui_extensions ?? []);
        $manifest['uiExtensions'] = $this->normalizeExtensionAssetUrls($plugin->plugin_id, $manifest['uiExtensions'] ?? []);
        $manifest['components'] = $this->normalizeManifestComponents($plugin->plugin_id, $manifest['components'] ?? []);
        $dependencies = $this->dependencyService->summarize($manifest, $plugin->dependency_state ?? []);
        $manifest['runtime'] = $this->resolveManagedRuntime($manifest['runtime'] ?? [], $dependencies);

        return [
            'id' => $plugin->plugin_id,
            'name' => $plugin->name,
            'description' => $plugin->description,
            'author' => $plugin->author,
            'version' => $plugin->current_version,
            'enabled' => (bool) $plugin->enabled,
            'trustLevel' => $plugin->trust_level ?? 'unsigned',
            'installSource' => $plugin->install_source ?? [],
            'manifest' => $manifest,
            'permissions' => $plugin->permissions ?? [],
            'hooks' => $plugin->hooks ?? [],
            'actions' => $plugin->actions ?? [],
            'uiExtensions' => $uiExtensions,
            'warnings' => $this->mergeWarnings($plugin->warnings ?? [], $dependencies['warnings'] ?? []),
            'classification' => $dependencies['classification'] ?? 'lightweight',
            'dependencies' => $dependencies,
            'runtimePath' => $plugin->getCurrentRuntimePath(),
            'runtime_path' => $plugin->getCurrentRuntimePath(),
            'versions' => $plugin->versions ?? [],
            'lastError' => $plugin->last_error ?? null,
            'lastHealthcheckAt' => $plugin->last_healthcheck_at ?? null,
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
                $plugin->save();
            }

            return $plugin;
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
                'manifest' => $package->manifest,
                'permissions' => $package->manifest['permissions'] ?? [],
                'hooks' => array_keys($package->manifest['hooks'] ?? []),
                'actions' => $package->manifest['actions'] ?? [],
                'ui_extensions' => $package->manifest['uiExtensions'] ?? [],
                'versions' => $versions,
                'warnings' => $this->mergeWarnings($package->warnings, $dependencyState['warnings'] ?? []),
                'dependency_state' => $dependencyState,
                'last_error' => null,
            ]);

            if ($plugin->isDirty()) {
                $plugin->save();
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
                $plugin->save();
            }
        }

        return $plugin->fresh() ?? $plugin;
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

    private function serializeRegistryPackage(array $package): array
    {
        $dependencies = $this->dependencyService->summarize($package['manifest'] ?? $package);

        return array_merge($package, [
            'classification' => $dependencies['classification'] ?? 'lightweight',
            'dependencies' => $dependencies,
            'warnings' => $this->mergeWarnings($package['warnings'] ?? [], $dependencies['warnings'] ?? []),
        ]);
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
        if (! $this->developmentModeEnabled()) {
            throw new PluginRuntimeException('Development mount support is disabled.');
        }

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
