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

    public function test_revision_five_only_fields_are_rejected_by_legacy_manifests(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 4);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('runtime.httpProxy requires SDK revision 5');

        $validator->validate([
            'id' => 'acme.legacy',
            'name' => 'Legacy plugin',
            'version' => '1.0.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'gateway',
                'httpProxy' => ['pathPrefix' => '/api', 'methods' => ['GET']],
            ],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway:1.0.0',
                'service' => ['port' => 9311],
            ]],
        ]);
    }

    public function test_disk_requirement_must_be_positive(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('Plugin requirements.diskMb must be greater than zero');

        $validator->validate([
            'id' => 'acme.disk',
            'name' => 'Disk plugin',
            'version' => '1.0.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'php', 'entry' => 'plugin.php'],
            'requirements' => ['diskMb' => 0],
        ]);
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

    public function test_it_normalizes_revision_five_managed_runtime_controls(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $manifest = $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'gateway',
                'auth' => ['mode' => 'wprint-bridge'],
                'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                'httpProxy' => [
                    'pathPrefix' => '/api/v1',
                    'methods' => ['get', 'POST'],
                    'requestTimeoutSecs' => 30,
                    'streamTimeoutSecs' => 900,
                    'maxUploadMb' => 256,
                ],
                'artifactImports' => [[
                    'id' => 'gcode',
                    'pathPattern' => '^/api/v1/jobs/[A-Za-z0-9_-]+/gcode$',
                    'contentTypes' => ['text/plain'],
                    'maxSizeMb' => 256,
                ]],
            ],
            'permissions' => ['network.outbound', 'ui.page', 'ui.custom_bundle'],
            'requirements' => ['diskMb' => 4096],
            'integrity' => ['algorithm' => 'sha256', 'files' => ['ui/index.html' => str_repeat('a', 64)]],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => [
                    'port' => 9311,
                    'storage' => [['name' => 'data', 'target' => '/data']],
                    'resources' => ['memoryMb' => 2048, 'cpuQuota' => 100000, 'pidsLimit' => 256],
                    'security' => ['readOnlyRootFs' => true, 'noNewPrivileges' => true, 'capDrop' => ['ALL'], 'user' => '10001:10001', 'tmpfs' => [['path' => '/tmp', 'sizeMb' => 1024]]],
                    'stopGracePeriodSecs' => 30,
                ],
            ]],
        ]);

        $this->assertSame('wprint-bridge', $manifest['runtime']['auth']['mode']);
        $this->assertSame(4096, $manifest['requirements']['diskMb']);
        $this->assertSame(['/api/v1'], $manifest['runtime']['proxy']['allowedPaths']);
        $this->assertSame(['GET', 'POST'], $manifest['runtime']['proxy']['methods']);
        $this->assertSame('gcode', $manifest['runtime']['artifactImports'][0]['id']);
        $this->assertSame(str_repeat('a', 64), $manifest['integrity']['files']['ui/index.html']);
        $this->assertSame('/data', $manifest['images'][0]['service']['storage'][0]['target']);
        $this->assertSame(256, $manifest['images'][0]['service']['resources']['pidsLimit']);
        $this->assertSame('10001:10001', $manifest['images'][0]['service']['security']['user']);
        $this->assertSame(1024, $manifest['images'][0]['service']['security']['tmpfs'][0]['sizeMb']);
        $this->assertSame(30, $manifest['images'][0]['service']['stopGracePeriodSecs']);
    }

    public function test_revision_five_managed_services_cannot_select_the_docker_network(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('cannot select a Docker network');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'gateway',
            ],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => [
                    'port' => 9311,
                    'network' => 'host',
                ],
            ]],
        ]);
    }

    public function test_managed_service_user_must_be_numeric_uid_and_gid(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('security.user must be a numeric uid[:gid]');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311, 'security' => ['user' => 'cura:cura']],
            ]],
        ]);
    }

    public function test_managed_service_stop_grace_must_be_positive(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('stopGracePeriodSecs must be greater than zero');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311, 'stopGracePeriodSecs' => 0],
            ]],
        ]);
    }

    public function test_managed_service_tmpfs_is_limited_to_tmp_and_configured_size(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('sizeMb must be between 1 and 4096');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311, 'security' => ['tmpfs' => [['path' => '/tmp', 'sizeMb' => 4097]]]],
            ]],
        ]);
    }

    public function test_managed_service_resource_aliases_are_normalized_and_limited(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $manifest = $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'pullTimeoutSecs' => 900,
                'service' => ['port' => 9311, 'resources' => ['memoryMb' => 4096, 'cpuCores' => 2, 'pids' => 256]],
            ]],
        ]);

        $resources = $manifest['images'][0]['service']['resources'];
        $this->assertSame(['memoryMb' => 4096, 'cpuQuota' => 200000, 'pidsLimit' => 256], $resources);
    }

    public function test_managed_service_cap_drop_outside_host_allowlist_is_rejected(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('capDrop contains a capability outside the host allowlist');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311, 'security' => ['capDrop' => ['NET_ADMIN']]],
            ]],
        ]);
    }

    public function test_managed_service_storage_object_normalizes_mount_and_retention(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $manifest = $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311, 'storage' => ['mountPath' => '/data', 'retainOnUninstall' => true]],
            ]],
        ]);

        $this->assertSame([
            'name' => 'data',
            'target' => '/data',
            'readOnly' => false,
            'retainOnUninstall' => true,
        ], $manifest['images'][0]['service']['storage'][0]);
    }

    public function test_managed_service_storage_rejects_multiple_writable_mounts(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('only one writable volume');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311, 'storage' => [
                    ['name' => 'data', 'target' => '/data'],
                    ['name' => 'cache', 'target' => '/data/cache'],
                ]],
            ]],
        ]);
    }

    public function test_workspace_presentation_requires_page_custom_bundle(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('workspace presentation requires a page custom_bundle');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            'uiExtensions' => [[
                'id' => 'bad-workspace',
                'surface' => 'settings_tab',
                'mode' => 'custom_bundle',
                'presentation' => 'workspace',
                'title' => 'Bad workspace',
                'bundle' => ['url' => 'asset://ui/index.html'],
            ]],
            'assets' => [['id' => 'ui', 'path' => 'ui']],
            'components' => [],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311],
            ]],
        ]);
    }

    public function test_revision_five_proxy_rejects_invalid_allowed_path(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('runtime.proxy.allowedPaths contains an invalid path');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway', 'proxy' => ['allowedPaths' => ['api/v1']]],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311],
            ]],
        ]);
    }

    public function test_revision_five_proxy_timeout_is_bounded_by_host_policy(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('streamTimeoutSecs must be between 1 and 1800');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'gateway',
                'httpProxy' => [
                    'pathPrefix' => '/api/v1',
                    'methods' => ['GET'],
                    'maxUploadMb' => 256,
                    'streamTimeoutSecs' => 1801,
                ],
            ],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway@sha256:'.str_repeat('a', 64),
                'service' => ['port' => 9311],
            ]],
        ]);
    }

    public function test_revision_five_images_must_be_digest_pinned(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 5);
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('immutable @sha256 digest');

        $validator->validate([
            'id' => 'cura.web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 5,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'gateway',
            ],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway:latest',
                'service' => ['port' => 9311],
            ]],
        ]);
    }

    public function test_legacy_revision_four_managed_services_keep_network_compatibility(): void
    {
        $validator = new PluginManifestValidator(null, null, null, null, 1, 4);
        $manifest = $validator->validate([
            'id' => 'legacy.bridge',
            'name' => 'Legacy Bridge',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => [
                'type' => 'bridge',
                'managedImageId' => 'gateway',
            ],
            'images' => [[
                'id' => 'gateway',
                'image' => 'ghcr.io/example/gateway:1.0.0',
                'service' => [
                    'port' => 9311,
                    'network' => 'legacy-network',
                ],
            ]],
        ]);

        $this->assertSame('legacy-network', $manifest['images'][0]['service']['network']);
    }
}
