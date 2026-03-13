<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\Contracts\PluginRuntimeAdapter;
use App\Plugins\PluginEffectExecutor;
use App\Plugins\PluginHookCompiler;
use App\Plugins\PluginRuntimeRegistry;
use PHPUnit\Framework\TestCase;

class PluginHookCompilerTest extends TestCase
{
    public function test_it_compiles_hook_invokers_once_and_reuses_them_across_dispatches(): void
    {
        $pluginManager = $this->createMock(PluginManager::class);
        $runtimeRegistry = $this->createMock(PluginRuntimeRegistry::class);
        $effectExecutor = $this->createMock(PluginEffectExecutor::class);
        $adapter = $this->createMock(PluginRuntimeAdapter::class);

        $plugin = [
            'id' => 'acme.demo',
            'manifest' => [
                'runtime' => [
                    'type' => 'php',
                ],
            ],
            'permissions' => [],
        ];

        $pluginManager->expects($this->once())
            ->method('getEnabledPluginsForHook')
            ->with('serial.line.received')
            ->willReturn([$plugin]);

        $runtimeRegistry->expects($this->once())
            ->method('resolve')
            ->with('php')
            ->willReturn($adapter);

        $adapter->expects($this->exactly(2))
            ->method('invokeHook')
            ->with($plugin, 'serial.line.received', $this->isType('array'))
            ->willReturn(['effects' => [], 'status' => 'ok']);

        $effectExecutor->expects($this->exactly(2))
            ->method('execute')
            ->with($plugin, []);

        $compiler = new PluginHookCompiler($pluginManager, $runtimeRegistry, $effectExecutor);
        $hook = $compiler->compile('serial.line.received');

        $first = $hook(['line' => 'ok']);
        $second = $hook(['line' => 'busy']);

        $this->assertCount(1, $first);
        $this->assertSame('acme.demo', $first[0]['pluginId']);
        $this->assertSame('serial.line.received', $first[0]['hook']);
        $this->assertSame('ok', $first[0]['result']['status']);

        $this->assertCount(1, $second);
        $this->assertSame('acme.demo', $second[0]['pluginId']);
    }
}
