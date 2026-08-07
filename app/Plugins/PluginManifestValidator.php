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

        $manifest['images'] = $this->normalizeImages($manifest['images'] ?? []);
        $manifest['requirements'] = $this->normalizeRequirements($manifest['requirements'] ?? []);

        if (
            $runtimeType === 'bridge'
            && empty($manifest['runtime']['baseUrl'])
            && empty($manifest['runtime']['socketPath'])
            && empty($manifest['runtime']['managedImageId'])
        ) {
            throw new InvalidPluginManifestException('Bridge plugins must declare runtime.baseUrl, runtime.socketPath, or runtime.managedImageId.');
        }

        if ($runtimeType === 'bridge' && ! empty($manifest['runtime']['managedImageId'])) {
            $managedImage = collect($manifest['images'])
                ->firstWhere('id', (string) $manifest['runtime']['managedImageId']);

            if (! $managedImage || empty($managedImage['service']['port'])) {
                throw new InvalidPluginManifestException('Bridge runtime.managedImageId must reference an image that declares service.port.');
            }
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

            if (isset($extension['mobilePresentation'])) {
                if (! in_array($extension['mobilePresentation'], ['card', 'gauges'], true)) {
                    throw new InvalidPluginManifestException("Unsupported mobile presentation for UI extension {$extension['id']}.");
                }

                if ($extension['surface'] !== 'navbar_widget') {
                    throw new InvalidPluginManifestException("Detached mobile presentation is only supported for navbar widgets: {$extension['id']}.");
                }
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
        $manifest['homepageUrl'] = $this->normalizeOptionalUrl($manifest['homepageUrl'] ?? null, 'homepageUrl');
        $manifest['documentationUrl'] = $this->normalizeOptionalUrl($manifest['documentationUrl'] ?? null, 'documentationUrl');
        $manifest['sourceUrl'] = $this->normalizeOptionalUrl($manifest['sourceUrl'] ?? null, 'sourceUrl');
        $manifest['updateSource'] = $manifest['updateSource'] ?? [];
        $manifest['settings'] = $this->normalizeSettings($manifest['settings'] ?? []);
        $manifest['i18n'] = $this->normalizeI18n($manifest['i18n'] ?? [], $manifest['assets']);

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

    private function normalizeSettings(array $settings): array
    {
        $defaults = $settings['defaults'] ?? [];

        if ($defaults !== [] && ! is_array($defaults)) {
            throw new InvalidPluginManifestException('Plugin settings.defaults must be an object.');
        }

        return [
            'defaults' => is_array($defaults) ? $defaults : [],
        ];
    }

    private function normalizeI18n(array $i18n, array $declaredAssets): array
    {
        if ($i18n === []) {
            return [];
        }

        $defaultLocale = $i18n['defaultLocale'] ?? null;

        if ($defaultLocale !== null && ! is_string($defaultLocale)) {
            throw new InvalidPluginManifestException('Plugin i18n.defaultLocale must be a string.');
        }

        $files = $i18n['files'] ?? [];

        if ($files !== [] && ! is_array($files)) {
            throw new InvalidPluginManifestException('Plugin i18n.files must be an object.');
        }

        $normalizedFiles = [];

        foreach ($files as $locale => $reference) {
            if (! is_string($locale) || trim($locale) === '') {
                throw new InvalidPluginManifestException('Plugin i18n.files locale keys must be non-empty strings.');
            }

            if (! is_string($reference) || trim($reference) === '') {
                throw new InvalidPluginManifestException("Plugin i18n file for {$locale} must be a non-empty asset reference.");
            }

            $this->assertDeclaredAssetReference($reference, "i18n {$locale}", $declaredAssets);
            $normalizedFiles[$locale] = trim($reference);
        }

        return array_filter([
            'defaultLocale' => $defaultLocale ? trim($defaultLocale) : null,
            'files' => $normalizedFiles,
        ]);
    }

    private function normalizeImages(array $images): array
    {
        $normalizedImages = [];
        $seenIds = [];

        foreach (array_values($images) as $index => $image) {
            if (! is_array($image) || empty($image['id']) || empty($image['image'])) {
                throw new InvalidPluginManifestException("Plugin image at index {$index} must declare id and image.");
            }

            $id = trim((string) $image['id']);
            $reference = trim((string) $image['image']);

            if ($id === '' || $reference === '') {
                throw new InvalidPluginManifestException("Plugin image at index {$index} must declare id and image.");
            }

            if (in_array($id, $seenIds, true)) {
                throw new InvalidPluginManifestException("Plugin image IDs must be unique: {$id}");
            }

            $seenIds[] = $id;
            $image['engine'] = $image['engine'] ?? 'auto';

            if (! in_array($image['engine'], ['auto', 'docker'], true)) {
                throw new InvalidPluginManifestException("Plugin image {$id} declares an unsupported engine.");
            }

            if (isset($image['healthcheck'])) {
                if (! is_array($image['healthcheck']) || empty($image['healthcheck']['command'])) {
                    throw new InvalidPluginManifestException("Plugin image {$id} healthcheck must declare command.");
                }

                $image['healthcheck']['timeoutSecs'] = max(1, (int) ($image['healthcheck']['timeoutSecs'] ?? 15));
            }

            if (isset($image['service'])) {
                if (! is_array($image['service']) || empty($image['service']['port'])) {
                    throw new InvalidPluginManifestException("Plugin image {$id} service must declare port.");
                }

                $image['service']['port'] = (int) $image['service']['port'];

                if ($image['service']['port'] <= 0) {
                    throw new InvalidPluginManifestException("Plugin image {$id} service.port must be greater than zero.");
                }

                $image['service']['networkAlias'] = trim((string) ($image['service']['networkAlias'] ?? ''));
                $image['service']['environment'] = is_array($image['service']['environment'] ?? null)
                    ? $image['service']['environment']
                    : [];
                $image['service']['args'] = $this->normalizeCommand($image['service']['args'] ?? []);
            }

            $normalizedImages[] = array_merge($image, [
                'id' => $id,
                'image' => $reference,
            ]);
        }

        return $normalizedImages;
    }

    private function normalizeRequirements(array $requirements): array
    {
        if ($requirements === []) {
            return [];
        }

        $normalized = [];

        if (array_key_exists('memoryMb', $requirements)) {
            $normalized['memoryMb'] = (int) $requirements['memoryMb'];

            if ($normalized['memoryMb'] <= 0) {
                throw new InvalidPluginManifestException('Plugin requirements.memoryMb must be greater than zero.');
            }
        }

        if (array_key_exists('cpuCores', $requirements)) {
            $normalized['cpuCores'] = (float) $requirements['cpuCores'];

            if ($normalized['cpuCores'] <= 0) {
                throw new InvalidPluginManifestException('Plugin requirements.cpuCores must be greater than zero.');
            }
        }

        return $normalized;
    }

    private function normalizeOptionalUrl(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidPluginManifestException("{$field} must be an absolute http(s) URL.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidPluginManifestException("{$field} must be an absolute http(s) URL.");
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidPluginManifestException("{$field} must be an absolute http(s) URL.");
        }

        return $value;
    }

    private function normalizeCommand(mixed $command): array
    {
        if (is_string($command)) {
            $command = preg_split('/\s+/', trim($command)) ?: [];
        }

        if (! is_array($command)) {
            return [];
        }

        return array_values(array_map(
            fn ($part) => trim((string) $part),
            array_filter($command, fn ($part) => trim((string) $part) !== '')
        ));
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
