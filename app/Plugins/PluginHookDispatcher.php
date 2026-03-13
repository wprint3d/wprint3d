<?php

namespace App\Plugins;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Support\Facades\Log;
use Throwable;

class PluginHookDispatcher
{
    public function __construct(
        private PluginManager $pluginManager,
        private PluginRuntimeRegistry $runtimeRegistry,
        private PluginEffectExecutor $effectExecutor,
    ) {
    }

    public function dispatch(string $hook, array $context = []): array
    {
        $responses = [];

        foreach ($this->pluginManager->getEnabledPluginsForHook($hook) as $plugin) {
            try {
                $adapter = $this->runtimeRegistry->resolve($plugin['manifest']['runtime']['type']);
                $result = $adapter->invokeHook($plugin, $hook, $context);

                $responses[] = [
                    'pluginId' => $plugin['id'],
                    'hook' => $hook,
                    'result' => $result,
                ];

                $this->effectExecutor->execute($plugin, $result['effects'] ?? []);
            } catch (Throwable $throwable) {
                Log::warning('Plugin hook dispatch failed', [
                    'plugin' => $plugin['id'] ?? null,
                    'hook' => $hook,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        return $responses;
    }
}
