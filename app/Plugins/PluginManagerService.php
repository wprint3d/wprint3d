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
    ) {}

    public function listInstalled(): array
    {
        return Plugin::query()->orderBy('name')->get()->map(fn (Plugin $plugin) => $this->serializePlugin($plugin))->all();
    }

    public function listRegistry(): array
    {
        return $this->registryClient->listPackages();
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
            'warnings' => $package->warnings,
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
        $package = $this->archiveService->inspectDirectory($resolvedPath, 'development_mount');
        $this->assertCoreCompatibility($package->manifest);

        $plugin = Plugin::firstOrNew(['plugin_id' => $package->manifest['id']]);
        $storagePath = config('plugins.paths.runtime').DIRECTORY_SEPARATOR.$package->manifest['id'];
        @mkdir($storagePath, 0777, true);

        $source = [
            'type' => 'development_mount',
            'mount_path' => config('plugins.development.mount_path'),
            'path' => $resolvedPath,
            'relative_path' => ltrim(str_replace($this->developmentMountRoot() ?: '', '', $resolvedPath), DIRECTORY_SEPARATOR),
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
            'warnings' => $package->warnings,
            'last_error' => null,
        ]);
        $plugin->save();

        return $this->serializePlugin($plugin->fresh());
    }

    public function enable(string $pluginId): array
    {
        $plugin = $this->requireModel($pluginId);
        $payload = $this->serializePlugin($plugin);

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
        $plugin->enabled = false;
        $plugin->save();

        return $this->serializePlugin($plugin->fresh());
    }

    public function uninstall(string $pluginId): void
    {
        $plugin = $this->requireModel($pluginId);

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
            ];
        })->all();
    }

    public function safeModeDisableAll(): int
    {
        $count = Plugin::enabled()->count();
        Plugin::enabled()->update(['enabled' => false]);

        return $count;
    }

    public function findModel(string $pluginId): ?Plugin
    {
        return Plugin::where('plugin_id', $pluginId)->first();
    }

    public function listDevelopmentPlugins(): array
    {
        if (! config('plugins.development.enabled', false)) {
            return [];
        }

        $root = $this->developmentMountRoot();

        if (! $root || ! is_dir($root)) {
            return [];
        }

        $plugins = [];

        foreach (scandir($root) ?: [] as $entry) {
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
                    'warning' => $exception->getMessage(),
                ];

                continue;
            }

            $plugins[] = [
                'id' => $package->manifest['id'],
                'name' => $package->manifest['name'],
                'description' => $package->manifest['description'] ?? null,
                'version' => $package->manifest['version'],
                'path' => $directory,
                'relativePath' => $entry,
                'trustLevel' => $package->trustLevel,
                'warnings' => $package->warnings,
            ];
        }

        return collect($plugins)->sortBy('name')->values()->all();
    }

    public function installFromUploadedFile(UploadedFile $file): array
    {
        return $this->installFromArchive($file->getRealPath(), 'local_upload', [
            'original_name' => $file->getClientOriginalName(),
        ]);
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

        return [
            'id' => $plugin->plugin_id,
            'name' => $plugin->name,
            'description' => $plugin->description,
            'author' => $plugin->author,
            'version' => $plugin->current_version,
            'enabled' => (bool) $plugin->enabled,
            'trustLevel' => $plugin->trust_level ?? 'unsigned',
            'installSource' => $plugin->install_source ?? [],
            'manifest' => $plugin->manifest ?? [],
            'permissions' => $plugin->permissions ?? [],
            'hooks' => $plugin->hooks ?? [],
            'actions' => $plugin->actions ?? [],
            'uiExtensions' => $plugin->ui_extensions ?? [],
            'warnings' => $plugin->warnings ?? [],
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
            $source = array_merge($plugin->install_source ?? [], [
                'type' => 'development_mount',
                'mount_path' => config('plugins.development.mount_path'),
                'path' => $runtimePath,
                'relative_path' => ltrim(str_replace($this->developmentMountRoot() ?: '', '', $runtimePath), DIRECTORY_SEPARATOR),
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
                'warnings' => $package->warnings,
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

    private function resolveDevelopmentPath(string $path): string
    {
        if (! config('plugins.development.enabled', false)) {
            throw new PluginRuntimeException('Development mount support is disabled.');
        }

        $root = $this->developmentMountRoot();

        if (! $root) {
            throw new PluginRuntimeException('The development plugin mount path is not configured.');
        }

        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $root.DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
        $resolvedPath = realpath($candidate);

        if (! $resolvedPath || ! is_dir($resolvedPath)) {
            throw new PluginRuntimeException("Development plugin directory not found: {$path}");
        }

        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedResolvedPath = rtrim($resolvedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($normalizedResolvedPath, $normalizedRoot)) {
            throw new PluginRuntimeException('Development plugins must live inside the configured development mount path.');
        }

        return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
    }

    private function developmentMountRoot(): ?string
    {
        $mountPath = (string) config('plugins.development.mount_path', '');

        if ($mountPath === '') {
            return null;
        }

        $resolvedPath = realpath($mountPath);

        return $resolvedPath ? rtrim($resolvedPath, DIRECTORY_SEPARATOR) : rtrim($mountPath, DIRECTORY_SEPARATOR);
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
