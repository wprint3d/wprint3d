<?php

namespace App\Plugins;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\Contracts\PluginRuntimeAdapter;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class PluginHookCompiler
{
    public function __construct(
        private PluginManager $pluginManager,
        private PluginRuntimeRegistry $runtimeRegistry,
        private PluginEffectExecutor $effectExecutor,
    ) {}

    public function compile(string $hook): Closure
    {
        $invokers = [];

        foreach ($this->pluginManager->getEnabledPluginsForHook($hook) as $plugin) {
            $adapter = $this->runtimeRegistry->resolve($plugin['manifest']['runtime']['type']);
            $invokers[] = $this->makeInvoker($plugin, $hook, $adapter);
        }

        return static function (array $context = []) use ($invokers): array {
            $responses = [];

            foreach ($invokers as $invoker) {
                $response = $invoker($context);

                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses;
        };
    }

    public function compileMany(array $hooks): array
    {
        $compiled = [];

        foreach (array_values(array_unique($hooks)) as $hook) {
            $compiled[$hook] = $this->compile($hook);
        }

        return $compiled;
    }

    public function compileSerialHooks(): array
    {
        return $this->compileMany([
            'serial.command.before_send',
            'serial.line.received',
            'serial.command.response_received',
        ]);
    }

    public function compileCameraHooks(): array
    {
        return $this->compileMany([
            'camera.snapshot.before_take',
            'camera.snapshot.after_take',
        ]);
    }

    private function makeInvoker(array $plugin, string $hook, PluginRuntimeAdapter $adapter): Closure
    {
        $effectExecutor = $this->effectExecutor;

        return static function (array $context = []) use ($plugin, $hook, $adapter, $effectExecutor): ?array {
            try {
                $result = $adapter->invokeHook($plugin, $hook, $context);

                $effectExecutor->execute($plugin, $result['effects'] ?? []);

                return [
                    'pluginId' => $plugin['id'],
                    'hook' => $hook,
                    'result' => $result,
                ];
            } catch (Throwable $throwable) {
                Log::warning('Plugin hook dispatch failed', [
                    'plugin' => $plugin['id'] ?? null,
                    'hook' => $hook,
                    'message' => $throwable->getMessage(),
                ]);

                return null;
            }
        };
    }
}
