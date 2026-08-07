<?php

namespace App\Plugins;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\Runtime\PluginRuntimeHttpClient;

class PluginSupportBundleService
{
    private const REDACTED = '[redacted]';

    /**
     * Build a bounded, metadata-only support snapshot. Model bytes, G-code,
     * credentials, host paths, and raw Docker inspection are never included.
     */
    public function __construct(
        private PluginManager $pluginManager,
        private ?PluginRuntimeHttpClient $runtimeHttpClient = null,
    ) {
        $this->runtimeHttpClient ??= new PluginRuntimeHttpClient;
    }

    public function snapshot(): array
    {
        $installed = $this->pluginManager->listInstalled();
        $plugins = [];

        foreach ($installed as $plugin) {
            $pluginId = (string) ($plugin['id'] ?? 'unknown');
            $entry = [
                'id' => $pluginId,
                'name' => $plugin['name'] ?? null,
                'version' => $plugin['version'] ?? null,
                'trustLevel' => $plugin['trustLevel'] ?? null,
                'classification' => $plugin['classification'] ?? null,
                'enabled' => (bool) ($plugin['enabled'] ?? false),
                'manifest' => $this->sanitize($plugin['manifest'] ?? []),
                'lifecycleLog' => $this->sanitize($this->pluginManager->getLogs($pluginId)),
            ];

            if (($plugin['enabled'] ?? false) && ($plugin['classification'] ?? null) === 'heavyweight') {
                $entry['gatewayDiagnostics'] = $this->gatewayDiagnostics($plugin);
            }

            $plugins[] = $entry;
        }

        return [
            'schemaVersion' => 1,
            'generatedAt' => now()->toAtomString(),
            'plugins' => $plugins,
            'diagnostics' => $this->sanitize($this->pluginManager->doctor()),
            'builtins' => $this->builtinInventory(),
        ];
    }

    private function builtinInventory(): array
    {
        $path = (string) config('plugins.paths.builtins').DIRECTORY_SEPARATOR.'builtin'.DIRECTORY_SEPARATOR.'index.json';
        if (! is_file($path)) {
            return ['schemaVersion' => 1, 'plugins' => []];
        }

        try {
            $inventory = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

            return is_array($inventory) ? $this->sanitize($inventory) : ['schemaVersion' => 1, 'plugins' => []];
        } catch (\Throwable) {
            return ['schemaVersion' => 1, 'plugins' => [], 'error' => 'Built-in inventory could not be read.'];
        }
    }

    private function gatewayDiagnostics(array $plugin): array
    {
        try {
            $response = $this->runtimeHttpClient?->get($plugin, '/api/v1/diagnostics');
            if (! $response || ! $response->successful()) {
                return ['status' => 'unavailable', 'httpStatus' => $response?->status()];
            }

            $payload = $response->json();

            return is_array($payload)
                ? $this->sanitize($payload)
                : ['status' => 'unavailable'];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    private function sanitize(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            if (strtolower($key) === 'signature') {
                return array_filter([
                    'algorithm' => $value['algorithm'] ?? null,
                    'keyId' => $value['keyId'] ?? null,
                    'fingerprint' => $value['fingerprint'] ?? null,
                ], static fn (mixed $item): bool => $item !== null && $item !== '');
            }

            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = $this->sanitize($childValue, (string) $childKey);
            }

            return $result;
        }

        if (is_string($value) && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return $normalized !== '' && (
            str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'ciphertext')
            || str_contains($normalized, 'privatekey')
            || str_contains($normalized, 'mountpoint')
            || str_contains($normalized, 'runtimepath')
            || str_contains($normalized, 'storagepath')
            || str_contains($normalized, 'socketpath')
            || str_contains($normalized, 'hostpath')
            || $normalized === 'baseurl'
            || $normalized === 'runtimeurl'
            || str_contains($normalized, 'networkname')
            || str_contains($normalized, 'networkalias')
            || str_contains($normalized, 'containername')
            || str_contains($normalized, 'model')
            || str_contains($normalized, 'gcode')
            || in_array($normalized, ['data', 'payload', 'body', 'content'], true)
            || $normalized === 'env'
            || $normalized === 'environment'
        );
    }
}
