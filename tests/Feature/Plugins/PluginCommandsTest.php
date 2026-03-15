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

    public function test_plugin_update_reports_when_no_new_release_exists(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('update')
            ->once()
            ->with('octoprint.navbartemp-port')
            ->andReturn([
                'id' => 'octoprint.navbartemp-port',
                'version' => '0.1.0',
                'latestVersion' => '0.1.0',
                'updateStatus' => 'noop',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $this->artisan('plugin:update octoprint.navbartemp-port')
            ->expectsOutput('No updates found for octoprint.navbartemp-port. Already at 0.1.0.')
            ->assertExitCode(0);
    }

    public function test_plugin_update_reports_when_automatic_updates_are_not_available(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('update')
            ->once()
            ->with('octoprint.navbartemp-port')
            ->andReturn([
                'id' => 'octoprint.navbartemp-port',
                'version' => '0.1.0',
                'updateStatus' => 'unsupported',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $this->artisan('plugin:update octoprint.navbartemp-port')
            ->expectsOutput('No automatic update source is configured for octoprint.navbartemp-port.')
            ->assertExitCode(0);
    }

    public function test_plugin_auto_update_reports_a_summary(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('runAutomaticUpdates')
            ->once()
            ->andReturn([
                'checkedCount' => 3,
                'updatedCount' => 1,
                'noopCount' => 1,
                'skippedCount' => 1,
                'failedCount' => 0,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $this->artisan('plugin:auto-update')
            ->expectsOutput('Automatic plugin updates checked 3 plugins: 1 updated, 1 already current, 1 skipped, 0 failed.')
            ->assertExitCode(0);
    }
}
