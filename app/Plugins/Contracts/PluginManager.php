<?php

namespace App\Plugins\Contracts;

use App\Models\Plugin;

interface PluginManager
{
    public function listInstalled(): array;

    public function listRegistry(): array;

    public function listRegistrySources(): array;

    public function saveRegistrySources(array $sources): array;

    public function get(string $pluginId): array;

    public function installFromArchive(string $archivePath, string $sourceType = 'local_upload', array $sourceMeta = []): array;

    public function installFromUrl(string $url): array;

    public function installFromRegistry(string $pluginId, ?string $version = null, ?string $sourceId = null): array;

    public function installFromDevelopmentPath(string $path): array;

    public function enable(string $pluginId): array;

    public function disable(string $pluginId): array;

    public function uninstall(string $pluginId): void;

    public function update(string $pluginId): array;

    public function listUiExtensions(?string $surface = null): array;

    public function invokeAction(string $pluginId, string $actionId, array $payload = [], array $context = []): array;

    public function getEnabledPluginsForHook(string $hook): array;

    public function doctor(): array;

    public function safeModeDisableAll(): int;

    public function findModel(string $pluginId): ?Plugin;

    public function listDevelopmentPlugins(): array;
}
