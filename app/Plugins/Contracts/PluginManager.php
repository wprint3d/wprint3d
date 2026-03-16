<?php

namespace App\Plugins\Contracts;

use App\Models\Plugin;

interface PluginManager
{
    public function sdkMetadata(): array;

    public function listInstalled(): array;

    public function listRegistry(): array;

    public function listRegistrySources(): array;

    public function saveRegistrySources(array $sources): array;

    public function getPluginPreferences(): array;

    public function updatePluginPreferences(array $preferences): array;

    public function get(string $pluginId): array;

    public function setPluginAutomaticUpdates(string $pluginId, bool $enabled): array;

    public function getSettings(string $pluginId): array;

    public function updateSettings(string $pluginId, array $settings): array;

    public function getState(string $pluginId): array;

    public function getLogs(string $pluginId): array;

    public function installFromArchive(string $archivePath, string $sourceType = 'local_upload', array $sourceMeta = []): array;

    public function installFromUrl(string $url): array;

    public function installFromRegistry(string $pluginId, ?string $version = null, ?string $sourceId = null): array;

    public function installFromDevelopmentPath(string $path): array;

    public function enable(string $pluginId): array;

    public function disable(string $pluginId): array;

    public function uninstall(string $pluginId): bool;

    public function update(string $pluginId): array;

    public function checkForPluginUpdates(bool $automaticOnly = false): array;

    public function updateAllPlugins(bool $automaticOnly = false): array;

    public function disableAll(): array;

    public function enableAll(): array;

    public function runAutomaticUpdates(): array;

    public function listUiExtensions(?string $surface = null): array;

    public function invokeAction(string $pluginId, string $actionId, array $payload = [], array $context = []): array;

    public function getEnabledPluginsForHook(string $hook): array;

    public function doctor(): array;

    public function safeModeDisableAll(): int;

    public function findModel(string $pluginId): ?Plugin;

    public function listDevelopmentPlugins(): array;

    public function resolveAsset(string $pluginId, string $assetPath): array;
}
