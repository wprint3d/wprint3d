<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Exceptions\InvalidPluginManifestException;
use App\Plugins\PluginManifestValidator;
use PHPUnit\Framework\TestCase;

class PluginManifestValidatorTest extends TestCase
{
    public function test_it_accepts_a_supported_manifest(): void
    {
        $validator = new PluginManifestValidator();

        $manifest = $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'minCoreVersion' => '1.0.0',
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
                'ui.settings_tab',
                'ui.navbar_widget',
            ],
            'hooks' => [
                'app.boot' => [
                    'handler' => 'hooks/on_boot.php',
                ],
            ],
            'actions' => [
                [
                    'id' => 'ping',
                    'label' => 'Ping',
                    'handler' => 'actions/ping.php',
                ],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'declarative',
                    'title' => 'Demo',
                    'schema' => [
                        'component' => 'section',
                        'children' => [
                            [
                                'component' => 'text',
                                'text' => 'Hello world',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'host-metrics',
                    'surface' => 'navbar_widget',
                    'mode' => 'declarative',
                    'title' => 'Host Metrics',
                    'schema' => [
                        'component' => 'gauge_cluster',
                        'dataActionId' => 'ping',
                        'items' => [
                            [
                                'id' => 'cpu',
                                'label' => 'CPU',
                                'valueKey' => 'cpu.usedPercentage',
                            ],
                            [
                                'id' => 'ram',
                                'label' => 'RAM',
                                'valueKey' => 'ram.usedPercentage',
                            ],
                        ],
                    ],
                ],
            ],
            'signature' => [
                'algorithm' => 'none',
            ],
        ]);

        $this->assertSame('acme.demo', $manifest['id']);
        $this->assertSame('php', $manifest['runtime']['type']);
        $this->assertSame('app.boot', array_key_first($manifest['hooks']));
        $this->assertSame('settings_tab', $manifest['uiExtensions'][0]['surface']);
        $this->assertSame('navbar_widget', $manifest['uiExtensions'][1]['surface']);
    }

    public function test_it_rejects_unknown_permissions(): void
    {
        $validator = new PluginManifestValidator();

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('Unknown plugin permission');

        $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'root.shell',
            ],
        ]);
    }

    public function test_it_rejects_unknown_ui_extension_modes(): void
    {
        $validator = new PluginManifestValidator();

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('Unsupported UI extension mode');

        $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'ui.settings_tab',
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'native-bundle',
                    'title' => 'Demo',
                ],
            ],
        ]);
    }
}
