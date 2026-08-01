<?php

namespace Tests\Feature\Plugins;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\Exceptions\PluginRuntimeException;
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

    public function test_it_reads_plugin_settings(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('getSettings')
            ->once()
            ->with('acme.demo')
            ->andReturn([
                'displayRaspiTemp' => true,
                'soc_name' => 'SoC',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/acme.demo/settings');

        $response
            ->assertOk()
            ->assertJsonPath('displayRaspiTemp', true)
            ->assertJsonPath('soc_name', 'SoC');
    }

    public function test_it_updates_plugin_settings(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('updateSettings')
            ->once()
            ->with('acme.demo', [
                'displayRaspiTemp' => false,
                'soc_name' => 'Host',
            ])
            ->andReturn([
                'displayRaspiTemp' => false,
                'soc_name' => 'Host',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->putJson('/api/plugins/acme.demo/settings', [
            'settings' => [
                'displayRaspiTemp' => false,
                'soc_name' => 'Host',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('displayRaspiTemp', false)
            ->assertJsonPath('soc_name', 'Host');
    }

    public function test_it_reads_plugin_state(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('getState')
            ->once()
            ->with('acme.demo')
            ->andReturn([
                'items' => [
                    [
                        'id' => 'tool0',
                        'text' => 'E: 205.0°C',
                    ],
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/acme.demo/state');

        $response
            ->assertOk()
            ->assertJsonPath('items.0.id', 'tool0')
            ->assertJsonPath('items.0.text', 'E: 205.0°C');
    }

    public function test_it_reads_plugin_logs(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('getLogs')
            ->once()
            ->with('acme.demo')
            ->andReturn([
                [
                    'timestamp' => '2026-03-14T12:00:00+00:00',
                    'level' => 'info',
                    'stage' => 'startup',
                    'message' => 'Plugin startup completed.',
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/acme.demo/logs');

        $response
            ->assertOk()
            ->assertJsonPath('0.level', 'info')
            ->assertJsonPath('0.stage', 'startup')
            ->assertJsonPath('0.message', 'Plugin startup completed.');
    }

    public function test_it_reads_plugin_preferences(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('getPluginPreferences')
            ->once()
            ->andReturn([
                'automaticUpdatesEnabled' => true,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/preferences');

        $response
            ->assertOk()
            ->assertJsonPath('automaticUpdatesEnabled', true);
    }

    public function test_it_updates_plugin_preferences(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('updatePluginPreferences')
            ->once()
            ->with([
                'automaticUpdatesEnabled' => false,
            ])
            ->andReturn([
                'automaticUpdatesEnabled' => false,
                'disabledPluginAutomaticUpdatesCount' => 3,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->putJson('/api/plugins/preferences', [
            'automaticUpdatesEnabled' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('automaticUpdatesEnabled', false)
            ->assertJsonPath('disabledPluginAutomaticUpdatesCount', 3);
    }

    public function test_it_updates_per_plugin_automatic_updates(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('setPluginAutomaticUpdates')
            ->once()
            ->with('acme.demo', true)
            ->andReturn([
                'id' => 'acme.demo',
                'automaticUpdatesEnabled' => true,
                'automaticUpdatesSupported' => true,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->putJson('/api/plugins/acme.demo/automatic-updates', [
            'enabled' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', 'acme.demo')
            ->assertJsonPath('automaticUpdatesEnabled', true)
            ->assertJsonPath('automaticUpdatesSupported', true);
    }

    public function test_it_checks_for_plugin_updates(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('checkForPluginUpdates')
            ->once()
            ->with(false)
            ->andReturn([
                'checkedCount' => 4,
                'updatesAvailableCount' => 2,
                'upToDateCount' => 1,
                'unsupportedCount' => 1,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/check-updates');

        $response
            ->assertOk()
            ->assertJsonPath('checkedCount', 4)
            ->assertJsonPath('updatesAvailableCount', 2)
            ->assertJsonPath('unsupportedCount', 1);
    }

    public function test_it_updates_all_plugins(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('updateAllPlugins')
            ->once()
            ->with(false)
            ->andReturn([
                'checkedCount' => 4,
                'updatedCount' => 2,
                'noopCount' => 1,
                'unsupportedCount' => 1,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/update-all');

        $response
            ->assertOk()
            ->assertJsonPath('checkedCount', 4)
            ->assertJsonPath('updatedCount', 2)
            ->assertJsonPath('unsupportedCount', 1);
    }

    public function test_it_disables_all_plugins(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('disableAll')
            ->once()
            ->andReturn([
                'disabledCount' => 2,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/disable-all');

        $response
            ->assertOk()
            ->assertJsonPath('disabledCount', 2);
    }

    public function test_it_enables_all_plugins(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('enableAll')
            ->once()
            ->andReturn([
                'enabledCount' => 2,
                'failedCount' => 1,
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/enable-all');

        $response
            ->assertOk()
            ->assertJsonPath('enabledCount', 2)
            ->assertJsonPath('failedCount', 1);
    }

    public function test_it_reports_development_plugin_capabilities(): void
    {
        $mountPath = sys_get_temp_dir().'/wprint3d-test-plugins-dev';
        File::ensureDirectoryExists($mountPath);

        try {
            config()->set('plugins.development.enabled', true);
            config()->set('plugins.development.mount_path', $mountPath);

            $manager = Mockery::mock(PluginManager::class);
            $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
            $manager->shouldReceive('listDevelopmentPlugins')
                ->once()
                ->andReturn([
                    [
                        'id' => 'acme.demo',
                        'path' => $mountPath.'/acme-demo',
                    ],
                ]);

            $this->app->instance(PluginManager::class, $manager);

            $response = $this->withoutMiddleware()->getJson('/api/plugins/development');

            $response
                ->assertOk()
                ->assertJsonPath('enabled', true)
                ->assertJsonPath('available', true)
                ->assertJsonPath('mountPath', base_path('plugins'))
                ->assertJsonPath('configuredMountPath', $mountPath)
                ->assertJsonPath('mountPaths.0', base_path('plugins'))
                ->assertJsonPath('mountPaths.1', $mountPath)
                ->assertJsonPath('plugins.0.id', 'acme.demo');
        } finally {
            File::deleteDirectory($mountPath);
        }
    }

    public function test_it_uses_the_live_developer_mode_env_when_config_is_stale(): void
    {
        $previousEnv = getenv('DEVELOPER_MODE');

        putenv('DEVELOPER_MODE=true');
        $_ENV['DEVELOPER_MODE'] = 'true';
        $_SERVER['DEVELOPER_MODE'] = 'true';

        try {
            config()->set('plugins.development.enabled', false);
            config()->set('plugins.development.mount_path', '/var/www/plugins-dev');

            $manager = Mockery::mock(PluginManager::class);
            $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
            $manager->shouldReceive('listDevelopmentPlugins')
                ->once()
                ->andReturn([]);

            $this->app->instance(PluginManager::class, $manager);

            $response = $this->withoutMiddleware()->getJson('/api/plugins/development');

            $response
                ->assertOk()
                ->assertJsonPath('enabled', true)
                ->assertJsonPath('available', true)
                ->assertJsonPath('mountPath', base_path('plugins'))
                ->assertJsonPath('configuredMountPath', '/var/www/plugins-dev');
        } finally {
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

    public function test_it_uses_the_live_development_mount_as_a_fallback_signal_when_config_and_env_look_disabled(): void
    {
        $mountPath = sys_get_temp_dir().'/wprint3d-test-plugins-dev-fallback';
        File::ensureDirectoryExists($mountPath);

        $previousEnv = getenv('DEVELOPER_MODE');
        putenv('DEVELOPER_MODE=false');
        $_ENV['DEVELOPER_MODE'] = 'false';
        $_SERVER['DEVELOPER_MODE'] = 'false';

        try {
            config()->set('plugins.development.enabled', false);
            config()->set('plugins.development.mount_path', $mountPath);

            $manager = Mockery::mock(PluginManager::class);
            $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
            $manager->shouldReceive('listDevelopmentPlugins')
                ->once()
                ->andReturn([]);

            $this->app->instance(PluginManager::class, $manager);

            $response = $this->withoutMiddleware()->getJson('/api/plugins/development');

            $response
                ->assertOk()
                ->assertJsonPath('enabled', true)
                ->assertJsonPath('available', true)
                ->assertJsonPath('mountPath', base_path('plugins'))
                ->assertJsonPath('mountPaths.0', base_path('plugins'))
                ->assertJsonPath('mountPaths.1', $mountPath);
        } finally {
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

    public function test_it_ignores_the_source_tree_fallback_when_the_configured_development_mount_is_missing(): void
    {
        config()->set('plugins.development.enabled', true);
        config()->set('plugins.development.mount_path', '/path/that/does/not/exist');

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('listDevelopmentPlugins')
            ->once()
            ->andReturn([]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins/development');

        $response
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('mountPath', base_path('plugins'))
            ->assertJsonPath('configuredMountPath', '/path/that/does/not/exist')
            ->assertJsonPath('mountPaths.0', base_path('plugins'))
            ->assertJsonPath('configuredMountPaths.0', '/path/that/does/not/exist');
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

    public function test_it_installs_an_unpacked_plugin_when_the_live_mount_is_available_even_if_the_explicit_dev_flag_is_stale(): void
    {
        $mountPath = sys_get_temp_dir().'/wprint3d-test-plugins-dev-install';
        File::ensureDirectoryExists($mountPath);

        $previousEnv = getenv('DEVELOPER_MODE');
        putenv('DEVELOPER_MODE=false');
        $_ENV['DEVELOPER_MODE'] = 'false';
        $_SERVER['DEVELOPER_MODE'] = 'false';

        try {
            config()->set('plugins.development.enabled', false);
            config()->set('plugins.development.mount_path', $mountPath);
            config()->set('plugins.development.mount_paths', []);

            $manager = Mockery::mock(PluginManager::class);
            $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
            $manager->shouldReceive('installFromDevelopmentPath')
                ->once()
                ->with($mountPath.'/acme-demo')
                ->andReturn([
                    'id' => 'acme.demo',
                    'trustLevel' => 'development',
                ]);

            $this->app->instance(PluginManager::class, $manager);

            $response = $this->withoutMiddleware()->postJson('/api/plugins/install', [
                'unpackedPath' => $mountPath.'/acme-demo',
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('id', 'acme.demo')
                ->assertJsonPath('trustLevel', 'development');
        } finally {
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
        $previousEnv = getenv('DEVELOPER_MODE');

        putenv('DEVELOPER_MODE=false');
        $_ENV['DEVELOPER_MODE'] = 'false';
        $_SERVER['DEVELOPER_MODE'] = 'false';

        config()->set('plugins.development.enabled', false);
        config()->set('plugins.development.mount_path', '/path/that/does/not/exist');
        config()->set('plugins.development.mount_paths', []);

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldNotReceive('installFromDevelopmentPath');

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/install', [
            'unpackedPath' => '/var/www/plugins-dev/acme-demo',
        ]);

        $response->assertForbidden();

        if ($previousEnv === false) {
            putenv('DEVELOPER_MODE');
            unset($_ENV['DEVELOPER_MODE'], $_SERVER['DEVELOPER_MODE']);
        } else {
            putenv("DEVELOPER_MODE={$previousEnv}");
            $_ENV['DEVELOPER_MODE'] = $previousEnv;
            $_SERVER['DEVELOPER_MODE'] = $previousEnv;
        }
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

    public function test_it_returns_not_found_when_a_plugin_asset_runtime_is_unavailable(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('resolveAsset')
            ->once()
            ->with('acme.demo', 'ui/index.html')
            ->andThrow(new PluginRuntimeException('Plugin acme.demo runtime path is unavailable.'));

        $this->app->instance(PluginManager::class, $manager);

        $this->withoutMiddleware()
            ->get('/api/plugins/acme.demo/assets/ui/index.html')
            ->assertNotFound();
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

    public function test_it_serves_the_octoprint_compatibility_helper_script(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->get('/api/plugins/sdk/octoprint-compat.js');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
            ->assertSee('WPrint3DOctoPrintCompat', false);
    }

    public function test_it_returns_plugin_update_status_metadata(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('update')
            ->once()
            ->with('octoprint.navbartemp-port')
            ->andReturn([
                'id' => 'octoprint.navbartemp-port',
                'version' => '0.1.0',
                'updateStatus' => 'noop',
                'latestVersion' => '0.1.0',
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->postJson('/api/plugins/octoprint.navbartemp-port/update');

        $response
            ->assertOk()
            ->assertJsonPath('id', 'octoprint.navbartemp-port')
            ->assertJsonPath('updateStatus', 'noop')
            ->assertJsonPath('latestVersion', '0.1.0');
    }
}
