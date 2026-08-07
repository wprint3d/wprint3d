<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Exceptions\InvalidPluginManifestException;
use App\Plugins\PluginManifestValidator;
use PHPUnit\Framework\TestCase;

class PluginManifestValidatorTest extends TestCase
{
    public function test_it_accepts_a_supported_manifest(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $manifest = $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'sdkRevision' => 1,
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
                    'mobilePresentation' => 'gauges',
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
        $this->assertSame(1, $manifest['sdkRevision']);
        $this->assertSame('app.boot', array_key_first($manifest['hooks']));
        $this->assertSame('settings_tab', $manifest['uiExtensions'][0]['surface']);
        $this->assertSame('navbar_widget', $manifest['uiExtensions'][1]['surface']);
        $this->assertSame('gauges', $manifest['uiExtensions'][1]['mobilePresentation']);
    }

    public function test_it_rejects_detached_mobile_presentation_outside_navbar_widgets(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('only supported for navbar widgets');

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
                    'mode' => 'declarative',
                    'title' => 'Demo',
                    'mobilePresentation' => 'card',
                    'schema' => [
                        'component' => 'text',
                        'text' => 'Hello world',
                    ],
                ],
            ],
        ]);
    }

    public function test_it_defaults_to_the_current_sdk_revision_when_not_declared(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $manifest = $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ]);

        $this->assertSame(1, $manifest['sdkRevision']);
    }

    public function test_it_normalizes_canonical_plugin_urls(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $manifest = $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
            'homepageUrl' => 'https://example.com/plugin',
            'documentationUrl' => 'https://example.com/plugin/docs',
            'sourceUrl' => 'https://github.com/example/plugin',
        ]);

        $this->assertSame('https://example.com/plugin', $manifest['homepageUrl']);
        $this->assertSame('https://example.com/plugin/docs', $manifest['documentationUrl']);
        $this->assertSame('https://github.com/example/plugin', $manifest['sourceUrl']);
    }

    public function test_it_rejects_invalid_canonical_plugin_urls(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('homepageUrl must be an absolute http(s) URL');

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
                'printer.read',
            ],
            'homepageUrl' => 'github.com/example/plugin',
        ]);
    }

    public function test_it_rejects_unknown_sdk_revisions(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('Unsupported plugin SDK revision');

        $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'sdkRevision' => 99,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ]);
    }

    public function test_it_requires_asset_backed_webviews_to_declare_their_assets(): void
    {
        $validator = new PluginManifestValidator(
            [
                'ui.settings_tab',
                'ui.webview',
            ],
            null,
            ['settings_tab'],
            ['declarative', 'webview'],
            1,
            1,
        );

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('references undeclared asset');

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
                'ui.webview',
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'webview',
                    'title' => 'Demo',
                    'url' => 'asset://ui/index.html',
                ],
            ],
        ]);
    }

    public function test_it_accepts_declared_browser_components(): void
    {
        $validator = new PluginManifestValidator(
            [
                'ui.settings_tab',
                'ui.custom_bundle',
            ],
            null,
            ['settings_tab'],
            ['declarative', 'custom_bundle'],
            1,
            1,
        );

        $manifest = $validator->validate([
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
                'ui.custom_bundle',
            ],
            'assets' => [
                ['path' => 'components/widget.js'],
                ['path' => 'ui/settings.html'],
            ],
            'components' => [
                [
                    'id' => 'metricsWidget',
                    'kind' => 'browser_module',
                    'entry' => 'asset://components/widget.js',
                ],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'custom_bundle',
                    'title' => 'Demo',
                    'bundle' => [
                        'url' => 'asset://ui/settings.html',
                    ],
                    'components' => ['metricsWidget'],
                ],
            ],
        ]);

        $this->assertSame('metricsWidget', $manifest['components'][0]['id']);
        $this->assertSame('browser_module', $manifest['components'][0]['kind']);
    }

    public function test_it_accepts_declared_remote_components(): void
    {
        $validator = new PluginManifestValidator(
            [
                'ui.settings_tab',
            ],
            null,
            ['settings_tab'],
            ['declarative'],
            1,
            1,
        );

        $manifest = $validator->validate([
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
            'components' => [
                [
                    'id' => 'hostPanel',
                    'kind' => 'remote_component',
                    'schema' => [
                        'component' => 'section',
                        'title' => ['$prop' => 'title', 'default' => 'Panel'],
                        'children' => [
                            [
                                'component' => 'text',
                                'text' => 'Hello {{name}}',
                            ],
                        ],
                    ],
                ],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'declarative',
                    'title' => 'Demo',
                    'schema' => [
                        'component' => 'remote_component',
                        'componentId' => 'hostPanel',
                        'props' => [
                            'title' => 'Host panel',
                            'name' => 'world',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('remote_component', $manifest['components'][0]['kind']);
    }

    public function test_it_accepts_heavyweight_image_dependencies_and_requirements(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $manifest = $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'sdkRevision' => 1,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'metrics-service',
            ],
            'permissions' => [
                'network.outbound',
            ],
            'images' => [
                [
                    'id' => 'metrics-service',
                    'image' => 'ghcr.io/acme/metrics-service:1.2.3',
                    'engine' => 'auto',
                    'service' => [
                        'port' => 9310,
                        'networkAlias' => 'acme-metrics',
                    ],
                    'healthcheck' => [
                        'command' => ['curl', '-f', 'http://127.0.0.1:9310/health'],
                        'timeoutSecs' => 15,
                    ],
                ],
            ],
            'requirements' => [
                'memoryMb' => 1024,
                'cpuCores' => 2,
            ],
        ]);

        $this->assertSame('metrics-service', $manifest['runtime']['managedImageId']);
        $this->assertSame('ghcr.io/acme/metrics-service:1.2.3', $manifest['images'][0]['image']);
        $this->assertSame(1024, $manifest['requirements']['memoryMb']);
        $this->assertSame(2.0, $manifest['requirements']['cpuCores']);
    }

    public function test_it_accepts_plugin_settings_defaults(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $manifest = $validator->validate([
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
            'settings' => [
                'defaults' => [
                    'displayRaspiTemp' => true,
                    'soc_name' => 'SoC',
                ],
            ],
        ]);

        $this->assertTrue($manifest['settings']['defaults']['displayRaspiTemp']);
        $this->assertSame('SoC', $manifest['settings']['defaults']['soc_name']);
    }

    public function test_it_rejects_non_object_plugin_settings_defaults(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('Plugin settings.defaults must be an object');

        $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [],
            'settings' => [
                'defaults' => 'invalid',
            ],
        ]);
    }

    public function test_it_rejects_bridge_managed_images_without_a_declared_service_port(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('must reference an image that declares service.port');

        $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'metrics-service',
            ],
            'permissions' => [
                'network.outbound',
            ],
            'images' => [
                [
                    'id' => 'metrics-service',
                    'image' => 'ghcr.io/acme/metrics-service:1.2.3',
                ],
            ],
        ]);
    }

    public function test_it_rejects_invalid_minimum_resource_requirements(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('Plugin requirements.memoryMb must be greater than zero');

        $validator->validate([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [],
            'requirements' => [
                'memoryMb' => 0,
            ],
        ]);
    }

    public function test_it_rejects_unknown_permissions(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

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
        $validator = new PluginManifestValidator(null, null, null, null, 1, 1);

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

    public function test_it_rejects_unknown_component_references(): void
    {
        $validator = new PluginManifestValidator(
            [
                'ui.settings_tab',
                'ui.custom_bundle',
            ],
            null,
            ['settings_tab'],
            ['declarative', 'custom_bundle'],
            1,
            1,
        );

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('references unknown component');

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
                'ui.custom_bundle',
            ],
            'assets' => [
                ['path' => 'ui/settings.html'],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'custom_bundle',
                    'title' => 'Demo',
                    'bundle' => [
                        'url' => 'asset://ui/settings.html',
                    ],
                    'components' => ['metricsWidget'],
                ],
            ],
        ]);
    }

    public function test_it_rejects_remote_components_without_a_schema(): void
    {
        $validator = new PluginManifestValidator(
            [
                'ui.settings_tab',
            ],
            null,
            ['settings_tab'],
            ['declarative'],
            1,
            1,
        );

        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('must declare schema');

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
            'components' => [
                [
                    'id' => 'hostPanel',
                    'kind' => 'remote_component',
                ],
            ],
        ]);
    }
}
