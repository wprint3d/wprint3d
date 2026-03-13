<?php

namespace App\Plugins\Runtimes;

use App\Plugins\Contracts\PluginRuntimeAdapter;
use App\Plugins\Exceptions\PluginRuntimeException;
use Symfony\Component\Process\Process;

class PhpPluginRuntimeAdapter implements PluginRuntimeAdapter
{
    public function supports(string $runtimeType): bool
    {
        return $runtimeType === 'php';
    }

    public function invokeHook(array $plugin, string $hook, array $context = []): array
    {
        $handler = $plugin['manifest']['hooks'][$hook]['handler'] ?? null;

        if (! $handler) {
            return [];
        }

        return $this->runPhpHandler($plugin, $handler, [
            'kind' => 'hook',
            'hook' => $hook,
            'context' => $context,
        ]);
    }

    public function invokeAction(array $plugin, array $action, array $payload = [], array $context = []): array
    {
        return $this->runPhpHandler($plugin, $action['handler'], [
            'kind' => 'action',
            'action' => $action['id'],
            'payload' => $payload,
            'context' => $context,
        ]);
    }

    private function runPhpHandler(array $plugin, string $relativePath, array $payload): array
    {
        $basePath = $plugin['runtime_path'] ?? null;
        $handlerPath = $basePath ? $basePath.DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR) : null;

        if (! $handlerPath || ! is_file($handlerPath)) {
            throw new PluginRuntimeException("Plugin handler not found: {$relativePath}");
        }

        $process = new Process(
            command: ['php', $handlerPath],
            cwd: $basePath,
            env: [
                'WPRINT3D_PLUGIN_ID' => $plugin['id'] ?? '',
                'WPRINT3D_PLUGIN_STORAGE_PATH' => $plugin['storage_path'] ?? '',
                'WPRINT3D_BASE_PATH' => base_path(),
                'WPRINT3D_VENDOR_AUTOLOAD' => base_path('vendor/autoload.php'),
            ],
        );
        $process->setTimeout(config('plugins.runtime.timeout_secs', 10));
        $process->setInput(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new PluginRuntimeException(trim($process->getErrorOutput()) ?: "Plugin handler failed: {$relativePath}");
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw new PluginRuntimeException("Plugin handler did not return valid JSON: {$relativePath}");
        }

        return $decoded;
    }
}
