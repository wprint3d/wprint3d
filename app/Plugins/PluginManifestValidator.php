<?php

namespace App\Plugins;

use App\Plugins\Exceptions\InvalidPluginManifestException;

class PluginManifestValidator
{
    public function __construct(
        private ?array $allowedPermissions = null,
        private ?array $allowedHooks = null,
        private ?array $allowedSurfaces = null,
        private ?array $allowedModes = null,
        private ?int $sdkVersion = null,
        private ?int $sdkRevision = null,
    ) {
        $this->allowedPermissions ??= $this->config('plugins.permissions', [
            'printer.read',
            'printer.command.queue',
            'camera.read',
            'host.metrics.read',
            'network.outbound',
            'storage.read',
            'storage.write',
            'ui.settings_tab',
            'ui.navbar_widget',
            'ui.printer_panel',
            'ui.printer_action',
            'ui.modal',
            'ui.page',
            'ui.webview',
            'ui.custom_bundle',
        ]);
        $this->allowedHooks ??= $this->config('plugins.hooks', [
            'app.boot',
            'serial.command.before_send',
            'serial.command.response_received',
            'serial.line.received',
            'camera.snapshot.before_take',
            'camera.snapshot.after_take',
            'print.job.started',
            'print.job.failed',
            'print.job.finished',
        ]);
        $this->allowedSurfaces ??= $this->config('plugins.ui.surfaces', [
            'settings_tab',
            'navbar_widget',
            'printer_panel',
            'printer_action',
            'modal',
            'page',
        ]);
        $this->allowedModes ??= $this->config('plugins.ui.modes', ['declarative']);
        $this->sdkVersion ??= (int) $this->config('plugins.sdk.current.version', $this->config('plugins.sdk_version', 1));
        $this->sdkRevision ??= (int) $this->config('plugins.sdk.current.revision', $this->config('plugins.sdk_revision', 0));
    }

    public function validate(array $manifest): array
    {
        foreach (['id', 'name', 'version', 'sdkVersion', 'runtime'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $manifest)) {
                throw new InvalidPluginManifestException("Missing plugin manifest key: {$requiredKey}");
            }
        }

        $manifest['sdkVersion'] = (int) $manifest['sdkVersion'];
        $manifest['sdkRevision'] = array_key_exists('sdkRevision', $manifest)
            ? (int) $manifest['sdkRevision']
            : $this->defaultRevisionForVersion($manifest['sdkVersion']);

        if (! $this->supportsSdkVersion($manifest['sdkVersion'])) {
            throw new InvalidPluginManifestException('Unsupported plugin SDK version.');
        }

        if (! $this->supportsSdkRevision($manifest['sdkVersion'], $manifest['sdkRevision'])) {
            throw new InvalidPluginManifestException('Unsupported plugin SDK revision.');
        }

        if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/', (string) $manifest['id'])) {
            throw new InvalidPluginManifestException('Plugin ID must be a dotted or dashed lowercase identifier.');
        }

        $runtimeType = $manifest['runtime']['type'] ?? null;

        if (! in_array($runtimeType, ['php', 'bridge'], true)) {
            throw new InvalidPluginManifestException('Unsupported plugin runtime type.');
        }

        if ($runtimeType === 'php' && empty($manifest['runtime']['entry'])) {
            throw new InvalidPluginManifestException('PHP plugins must declare runtime.entry.');
        }

        if ($runtimeType === 'bridge' && empty($manifest['runtime']['baseUrl']) && empty($manifest['runtime']['socketPath'])) {
            throw new InvalidPluginManifestException('Bridge plugins must declare runtime.baseUrl or runtime.socketPath.');
        }

        $manifest['permissions'] = array_values(array_unique($manifest['permissions'] ?? []));

        foreach ($manifest['permissions'] as $permission) {
            if (! in_array($permission, $this->allowedPermissions, true)) {
                throw new InvalidPluginManifestException("Unknown plugin permission: {$permission}");
            }
        }

        $manifest['hooks'] = $manifest['hooks'] ?? [];

        foreach ($manifest['hooks'] as $hookName => $hookConfig) {
            if ($this->allowedHooks && ! in_array($hookName, $this->allowedHooks, true)) {
                throw new InvalidPluginManifestException("Unsupported plugin hook: {$hookName}");
            }

            if (! is_array($hookConfig)) {
                throw new InvalidPluginManifestException("Hook configuration must be an object: {$hookName}");
            }

            if ($runtimeType === 'php' && empty($hookConfig['handler'])) {
                throw new InvalidPluginManifestException("PHP hook {$hookName} must declare a handler.");
            }

            if ($runtimeType === 'bridge' && empty($hookConfig['path']) && empty($hookConfig['handler'])) {
                throw new InvalidPluginManifestException("Bridge hook {$hookName} must declare a path.");
            }
        }

        $manifest['actions'] = array_values($manifest['actions'] ?? []);

        foreach ($manifest['actions'] as $index => $action) {
            if (empty($action['id']) || empty($action['label'])) {
                throw new InvalidPluginManifestException("Plugin action at index {$index} must declare id and label.");
            }

            if ($runtimeType === 'php' && empty($action['handler'])) {
                throw new InvalidPluginManifestException("PHP action {$action['id']} must declare a handler.");
            }

            if ($runtimeType === 'bridge' && empty($action['path']) && empty($action['handler'])) {
                throw new InvalidPluginManifestException("Bridge action {$action['id']} must declare a path.");
            }
        }

        $manifest['assets'] = $this->normalizeAssets($manifest['assets'] ?? []);
        $manifest['components'] = $this->normalizeComponents($manifest['components'] ?? [], $manifest['assets']);
        $manifest['uiExtensions'] = array_values($manifest['uiExtensions'] ?? []);

        foreach ($manifest['uiExtensions'] as $extension) {
            if (empty($extension['id']) || empty($extension['surface']) || empty($extension['title'])) {
                throw new InvalidPluginManifestException('Each UI extension must declare id, surface and title.');
            }

            if (! in_array($extension['surface'], $this->allowedSurfaces, true)) {
                throw new InvalidPluginManifestException("Unsupported UI extension surface: {$extension['surface']}");
            }

            $mode = $extension['mode'] ?? 'declarative';

            if (! in_array($mode, $this->allowedModes, true)) {
                throw new InvalidPluginManifestException("Unsupported UI extension mode: {$mode}");
            }

            if ($mode === 'declarative' && empty($extension['schema'])) {
                throw new InvalidPluginManifestException("Declarative UI extension {$extension['id']} must declare schema.");
            }

            if ($mode === 'webview' && empty($extension['url'])) {
                throw new InvalidPluginManifestException("WebView UI extension {$extension['id']} must declare url.");
            }

            if ($mode === 'custom_bundle' && empty($extension['bundle'])) {
                throw new InvalidPluginManifestException("Custom bundle UI extension {$extension['id']} must declare bundle.");
            }

            if ($mode === 'webview') {
                $this->assertDeclaredAssetReference($extension['url'], $extension['id'], $manifest['assets']);
            }

            if ($mode === 'custom_bundle') {
                $bundleUrl = $extension['bundle']['url'] ?? $extension['url'] ?? null;

                if (! $bundleUrl) {
                    throw new InvalidPluginManifestException("Custom bundle UI extension {$extension['id']} must declare bundle.url.");
                }

                $this->assertDeclaredAssetReference($bundleUrl, $extension['id'], $manifest['assets']);
            }

            foreach (($extension['components'] ?? []) as $componentId) {
                if (! collect($manifest['components'])->contains(fn (array $component) => ($component['id'] ?? null) === $componentId)) {
                    throw new InvalidPluginManifestException("UI extension {$extension['id']} references unknown component {$componentId}.");
                }
            }
        }

        $manifest['signature'] = $manifest['signature'] ?? ['algorithm' => 'none'];
        $manifest['minCoreVersion'] = $manifest['minCoreVersion'] ?? null;
        $manifest['description'] = $manifest['description'] ?? null;
        $manifest['author'] = $manifest['author'] ?? null;
        $manifest['updateSource'] = $manifest['updateSource'] ?? [];

        return $manifest;
    }

    private function supportsSdkVersion(int $version): bool
    {
        $versions = $this->config('plugins.sdk.versions', []);

        if ($versions === []) {
            return $version === $this->sdkVersion;
        }

        return array_key_exists($version, $versions);
    }

    private function supportsSdkRevision(int $version, int $revision): bool
    {
        $versions = $this->config('plugins.sdk.versions', []);
        $versionConfig = $versions[$version] ?? null;

        if (! is_array($versionConfig)) {
            return $version === $this->sdkVersion && $revision === $this->sdkRevision;
        }

        return array_key_exists($revision, $versionConfig['revisions'] ?? []);
    }

    private function defaultRevisionForVersion(int $version): int
    {
        $versions = $this->config('plugins.sdk.versions', []);
        $versionConfig = $versions[$version] ?? null;

        if (! is_array($versionConfig)) {
            return $version === $this->sdkVersion ? $this->sdkRevision : 0;
        }

        return (int) ($versionConfig['defaultRevision'] ?? $this->sdkRevision ?? 0);
    }

    private function normalizeAssets(array $assets): array
    {
        $normalizedAssets = [];

        foreach (array_values($assets) as $index => $asset) {
            if (is_string($asset)) {
                $asset = ['path' => $asset];
            }

            if (! is_array($asset) || empty($asset['path'])) {
                throw new InvalidPluginManifestException("Plugin asset at index {$index} must declare path.");
            }

            $path = ltrim((string) $asset['path'], DIRECTORY_SEPARATOR);

            if ($path === '' || str_contains($path, '..')) {
                throw new InvalidPluginManifestException("Plugin asset path is invalid: {$path}");
            }

            $normalizedAssets[] = array_merge($asset, ['path' => $path]);
        }

        return $normalizedAssets;
    }

    private function normalizeComponents(array $components, array $declaredAssets): array
    {
        $normalizedComponents = [];

        foreach (array_values($components) as $index => $component) {
            if (! is_array($component) || empty($component['id'])) {
                throw new InvalidPluginManifestException("Plugin component at index {$index} must declare id.");
            }

            $component['kind'] = $component['kind'] ?? 'remote_component';

            if (! in_array($component['kind'], ['remote_component', 'browser_module'], true)) {
                throw new InvalidPluginManifestException("Unsupported plugin component kind: {$component['kind']}");
            }

            if ($component['kind'] === 'browser_module') {
                if (empty($component['entry'])) {
                    throw new InvalidPluginManifestException("Browser module component {$component['id']} must declare entry.");
                }

                $component['exports'] = $component['exports'] ?? 'mount';
                $this->assertDeclaredAssetReference((string) $component['entry'], $component['id'], $declaredAssets);
            }

            if ($component['kind'] === 'remote_component') {
                if (empty($component['schema']) || ! is_array($component['schema'])) {
                    throw new InvalidPluginManifestException("Remote component {$component['id']} must declare schema.");
                }
            }

            $normalizedComponents[] = $component;
        }

        return $normalizedComponents;
    }

    private function assertDeclaredAssetReference(?string $reference, string $extensionId, array $declaredAssets): void
    {
        if (! is_string($reference) || ! str_starts_with($reference, 'asset://')) {
            return;
        }

        $assetPath = ltrim(substr($reference, strlen('asset://')), DIRECTORY_SEPARATOR);
        $declaredAssets = collect($declaredAssets);

        if ($declaredAssets->contains(fn (array $asset) => ($asset['path'] ?? null) === $assetPath)) {
            return;
        }

        throw new InvalidPluginManifestException("UI extension {$extensionId} references undeclared asset {$assetPath}.");
    }

    private function config(string $key, mixed $default = null): mixed
    {
        if (! function_exists('app')) {
            return $default;
        }

        try {
            $app = app();

            if (! $app || ! $app->bound('config')) {
                return $default;
            }

            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
