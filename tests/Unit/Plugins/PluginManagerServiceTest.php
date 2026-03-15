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
}
