<?php

namespace Tests\Feature\Plugins;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\PluginTrustedKeySynchronizer;
use Mockery;
use Tests\TestCase;

class PluginCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_plugin_remove_is_idempotent_when_the_plugin_is_already_missing(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('uninstall')
            ->once()
            ->with('octoprint.navbartemp-port')
            ->andReturn(false);

        $this->app->instance(PluginManager::class, $manager);

        $this->artisan('plugin:remove octoprint.navbartemp-port')
            ->expectsOutput('Plugin was already absent.')
            ->assertExitCode(0);
    }

    public function test_plugin_sync_trusted_keys_runs_the_registry_key_sync(): void
    {
        $synchronizer = Mockery::mock(PluginTrustedKeySynchronizer::class);
        $synchronizer->shouldReceive('sync')
            ->once()
            ->andReturn([
                'sourcesSynced' => 2,
                'keysSynced' => 3,
                'sourcesFailed' => 0,
            ]);

        $this->app->instance(PluginTrustedKeySynchronizer::class, $synchronizer);

        $this->artisan('plugin:sync-trusted-keys')
            ->expectsOutput('Synced trusted keys from 2 sources (3 keys downloaded, 0 failed sources).')
            ->assertExitCode(0);
    }
}
