<?php

namespace Tests\Feature\Plugins;

use App\Plugins\Contracts\PluginManager;
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
        $manager->shouldNotReceive('installFromDevelopmentPath');

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/install', [
            'unpackedPath' => '/var/www/plugins-dev/acme-demo',
        ]);

        $response->assertForbidden();
    }
}
