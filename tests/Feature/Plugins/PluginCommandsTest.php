<?php

namespace Tests\Feature\Plugins;

use App\Plugins\Contracts\PluginManager;
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
}
