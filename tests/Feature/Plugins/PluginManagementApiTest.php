<?php

namespace Tests\Feature\Plugins;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class PluginManagementApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_lists_plugins_from_the_manager(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('listInstalled')
            ->once()
            ->andReturn([
                [
                    'id' => 'acme.demo',
                    'name' => 'ACME Demo',
                    'enabled' => true,
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins');

        $response
            ->assertOk()
            ->assertJsonPath('0.id', 'acme.demo')
            ->assertJsonPath('0.enabled', true);
    }

    public function test_it_enables_a_plugin(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('enable')
            ->once()
            ->with('acme.demo')
            ->andReturn([
                'id' => 'acme.demo',
                'enabled' => true,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/acme.demo/enable');

        $response
            ->assertOk()
            ->assertJsonPath('id', 'acme.demo')
            ->assertJsonPath('enabled', true);
    }

    public function test_it_reports_development_plugin_capabilities(): void
    {
        config()->set('plugins.development.enabled', true);
        config()->set('plugins.development.mount_path', '/var/www/plugins-dev');

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('listDevelopmentPlugins')
            ->once()
            ->andReturn([
                [
                    'id' => 'acme.demo',
                    'path' => '/var/www/plugins-dev/acme-demo',
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/development');

        $response
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('mountPath', '/var/www/plugins-dev')
            ->assertJsonPath('plugins.0.id', 'acme.demo');
    }

    public function test_it_lists_registry_sources(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('listRegistrySources')
            ->once()
            ->andReturn([
                [
                    'id' => 'official',
                    'name' => 'Official registry',
                    'official' => true,
                ],
                [
                    'id' => 'partner-registry',
                    'name' => 'Partner registry',
                    'official' => false,
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/registry/sources');

        $response
            ->assertOk()
            ->assertJsonPath('0.id', 'official')
            ->assertJsonPath('1.id', 'partner-registry');
    }

    public function test_it_updates_registry_sources(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('saveRegistrySources')
            ->once()
            ->with([
                [
                    'name' => 'Partner registry',
                    'indexUrl' => 'https://plugins.example.com/index.json',
                    'websiteUrl' => 'https://plugins.example.com',
                ],
            ])
            ->andReturn([
                [
                    'id' => 'official',
                    'name' => 'Official registry',
                    'official' => true,
                ],
                [
                    'id' => 'partner-registry',
                    'name' => 'Partner registry',
                    'official' => false,
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->putJson('/api/plugins/registry/sources', [
            'sources' => [
                [
                    'name' => 'Partner registry',
                    'indexUrl' => 'https://plugins.example.com/index.json',
                    'websiteUrl' => 'https://plugins.example.com',
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('1.id', 'partner-registry');
    }

    public function test_it_installs_an_unpacked_plugin_from_the_development_mount(): void
    {
        config()->set('plugins.development.enabled', true);

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('installFromDevelopmentPath')
            ->once()
            ->with('/var/www/plugins-dev/acme-demo')
            ->andReturn([
                'id' => 'acme.demo',
                'trustLevel' => 'development',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/install', [
            'unpackedPath' => '/var/www/plugins-dev/acme-demo',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', 'acme.demo')
            ->assertJsonPath('trustLevel', 'development');
    }

    public function test_it_installs_a_registry_plugin_from_a_specific_source(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('installFromRegistry')
            ->once()
            ->with('acme.demo', '1.2.3', 'partner-registry')
            ->andReturn([
                'id' => 'acme.demo',
                'trustLevel' => 'signed',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/install', [
            'pluginId' => 'acme.demo',
            'version' => '1.2.3',
            'sourceId' => 'partner-registry',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', 'acme.demo')
            ->assertJsonPath('trustLevel', 'signed');
    }

    public function test_it_rejects_unpacked_plugin_installs_when_development_mount_support_is_disabled(): void
    {
        config()->set('plugins.development.enabled', false);

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldNotReceive('installFromDevelopmentPath');

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/install', [
            'unpackedPath' => '/var/www/plugins-dev/acme-demo',
        ]);

        $response->assertForbidden();
    }

    public function test_it_exposes_sdk_metadata(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')
            ->once()
            ->andReturn([
                'current' => [
                    'version' => 1,
                    'revision' => 1,
                ],
                'versions' => [
                    1 => [
                        'defaultRevision' => 1,
                    ],
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/sdk');

        $response
            ->assertOk()
            ->assertJsonPath('current.version', 1)
            ->assertJsonPath('current.revision', 1);
    }

    public function test_it_serves_a_plugin_asset(): void
    {
        $assetPath = storage_path('framework/testing/plugin-assets-'.uniqid().'.html');
        File::ensureDirectoryExists(dirname($assetPath));
        File::put($assetPath, '<html><body>asset</body></html>');

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('resolveAsset')
            ->once()
            ->with('acme.demo', 'ui/index.html')
            ->andReturn([
                'path' => $assetPath,
                'mimeType' => 'text/html',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->get('/api/plugins/acme.demo/assets/ui/index.html');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('asset', false);
    }

    public function test_it_serves_javascript_assets_with_a_module_safe_mime_type(): void
    {
        $assetPath = storage_path('framework/testing/plugin-assets-'.uniqid().'.js');
        File::ensureDirectoryExists(dirname($assetPath));
        File::put($assetPath, 'export function mount() {}');

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('resolveAsset')
            ->once()
            ->with('acme.demo', 'components/widget.js')
            ->andReturn([
                'path' => $assetPath,
                'mimeType' => 'text/javascript',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->get('/api/plugins/acme.demo/assets/components/widget.js');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=UTF-8');
    }
}
