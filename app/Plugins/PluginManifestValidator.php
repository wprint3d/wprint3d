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
        $this->sdkVersion ??= (int) $this->config('plugins.sdk_version', 1);
    }

    public function validate(array $manifest): array
    {
        foreach (['id', 'name', 'version', 'sdkVersion', 'runtime'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $manifest)) {
                throw new InvalidPluginManifestException("Missing plugin manifest key: {$requiredKey}");
            }
        }

        $manifest['sdkVersion'] = (int) $manifest['sdkVersion'];

        if ($manifest['sdkVersion'] !== $this->sdkVersion) {
            throw new InvalidPluginManifestException('Unsupported plugin SDK version.');
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
        }

        $manifest['signature'] = $manifest['signature'] ?? ['algorithm' => 'none'];
        $manifest['minCoreVersion'] = $manifest['minCoreVersion'] ?? null;
        $manifest['description'] = $manifest['description'] ?? null;
        $manifest['author'] = $manifest['author'] ?? null;
        $manifest['assets'] = array_values($manifest['assets'] ?? []);
        $manifest['updateSource'] = $manifest['updateSource'] ?? [];

        return $manifest;
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
