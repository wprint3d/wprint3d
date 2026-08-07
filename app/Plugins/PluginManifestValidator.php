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

        $this->assertRevisionFiveFields($manifest, (int) $manifest['sdkRevision']);

        if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/', (string) $manifest['id'])) {
            throw new InvalidPluginManifestException('Plugin ID must be a dotted or dashed lowercase identifier.');
        }

        $runtimeType = $manifest['runtime']['type'] ?? null;

        if (! in_array($runtimeType, ['php', 'bridge'], true)) {
            throw new InvalidPluginManifestException('Unsupported plugin runtime type.');
        }

        $manifest['runtime'] = $this->normalizeRuntime($manifest['runtime'], $runtimeType, (int) $manifest['sdkRevision']);

        if ($runtimeType === 'php' && empty($manifest['runtime']['entry'])) {
            throw new InvalidPluginManifestException('PHP plugins must declare runtime.entry.');
        }

        $manifest['images'] = $this->normalizeImages($manifest['images'] ?? [], (int) $manifest['sdkRevision']);
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

        if ($runtimeType === 'bridge' && $manifest['sdkRevision'] >= 5 && ! empty($manifest['runtime']['managedImageId'])) {
            $manifest['runtime']['auth']['mode'] = $manifest['runtime']['auth']['mode'] ?? 'wprint-bridge';
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
        $manifest['integrity'] = $this->normalizeIntegrity($manifest['integrity'] ?? []);
        $manifest['components'] = $this->normalizeComponents($manifest['components'] ?? [], $manifest['assets']);
        $manifest['uiExtensions'] = array_values($manifest['uiExtensions'] ?? []);

        foreach ($manifest['uiExtensions'] as $extension) {
            if (empty($extension['id']) || empty($extension['surface']) || empty($extension['title'])) {
                throw new InvalidPluginManifestException('Each UI extension must declare id, surface and title.');
            }

            if (! in_array($extension['surface'], $this->allowedSurfaces, true)) {
                throw new InvalidPluginManifestException("Unsupported UI extension surface: {$extension['surface']}");
            }

            if (isset($extension['presentation'])) {
                if ($extension['presentation'] !== 'workspace' || $extension['surface'] !== 'page' || ($extension['mode'] ?? 'declarative') !== 'custom_bundle') {
                    throw new InvalidPluginManifestException("UI extension {$extension['id']} workspace presentation requires a page custom_bundle extension.");
                }
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

    private function assertRevisionFiveFields(array $manifest, int $revision): void
    {
        if ($revision >= 5) {
            return;
        }

        $runtime = is_array($manifest['runtime'] ?? null) ? $manifest['runtime'] : [];
        foreach (['proxy', 'httpProxy', 'artifactImports'] as $field) {
            if (array_key_exists($field, $runtime)) {
                throw new InvalidPluginManifestException("runtime.{$field} requires SDK revision 5.");
            }
        }

        if (array_key_exists('diskMb', $manifest['requirements'] ?? [])) {
            throw new InvalidPluginManifestException('requirements.diskMb requires SDK revision 5.');
        }

        if (array_key_exists('integrity', $manifest) && $manifest['integrity'] !== []) {
            throw new InvalidPluginManifestException('integrity requires SDK revision 5.');
        }

        foreach (($manifest['images'] ?? []) as $image) {
            if (! is_array($image)) {
                continue;
            }

            if (array_key_exists('pullTimeoutSecs', $image)) {
                throw new InvalidPluginManifestException('images.pullTimeoutSecs requires SDK revision 5.');
            }

            $service = is_array($image['service'] ?? null) ? $image['service'] : [];
            foreach (['stopGracePeriodSecs'] as $field) {
                if (array_key_exists($field, $service)) {
                    throw new InvalidPluginManifestException("images.service.{$field} requires SDK revision 5.");
                }
            }

            $storage = $service['storage'] ?? [];
            if (is_array($storage) && array_key_exists('mountPath', $storage)) {
                throw new InvalidPluginManifestException('images.service.storage.mountPath requires SDK revision 5.');
            }
            if (is_array($storage)) {
                foreach (array_values($storage) as $mount) {
                    if (is_array($mount) && array_key_exists('retainOnUninstall', $mount)) {
                        throw new InvalidPluginManifestException('images.service.storage.retainOnUninstall requires SDK revision 5.');
                    }
                }
            }

            $resources = is_array($service['resources'] ?? null) ? $service['resources'] : [];
            foreach (['cpuCores', 'pids'] as $field) {
                if (array_key_exists($field, $resources)) {
                    throw new InvalidPluginManifestException("images.service.resources.{$field} requires SDK revision 5.");
                }
            }

            $security = is_array($service['security'] ?? null) ? $service['security'] : [];
            foreach (['readOnlyRootFilesystem', 'user', 'tmpfs'] as $field) {
                if (array_key_exists($field, $security)) {
                    throw new InvalidPluginManifestException("images.service.security.{$field} requires SDK revision 5.");
                }
            }
        }

        foreach (($manifest['uiExtensions'] ?? []) as $extension) {
            if (is_array($extension) && array_key_exists('presentation', $extension)) {
                throw new InvalidPluginManifestException('uiExtensions.presentation requires SDK revision 5.');
            }
        }
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

    private function normalizeIntegrity(mixed $integrity): array
    {
        if ($integrity === []) {
            return [];
        }
        if (! is_array($integrity) || ($integrity['algorithm'] ?? null) !== 'sha256' || ! is_array($integrity['files'] ?? null)) {
            throw new InvalidPluginManifestException('Plugin integrity must declare sha256 and a files object.');
        }

        $normalized = [];
        foreach ($integrity['files'] as $path => $digest) {
            if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains('/'.str_replace('\\', '/', $path).'/', '/../') || str_starts_with($path, '/')) {
                throw new InvalidPluginManifestException('Plugin integrity file path is unsafe.');
            }
            if (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/', $digest)) {
                throw new InvalidPluginManifestException("Plugin integrity digest is invalid: {$path}.");
            }
            $normalized[str_replace('\\', '/', $path)] = $digest;
        }

        return ['algorithm' => 'sha256', 'files' => $normalized];
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

    private function normalizeImages(array $images, int $manifestRevision): array
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

            if ($manifestRevision >= 5 && ! preg_match('/@sha256:[a-f0-9]{64}$/', $reference)) {
                throw new InvalidPluginManifestException("Plugin image {$id} must use an immutable @sha256 digest in SDK revision 5.");
            }

            if (in_array($id, $seenIds, true)) {
                throw new InvalidPluginManifestException("Plugin image IDs must be unique: {$id}");
            }

            $seenIds[] = $id;
            $image['engine'] = $image['engine'] ?? 'auto';

            if (isset($image['pullTimeoutSecs'])) {
                $pullTimeout = (int) $image['pullTimeoutSecs'];
                $maxPullTimeout = max(1, (int) $this->config('plugins.container.max_pull_timeout_secs', 1800));
                if ($pullTimeout <= 0 || $pullTimeout > $maxPullTimeout) {
                    throw new InvalidPluginManifestException("Plugin image {$id} pullTimeoutSecs must be between 1 and {$maxPullTimeout}.");
                }
                $image['pullTimeoutSecs'] = $pullTimeout;
            }

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

                if ($manifestRevision >= 5 && array_key_exists('network', $image['service'])) {
                    throw new InvalidPluginManifestException("Plugin image {$id} cannot select a Docker network; WPrint owns the managed runtime network.");
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
                $image['service']['storage'] = $this->normalizeStorage($image['service']['storage'] ?? []);
                $image['service']['resources'] = $this->normalizeResources($image['service']['resources'] ?? []);
                $image['service']['security'] = $this->normalizeSecurity($image['service']['security'] ?? []);
                if (array_key_exists('stopGracePeriodSecs', $image['service'])) {
                    $stopGrace = (int) $image['service']['stopGracePeriodSecs'];
                    if ($stopGrace <= 0) {
                        throw new InvalidPluginManifestException("Plugin image {$id} service.stopGracePeriodSecs must be greater than zero.");
                    }
                    $image['service']['stopGracePeriodSecs'] = $stopGrace;
                }
            }

            $normalizedImages[] = array_merge($image, [
                'id' => $id,
                'image' => $reference,
            ]);
        }

        return $normalizedImages;
    }

    private function normalizeRuntime(array $runtime, string $runtimeType, int $manifestRevision): array
    {
        $runtime['type'] = $runtimeType;

        if ($runtimeType !== 'bridge') {
            return $runtime;
        }

        if (isset($runtime['auth']) && ! is_array($runtime['auth'])) {
            throw new InvalidPluginManifestException('Bridge runtime.auth must be an object.');
        }

        $auth = $runtime['auth'] ?? [];
        $authMode = (string) ($auth['mode'] ?? 'none');

        if (! in_array($authMode, ['none', 'wprint-bridge'], true)) {
            throw new InvalidPluginManifestException('Unsupported bridge runtime auth mode.');
        }

        $runtime['auth'] = ['mode' => $authMode];

        if (isset($runtime['proxy']) && ! is_array($runtime['proxy'])) {
            throw new InvalidPluginManifestException('Bridge runtime.proxy must be an object.');
        }

        if (isset($runtime['httpProxy'])) {
            if (! is_array($runtime['httpProxy'])) {
                throw new InvalidPluginManifestException('Bridge runtime.httpProxy must be an object.');
            }

            $httpProxy = $runtime['httpProxy'];
            $pathPrefix = trim((string) ($httpProxy['pathPrefix'] ?? ''));
            if ($pathPrefix === '' || ! str_starts_with($pathPrefix, '/') || str_contains($pathPrefix, '..')) {
                throw new InvalidPluginManifestException('Bridge runtime.httpProxy.pathPrefix must be an absolute safe path.');
            }
            $methods = array_values(array_unique(array_map('strtoupper', is_array($httpProxy['methods'] ?? null) ? $httpProxy['methods'] : [])));
            $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
            if ($methods === [] || array_diff($methods, $allowedMethods) !== []) {
                throw new InvalidPluginManifestException('Bridge runtime.httpProxy.methods contains an unsupported method.');
            }
            $hostMaxUploadMb = max(1, (int) floor((int) $this->config('plugins.runtime.max_upload_payload_bytes', 268435456) / 1048576));
            $maxUploadMb = (int) ($httpProxy['maxUploadMb'] ?? $hostMaxUploadMb);
            if ($maxUploadMb <= 0 || $maxUploadMb > $hostMaxUploadMb) {
                throw new InvalidPluginManifestException("Bridge runtime.httpProxy.maxUploadMb must be between 1 and {$hostMaxUploadMb}.");
            }
            $runtime['httpProxy'] = [
                'pathPrefix' => '/'.trim($pathPrefix, '/'),
                'methods' => $methods,
                'requestTimeoutSecs' => $this->boundedRuntimeTimeout($httpProxy['requestTimeoutSecs'] ?? 30, 'requestTimeoutSecs'),
                'streamTimeoutSecs' => $this->boundedRuntimeTimeout($httpProxy['streamTimeoutSecs'] ?? 900, 'streamTimeoutSecs'),
                'maxUploadMb' => $maxUploadMb,
            ];
        }

        $proxy = $runtime['proxy'] ?? [];
        if (isset($runtime['httpProxy'])) {
            $proxy['enabled'] = true;
            $proxy['allowedPaths'] = [$runtime['httpProxy']['pathPrefix']];
            $proxy['methods'] = $runtime['httpProxy']['methods'];
            $proxy['requestTimeoutSecs'] = $runtime['httpProxy']['requestTimeoutSecs'];
            $proxy['streamTimeoutSecs'] = $runtime['httpProxy']['streamTimeoutSecs'];
            $proxy['maxUploadMb'] = $runtime['httpProxy']['maxUploadMb'];
        }
        $rawAllowedPaths = is_array($proxy['allowedPaths'] ?? null) ? array_map('strval', $proxy['allowedPaths']) : [];
        $invalidAllowedPaths = array_filter($rawAllowedPaths, fn (string $path): bool => ! str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0"));
        if ($manifestRevision >= 5 && $invalidAllowedPaths !== []) {
            throw new InvalidPluginManifestException('Bridge runtime.proxy.allowedPaths contains an invalid path.');
        }
        $runtime['proxy'] = [
            'enabled' => (bool) ($proxy['enabled'] ?? false),
            'allowedPaths' => array_values(array_filter(
                $rawAllowedPaths,
                fn (string $path) => str_starts_with($path, '/') && ! str_contains($path, '..')
            )),
        ];
        foreach (['methods', 'requestTimeoutSecs', 'streamTimeoutSecs', 'maxUploadMb'] as $proxyKey) {
            if (array_key_exists($proxyKey, $proxy)) {
                $runtime['proxy'][$proxyKey] = $proxyKey === 'methods'
                    ? array_values(array_unique(array_map('strtoupper', (array) $proxy[$proxyKey])))
                    : max(1, (int) $proxy[$proxyKey]);
            }
        }
        $runtime['artifactImports'] = $this->normalizeArtifactImports($runtime['artifactImports'] ?? []);

        return $runtime;
    }

    private function boundedRuntimeTimeout(mixed $value, string $field): int
    {
        $timeout = (int) $value;
        $max = max(1, (int) $this->config(
            $field === 'streamTimeoutSecs'
                ? 'plugins.runtime.max_proxy_stream_timeout_secs'
                : 'plugins.runtime.max_proxy_timeout_secs',
            $field === 'streamTimeoutSecs' ? 1800 : 300,
        ));

        if ($timeout <= 0 || $timeout > $max) {
            throw new InvalidPluginManifestException("Bridge runtime.httpProxy.{$field} must be between 1 and {$max}.");
        }

        return $timeout;
    }

    private function normalizeArtifactImports(mixed $imports): array
    {
        if ($imports === []) {
            return [];
        }
        if (! is_array($imports)) {
            throw new InvalidPluginManifestException('Bridge runtime.artifactImports must be an array.');
        }

        $normalized = [];
        $seen = [];
        $maxSize = max(1, (int) $this->config('plugins.runtime.max_artifact_import_bytes', 268435456));
        foreach (array_values($imports) as $index => $import) {
            if (! is_array($import) || empty($import['id']) || empty($import['pathPattern'])) {
                throw new InvalidPluginManifestException("Artifact import {$index} must declare id and pathPattern.");
            }
            $id = trim((string) $import['id']);
            $pattern = (string) $import['pathPattern'];
            if (isset($seen[$id]) || strlen($pattern) > 512 || ! str_starts_with($pattern, '^') || ! str_ends_with($pattern, '$') || str_contains($pattern, '://')) {
                throw new InvalidPluginManifestException("Artifact import {$id} has an invalid or duplicate pathPattern.");
            }
            set_error_handler(static fn () => true);
            $compiled = preg_match('~'.$pattern.'~', '');
            restore_error_handler();
            if ($compiled === false) {
                throw new InvalidPluginManifestException("Artifact import {$id} has an invalid pathPattern.");
            }
            $sizeMb = (int) ($import['maxSizeMb'] ?? floor($maxSize / 1048576));
            if ($sizeMb <= 0 || $sizeMb * 1048576 > $maxSize) {
                throw new InvalidPluginManifestException("Artifact import {$id} exceeds the host import limit.");
            }
            $seen[$id] = true;
            $normalized[] = [
                'id' => $id,
                'pathPattern' => $pattern,
                'contentTypes' => array_values(array_filter(array_map('strval', (array) ($import['contentTypes'] ?? [])))),
                'maxSizeMb' => $sizeMb,
            ];
        }

        return $normalized;
    }

    private function normalizeStorage(mixed $storage): array
    {
        if ($storage === []) {
            return [];
        }

        if (! is_array($storage)) {
            throw new InvalidPluginManifestException('Managed service storage must be an array.');
        }

        if (array_key_exists('mountPath', $storage)) {
            $storage = [[
                'name' => 'data',
                'target' => $storage['mountPath'],
                'retainOnUninstall' => $storage['retainOnUninstall'] ?? true,
            ]];
        }

        $normalized = [];
        $writableMounts = 0;

        foreach (array_values($storage) as $index => $mount) {
            if (! is_array($mount) || empty($mount['name']) || empty($mount['target'] ?? $mount['mountPath'] ?? null)) {
                throw new InvalidPluginManifestException("Managed service storage mount {$index} must declare name and target.");
            }

            $target = trim((string) ($mount['target'] ?? $mount['mountPath']));

            if (! str_starts_with($target, '/data') || str_contains($target, '..')) {
                throw new InvalidPluginManifestException("Managed service storage target {$target} must be below /data.");
            }

            if (($mount['readOnly'] ?? false) !== true) {
                $writableMounts++;
                if ($writableMounts > 1) {
                    throw new InvalidPluginManifestException('Managed service storage may declare only one writable volume.');
                }
            }

            $normalized[] = [
                'name' => trim((string) $mount['name']),
                'target' => $target,
                'readOnly' => (bool) ($mount['readOnly'] ?? false),
                'retainOnUninstall' => (bool) ($mount['retainOnUninstall'] ?? true),
            ];
        }

        return $normalized;
    }

    private function normalizeResources(mixed $resources): array
    {
        if ($resources === []) {
            return [];
        }

        if (! is_array($resources)) {
            throw new InvalidPluginManifestException('Managed service resources must be an object.');
        }

        $normalized = [];
        $aliases = [
            'memoryMb' => 'memoryMb',
            'cpuQuota' => 'cpuQuota',
            'pidsLimit' => 'pidsLimit',
            'cpuCores' => 'cpuQuota',
            'pids' => 'pidsLimit',
        ];
        $maxMemory = max(1, (int) $this->config('plugins.container.max_memory_mb', 16384));
        $maxCpuQuota = max(1, (int) $this->config('plugins.container.max_cpu_quota', 1600000));
        $maxPids = max(1, (int) $this->config('plugins.container.max_pids', 4096));

        foreach ($aliases as $inputKey => $key) {
            if (! array_key_exists($inputKey, $resources)) {
                continue;
            }

            $value = $inputKey === 'cpuCores'
                ? (int) round((float) $resources[$inputKey] * 100000)
                : (int) $resources[$inputKey];
            if ($value <= 0) {
                throw new InvalidPluginManifestException("Managed service resources.{$inputKey} must be greater than zero.");
            }

            $max = match ($key) {
                'memoryMb' => $maxMemory,
                'cpuQuota' => $maxCpuQuota,
                default => $maxPids,
            };
            if ($value > $max) {
                throw new InvalidPluginManifestException("Managed service resources.{$inputKey} exceeds the host maximum of {$max}.");
            }

            if (! isset($normalized[$key]) || $inputKey === $key) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function normalizeSecurity(mixed $security): array
    {
        if ($security === []) {
            return [];
        }

        if (! is_array($security)) {
            throw new InvalidPluginManifestException('Managed service security must be an object.');
        }

        $capDrop = $security['capDrop'] ?? ['ALL'];

        if (! is_array($capDrop)) {
            throw new InvalidPluginManifestException('Managed service security.capDrop must be an array.');
        }

        $allowedCapDrops = $this->config('plugins.container.allowed_cap_drops', ['ALL']);
        $allowedCapDrops = is_array($allowedCapDrops) ? array_map('strval', $allowedCapDrops) : ['ALL'];
        if (array_diff(array_map('strval', $capDrop), $allowedCapDrops) !== []) {
            throw new InvalidPluginManifestException('Managed service security.capDrop contains a capability outside the host allowlist.');
        }

        $user = $security['user'] ?? null;
        if ($user !== null && (! is_string($user) || ! preg_match('/^\d+(?::\d+)?$/', $user))) {
            throw new InvalidPluginManifestException('Managed service security.user must be a numeric uid[:gid].');
        }
        if ($user !== null && preg_match('/^0(?::\d+)?$/', $user)) {
            throw new InvalidPluginManifestException('Managed service security.user cannot run as root.');
        }

        $tmpfs = $security['tmpfs'] ?? [];
        if (! is_array($tmpfs)) {
            throw new InvalidPluginManifestException('Managed service security.tmpfs must be an array.');
        }

        $normalizedTmpfs = [];
        $maxTmpfsMb = max(1, (int) $this->config('plugins.container.max_tmpfs_mb', 4096));
        foreach (array_values($tmpfs) as $index => $mount) {
            if (! is_array($mount) || ! in_array($mount['path'] ?? null, ['/tmp', '/run'], true)) {
                throw new InvalidPluginManifestException("Managed service security.tmpfs entry {$index} must target /tmp or /run.");
            }

            $sizeMb = (int) ($mount['sizeMb'] ?? 0);
            if ($sizeMb <= 0 || $sizeMb > $maxTmpfsMb) {
                throw new InvalidPluginManifestException("Managed service security.tmpfs entry {$index} sizeMb must be between 1 and {$maxTmpfsMb}.");
            }

            $normalizedTmpfs[] = ['path' => (string) $mount['path'], 'sizeMb' => $sizeMb];
        }

        return [
            'readOnlyRootFs' => (bool) ($security['readOnlyRootFs'] ?? $security['readOnlyRootFilesystem'] ?? true),
            'noNewPrivileges' => (bool) ($security['noNewPrivileges'] ?? true),
            'capDrop' => array_values(array_unique(array_map('strval', $capDrop))),
            ...($user !== null ? ['user' => $user] : []),
            ...($normalizedTmpfs !== [] ? ['tmpfs' => $normalizedTmpfs] : []),
        ];
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

        if (array_key_exists('diskMb', $requirements)) {
            $normalized['diskMb'] = (int) $requirements['diskMb'];

            if ($normalized['diskMb'] <= 0) {
                throw new InvalidPluginManifestException('Plugin requirements.diskMb must be greater than zero.');
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

        if ($declaredAssets->contains(function (array $asset) use ($assetPath): bool {
            $declaredPath = trim((string) ($asset['path'] ?? ''), '/');

            return $declaredPath !== '' && ($declaredPath === $assetPath || str_starts_with($assetPath, $declaredPath.'/'));
        })) {
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
