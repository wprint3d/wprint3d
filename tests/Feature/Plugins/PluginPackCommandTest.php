<?php

namespace Tests\Feature\Plugins;

use App\Plugins\PluginPackager;
use Mockery;
use Tests\TestCase;

class PluginPackCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_defaults_the_package_output_to_the_plugin_builds_directory(): void
    {
        $source = '/var/www/plugins-dev/acme-demo';
        $expectedOutput = '/var/www/plugins-dev/acme-demo/builds/acme-demo.w3dp';

        $packager = Mockery::mock(PluginPackager::class);
        $packager->shouldReceive('build')
            ->once()
            ->with($source, $expectedOutput, null, null)
            ->andReturn($expectedOutput);

        $this->app->instance(PluginPackager::class, $packager);

        $this->artisan("plugin:pack {$source}")
            ->expectsOutput("Plugin package created at {$expectedOutput}")
            ->assertExitCode(0);
    }

    public function test_it_still_honors_an_explicit_output_path(): void
    {
        $source = '/var/www/plugins-dev/acme-demo';
        $explicitOutput = '/tmp/acme-demo-release.w3dp';

        $packager = Mockery::mock(PluginPackager::class);
        $packager->shouldReceive('build')
            ->once()
            ->with($source, $explicitOutput, null, null)
            ->andReturn($explicitOutput);

        $this->app->instance(PluginPackager::class, $packager);

        $this->artisan("plugin:pack {$source} --output={$explicitOutput}")
            ->expectsOutput("Plugin package created at {$explicitOutput}")
            ->assertExitCode(0);
    }
}
