<?php

namespace App\Plugins\Runtimes;

use App\Plugins\Contracts\PluginRuntimeAdapter;
use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\Http;

class BridgePluginRuntimeAdapter implements PluginRuntimeAdapter
{
    public function supports(string $runtimeType): bool
    {
        return $runtimeType === 'bridge';
    }

    public function invokeHook(array $plugin, string $hook, array $context = []): array
    {
        $hookConfig = $plugin['manifest']['hooks'][$hook] ?? [];
        $path = $hookConfig['path'] ?? $hookConfig['handler'] ?? "/hooks/{$hook}";

        return $this->post($plugin, $path, [
            'kind' => 'hook',
            'hook' => $hook,
            'settings' => $plugin['settings'] ?? [],
            'state' => $plugin['state'] ?? [],
            'context' => $context,
        ]);
    }

    public function invokeAction(array $plugin, array $action, array $payload = [], array $context = []): array
    {
        $path = $action['path'] ?? $action['handler'] ?? "/actions/{$action['id']}";

        return $this->post($plugin, $path, [
            'kind' => 'action',
            'action' => $action['id'],
            'settings' => $plugin['settings'] ?? [],
            'state' => $plugin['state'] ?? [],
            'payload' => $payload,
            'context' => $context,
        ]);
    }

    public function healthcheck(array $plugin): void
    {
        $runtime = $plugin['manifest']['runtime'] ?? [];
        $baseUrl = $runtime['baseUrl'] ?? null;
        $path = $runtime['healthcheck'] ?? '/health';

        if (!$baseUrl) {
            throw new PluginRuntimeException('Bridge plugin is missing runtime.baseUrl.');
        }

        Http::timeout(config('plugins.runtime.bridge_timeout_secs', 5))
            ->baseUrl($baseUrl)
            ->get($path)
            ->throw();
    }

    private function post(array $plugin, string $path, array $payload): array
    {
        $runtime = $plugin['manifest']['runtime'] ?? [];
        $baseUrl = $runtime['baseUrl'] ?? null;

        if (!$baseUrl) {
            throw new PluginRuntimeException('Bridge plugin is missing runtime.baseUrl.');
        }

        $response = Http::timeout(config('plugins.runtime.bridge_timeout_secs', 5))
            ->baseUrl($baseUrl)
            ->post($path, $payload);

        $response->throw();

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
