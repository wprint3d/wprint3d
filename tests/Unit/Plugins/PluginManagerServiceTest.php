<?php

namespace Tests\Unit\Plugins;

use App\Models\Plugin;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\PluginArchiveService;
use App\Plugins\PluginDependencyService;
use App\Plugins\PluginLifecycleLogStore;
use App\Plugins\PluginManagerService;
use App\Plugins\PluginPackage;
use App\Plugins\PluginRegistryClient;
use App\Plugins\PluginRuntimeRegistry;
use App\Plugins\Runtimes\BridgePluginRuntimeAdapter;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class PluginManagerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plugin::query()->delete();
    }

    protected function tearDown(): void
    {
        Plugin::query()->delete();
        Mockery::close();

        parent::tearDown();
    }

    public function test_uninstall_is_a_no_op_when_the_plugin_is_missing(): void
    {
        $service = new PluginManagerService(
            Mockery::mock(PluginArchiveService::class),
            Mockery::mock(PluginRegistryClient::class),
            Mockery::mock(PluginRuntimeRegistry::class),
            Mockery::mock(PluginDependencyService::class),
            new PluginLifecycleLogStore,
        );

        $this->assertFalse($service->uninstall('missing.plugin'));
    }

    public function test_archive_install_rolls_back_staged_package_when_dependency_preparation_fails(): void
    {
        $runtimePath = storage_path('framework/testing/transactional-plugin-'.uniqid());
        File::ensureDirectoryExists($runtimePath);
        File::put($runtimePath.'/plugin.json', '{}');
        $package = new PluginPackage(
            manifest: [
                'id' => 'acme.transactional',
                'name' => 'Transactional plugin',
                'version' => '1.0.0',
                'runtime' => ['type' => 'php'],
                'permissions' => [],
                'hooks' => [],
                'actions' => [],
                'uiExtensions' => [],
            ],
            rawManifest: null,
            archivePath: 'fixture.w3dp',
            archiveSha256: str_repeat('a', 64),
            sourceType: 'local_upload',
        );
        $archiveService = Mockery::mock(PluginArchiveService::class);
        $archiveService->shouldReceive('inspect')->once()->andReturn($package);
        $archiveService->shouldReceive('extract')->once()->andReturn($runtimePath);
        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('prepare')->once()->andThrow(new PluginRuntimeException('digest mismatch'));

        $service = new PluginManagerService(
            $archiveService,
            Mockery::mock(PluginRegistryClient::class),
            Mockery::mock(PluginRuntimeRegistry::class),
            $dependencyService,
            new PluginLifecycleLogStore,
        );

        $this->expectException(PluginRuntimeException::class);
        try {
            $service->installFromArchive('fixture.w3dp');
        } finally {
            $this->assertFalse(is_dir($runtimePath));
            $this->assertNull(Plugin::where('plugin_id', 'acme.transactional')->first());
        }
    }

    public function test_update_repairs_an_up_to_date_registry_plugin_when_its_runtime_path_is_missing(): void
    {
        $missingRuntimePath = storage_path('framework/testing/missing-plugin-runtime-'.uniqid());

        Plugin::query()->create([
            'plugin_id' => 'acme.demo',
            'name' => 'ACME Demo',
            'current_version' => '0.1.0',
            'enabled' => true,
            'trust_level' => 'signed',
            'install_source' => [
                'type' => 'official_registry',
                'registry' => [
                    'source' => [
                        'id' => 'official',
                    ],
                ],
            ],
            'manifest' => [
                'runtime' => ['type' => 'php'],
                'uiExtensions' => [],
            ],
            'versions' => [
                '0.1.0' => [
                    'path' => $missingRuntimePath,
                ],
            ],
        ]);

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $registryClient = Mockery::mock(PluginRegistryClient::class);
        $runtimeRegistry = Mockery::mock(PluginRuntimeRegistry::class);
        $dependencyService = Mockery::mock(PluginDependencyService::class);

        $registryClient->shouldReceive('getPackage')
            ->once()
            ->with('acme.demo', null, 'official')
            ->andReturn([
                'version' => '0.1.0',
                'latestVersion' => '0.1.0',
            ]);

        $service = Mockery::mock(PluginManagerService::class, [
            $archiveService,
            $registryClient,
            $runtimeRegistry,
            $dependencyService,
            new PluginLifecycleLogStore,
        ])->makePartial();

        $service->shouldReceive('installFromRegistry')
            ->once()
            ->with('acme.demo', '0.1.0', 'official')
            ->andReturn([
                'id' => 'acme.demo',
                'version' => '0.1.0',
                'enabled' => true,
                'loadStatus' => 'ready',
            ]);

        $payload = $service->update('acme.demo');

        $this->assertSame('repaired', $payload['updateStatus']);
        $this->assertSame('0.1.0', $payload['previousVersion']);
        $this->assertSame('0.1.0', $payload['latestVersion']);
    }

    public function test_update_rolls_back_to_the_previous_version_when_new_bridge_healthcheck_fails(): void
    {
        config(['plugins.runtime.blue_green_updates' => false]);
        $oldPath = storage_path('framework/testing/acme-old-'.uniqid());
        File::ensureDirectoryExists($oldPath);
        Plugin::query()->create([
            'plugin_id' => 'acme.bridge',
            'name' => 'ACME Bridge',
            'current_version' => '1.0.0',
            'enabled' => true,
            'load_status' => 'ready',
            'trust_level' => 'signed',
            'install_source' => ['type' => 'official_registry', 'registry' => ['source' => ['id' => 'official']]],
            'manifest' => ['runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'], 'uiExtensions' => []],
            'versions' => ['1.0.0' => ['path' => $oldPath]],
        ]);

        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('hasCandidate')->andReturn(false);
        $dependencyService->shouldReceive('deactivate')->once();
        $dependencyService->shouldReceive('summarize')->zeroOrMoreTimes()->andReturn([
            'classification' => 'heavyweight',
            'hint' => 'heavyweight',
            'requirements' => [],
            'host' => ['cpuCores' => 8, 'memoryMb' => 8192, 'meetsRequirements' => true],
            'warnings' => [],
            'runtime' => [],
            'images' => [],
        ]);
        $pluginRegistry = Mockery::mock(PluginRegistryClient::class);
        $pluginRegistry->shouldReceive('getPackage')->once()->andReturn(['version' => '2.0.0', 'latestVersion' => '2.0.0']);
        $service = Mockery::mock(PluginManagerService::class, [
            Mockery::mock(PluginArchiveService::class),
            $pluginRegistry,
            Mockery::mock(PluginRuntimeRegistry::class),
            $dependencyService,
            new PluginLifecycleLogStore,
        ])->makePartial();
        $service->shouldReceive('installFromRegistry')->once()->andReturn([
            'id' => 'acme.bridge',
            'version' => '2.0.0',
            'enabled' => false,
            'lastError' => 'new image failed readiness',
        ]);
        $service->shouldReceive('enable')->twice()->andReturnValues([
            ['id' => 'acme.bridge', 'enabled' => false, 'lastError' => 'new image failed readiness'],
            ['id' => 'acme.bridge', 'enabled' => true, 'loadStatus' => 'ready'],
        ]);

        $payload = $service->update('acme.bridge');

        $this->assertSame('rollback', $payload['updateStatus']);
        $this->assertSame('1.0.0', Plugin::where('plugin_id', 'acme.bridge')->first()->current_version);
        File::deleteDirectory($oldPath);
    }

    public function test_missing_plugin_runtime_is_reported_as_failed_and_its_ui_extensions_are_not_loaded(): void
    {
        Plugin::query()->create([
            'plugin_id' => 'acme.demo',
            'name' => 'ACME Demo',
            'current_version' => '0.1.0',
            'enabled' => true,
            'load_status' => 'ready',
            'trust_level' => 'signed',
            'install_source' => [
                'type' => 'official_registry',
            ],
            'manifest' => [
                'runtime' => ['type' => 'php'],
                'uiExtensions' => [
                    [
                        'id' => 'settings',
                        'surface' => 'settings_tab',
                        'mode' => 'custom_bundle',
                    ],
                ],
            ],
            'versions' => [
                '0.1.0' => [
                    'path' => storage_path('framework/testing/missing-plugin-runtime-'.uniqid()),
                ],
            ],
        ]);

        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('hasCandidate')->andReturn(false);
        $dependencyService->shouldReceive('summarize')->andReturn([
            'classification' => 'lightweight',
            'requirements' => [],
            'host' => [],
            'warnings' => [],
            'runtime' => [],
            'images' => [],
        ]);

        $service = new PluginManagerService(
            Mockery::mock(PluginArchiveService::class),
            Mockery::mock(PluginRegistryClient::class),
            Mockery::mock(PluginRuntimeRegistry::class),
            $dependencyService,
            new PluginLifecycleLogStore,
        );

        $this->assertSame('failed', $service->listInstalled()[0]['loadStatus']);
        $this->assertSame([], $service->listUiExtensions());
    }

    public function test_enable_marks_plugin_as_failed_instead_of_throwing_when_startup_fails(): void
    {
        Plugin::query()->create([
            'plugin_id' => 'acme.demo',
            'name' => 'ACME Demo',
            'current_version' => '0.1.0',
            'enabled' => false,
            'trust_level' => 'unsigned',
            'manifest' => [
                'runtime' => [
                    'type' => 'bridge',
                    'baseUrl' => 'http://bridge.test',
                ],
            ],
            'permissions' => [],
            'hooks' => [],
            'actions' => [],
            'ui_extensions' => [],
            'versions' => [
                '0.1.0' => [
                    'path' => sys_get_temp_dir(),
                ],
            ],
            'warnings' => [],
        ]);

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $registryClient = Mockery::mock(PluginRegistryClient::class);
        $runtimeRegistry = Mockery::mock(PluginRuntimeRegistry::class);
        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $bridgeAdapter = Mockery::mock(BridgePluginRuntimeAdapter::class);

        $dependencyService->shouldReceive('summarize')->andReturn([
            'classification' => 'lightweight',
            'hint' => 'This plugin is lightweight.',
            'requirements' => [],
            'host' => [
                'cpuCores' => 8,
                'memoryMb' => 8192,
                'meetsRequirements' => true,
            ],
            'warnings' => [],
            'runtime' => [],
            'images' => [],
        ]);
        $dependencyService->shouldReceive('activate')->once()->andReturn([
            'warnings' => [],
        ]);
        $dependencyService->shouldReceive('hasCandidate')->andReturn(false);
        $dependencyService->shouldReceive('deactivate')->once();

        $runtimeRegistry->shouldReceive('resolve')
            ->once()
            ->with('bridge')
            ->andReturn($bridgeAdapter);

        $bridgeAdapter->shouldReceive('healthcheck')
            ->once()
            ->andThrow(new PluginRuntimeException('Bridge startup failed.'));

        $service = new PluginManagerService(
            $archiveService,
            $registryClient,
            $runtimeRegistry,
            $dependencyService,
            new PluginLifecycleLogStore,
        );

        $payload = $service->enable('acme.demo');

        $this->assertFalse($payload['enabled']);
        $this->assertSame('failed', $payload['loadStatus']);
        $this->assertSame('Bridge startup failed.', $payload['lastError']);
        $logs = $service->getLogs('acme.demo');

        $this->assertNotEmpty($logs);
        $this->assertSame('error', $logs[count($logs) - 1]['level']);
    }

    public function test_host_context_omits_runtime_urls_when_the_proxy_kill_switch_is_active(): void
    {
        config()->set('plugins.rollout.runtime_proxy_enabled', false);

        Plugin::query()->create([
            'plugin_id' => 'acme.proxy-disabled',
            'name' => 'Proxy disabled fixture',
            'current_version' => '1.0.0',
            'enabled' => true,
            'load_status' => 'ready',
            'trust_level' => 'signed',
            'manifest' => [
                'id' => 'acme.proxy-disabled',
                'name' => 'Proxy disabled fixture',
                'version' => '1.0.0',
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                ],
                'permissions' => ['ui.custom_bundle', 'storage.write'],
                'uiExtensions' => [],
                'hooks' => [],
                'actions' => [],
            ],
            'permissions' => ['ui.custom_bundle', 'storage.write'],
            'hooks' => [],
            'actions' => [],
            'ui_extensions' => [],
            'versions' => [
                '1.0.0' => ['path' => sys_get_temp_dir()],
            ],
            'warnings' => [],
        ]);

        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('summarize')
            ->once()
            ->andReturn([
                'classification' => 'heavyweight',
                'requirements' => [],
                'host' => [],
                'warnings' => [],
                'runtime' => [],
                'images' => [],
            ]);

        $service = new PluginManagerService(
            Mockery::mock(PluginArchiveService::class),
            Mockery::mock(PluginRegistryClient::class),
            Mockery::mock(PluginRuntimeRegistry::class),
            $dependencyService,
            new PluginLifecycleLogStore,
        );

        $context = $service->hostContext('acme.proxy-disabled');

        $this->assertNull($context['runtimeBase']);
        $this->assertNull($context['artifactImportBase']);
    }

    public function test_it_installs_from_a_live_development_mount_even_when_the_explicit_dev_flag_is_stale(): void
    {
        $mountPath = sys_get_temp_dir().'/wprint3d-plugin-manager-dev-mount';
        $pluginPath = $mountPath.'/acme-demo';
        File::ensureDirectoryExists($pluginPath);

        $previousEnv = getenv('DEVELOPER_MODE');
        putenv('DEVELOPER_MODE=false');
        $_ENV['DEVELOPER_MODE'] = 'false';
        $_SERVER['DEVELOPER_MODE'] = 'false';

        config()->set('plugins.development.enabled', false);
        config()->set('plugins.development.mount_path', $mountPath);
        config()->set('plugins.development.mount_paths', []);

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $registryClient = Mockery::mock(PluginRegistryClient::class);
        $runtimeRegistry = Mockery::mock(PluginRuntimeRegistry::class);
        $dependencyService = Mockery::mock(PluginDependencyService::class);

        $archiveService->shouldReceive('inspectDirectory')
            ->twice()
            ->with($pluginPath, 'development_mount')
            ->andReturn(new PluginPackage(
                manifest: [
                    'id' => 'acme.demo',
                    'name' => 'ACME Demo',
                    'version' => '0.1.0',
                    'sdkVersion' => (int) config('plugins.sdk.current.version', 1),
                    'sdkRevision' => (int) config('plugins.sdk.current.revision', 0),
                    'runtime' => ['type' => 'php'],
                    'permissions' => [],
                    'hooks' => [],
                    'actions' => [],
                    'uiExtensions' => [],
                ],
                rawManifest: [
                    'id' => 'acme.demo',
                    'name' => 'ACME Demo',
                    'version' => '0.1.0',
                    'sdkVersion' => (int) config('plugins.sdk.current.version', 1),
                    'sdkRevision' => (int) config('plugins.sdk.current.revision', 0),
                    'runtime' => ['type' => 'php'],
                    'permissions' => [],
                    'hooks' => [],
                    'actions' => [],
                    'uiExtensions' => [],
                ],
                archivePath: $pluginPath,
                archiveSha256: hash('sha256', 'acme-demo'),
                sourceType: 'development_mount',
                trustLevel: 'development',
                warnings: ['This plugin is loaded directly from the development mount and updates live from source files.'],
            ));

        $dependencyService->shouldReceive('prepare')
            ->once()
            ->andReturn([
                'classification' => 'lightweight',
                'hint' => 'This plugin is lightweight.',
                'requirements' => [],
                'host' => [
                    'cpuCores' => 8,
                    'memoryMb' => 8192,
                    'meetsRequirements' => true,
                ],
                'warnings' => [],
                'runtime' => [],
                'images' => [],
            ]);
        $dependencyService->shouldReceive('summarize')->andReturn([
            'classification' => 'lightweight',
            'hint' => 'This plugin is lightweight.',
            'requirements' => [],
            'host' => [
                'cpuCores' => 8,
                'memoryMb' => 8192,
                'meetsRequirements' => true,
            ],
            'warnings' => [],
            'runtime' => [],
            'images' => [],
        ]);

        $service = new PluginManagerService(
            $archiveService,
            $registryClient,
            $runtimeRegistry,
            $dependencyService,
            new PluginLifecycleLogStore,
        );

        try {
            $payload = $service->installFromDevelopmentPath($pluginPath);

            $this->assertSame('acme.demo', $payload['id']);
            $this->assertSame('development', $payload['trustLevel']);
            $this->assertSame('development_mount', $payload['installSource']['type']);
        } finally {
            Plugin::query()->delete();
            File::deleteDirectory($mountPath);

            if ($previousEnv === false) {
                putenv('DEVELOPER_MODE');
                unset($_ENV['DEVELOPER_MODE'], $_SERVER['DEVELOPER_MODE']);
            } else {
                putenv("DEVELOPER_MODE={$previousEnv}");
                $_ENV['DEVELOPER_MODE'] = $previousEnv;
                $_SERVER['DEVELOPER_MODE'] = $previousEnv;
            }
        }
    }

    public function test_it_recomputes_packaged_plugin_trust_after_registry_keys_sync(): void
    {
        $runtimePath = storage_path('framework/testing/plugins-installed-runtime-'.uniqid());
        File::ensureDirectoryExists($runtimePath);

        Plugin::query()->create([
            'plugin_id' => 'octoprint.navbartemp-port',
            'name' => 'OctoPrint NavbarTemp Port',
            'current_version' => '0.1.0',
            'enabled' => true,
            'trust_level' => 'invalid_signature',
            'install_source' => [
                'type' => 'official_registry',
            ],
            'manifest' => [
                'runtime' => [
                    'type' => 'php',
                ],
                'uiExtensions' => [],
                'components' => [],
            ],
            'permissions' => [],
            'hooks' => [],
            'actions' => [],
            'ui_extensions' => [],
            'versions' => [
                '0.1.0' => [
                    'path' => $runtimePath,
                    'trust_level' => 'invalid_signature',
                ],
            ],
            'warnings' => [
                'Plugin signature could not be verified with the configured or synced trusted keys.',
            ],
        ]);

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $registryClient = Mockery::mock(PluginRegistryClient::class);
        $runtimeRegistry = Mockery::mock(PluginRuntimeRegistry::class);
        $dependencyService = Mockery::mock(PluginDependencyService::class);

        $archiveService->shouldReceive('inspectDirectory')
            ->once()
            ->with($runtimePath, 'installed_runtime')
            ->andReturn(new PluginPackage(
                manifest: [
                    'id' => 'octoprint.navbartemp-port',
                    'name' => 'OctoPrint NavbarTemp Port',
                    'version' => '0.1.0',
                    'runtime' => ['type' => 'php'],
                    'uiExtensions' => [],
                    'components' => [],
                ],
                rawManifest: [
                    'id' => 'octoprint.navbartemp-port',
                    'name' => 'OctoPrint NavbarTemp Port',
                    'version' => '0.1.0',
                    'runtime' => ['type' => 'php'],
                    'uiExtensions' => [],
                    'components' => [],
                ],
                archivePath: $runtimePath,
                archiveSha256: hash('sha256', 'octoprint.navbartemp-port'),
                sourceType: 'installed_runtime',
                trustLevel: 'signed',
                warnings: [],
            ));

        $dependencyService->shouldReceive('summarize')->andReturn([
            'classification' => 'lightweight',
            'hint' => 'This plugin is lightweight.',
            'requirements' => [],
            'host' => [
                'cpuCores' => 8,
                'memoryMb' => 8192,
                'meetsRequirements' => true,
            ],
            'warnings' => [],
            'runtime' => [],
            'images' => [],
        ]);

        $service = new PluginManagerService(
            $archiveService,
            $registryClient,
            $runtimeRegistry,
            $dependencyService,
            new PluginLifecycleLogStore,
        );

        try {
            $plugins = $service->listInstalled();

            $this->assertSame('signed', $plugins[0]['trustLevel']);
            $this->assertSame([], $plugins[0]['warnings']);

            $plugin = Plugin::query()->where('plugin_id', 'octoprint.navbartemp-port')->firstOrFail();

            $this->assertSame('signed', $plugin->trust_level);
            $this->assertSame('signed', $plugin->versions['0.1.0']['trust_level']);
            $this->assertSame([], $plugin->warnings);
        } finally {
            File::deleteDirectory($runtimePath);
        }
    }
}
