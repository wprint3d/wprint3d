<?php

namespace Tests\Feature\Plugins;

use App\Models\Plugin;
use App\Plugins\Contracts\PluginManager;
use App\Plugins\PluginArchiveService;
use App\Plugins\PluginDependencyService;
use App\Plugins\PluginPackage;
use App\Plugins\PluginTrustedKeySynchronizer;
use Illuminate\Support\Facades\File;
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

    public function test_runtime_reconciliation_persists_the_resolved_bridge_state(): void
    {
        $plugin = Plugin::query()->create([
            'plugin_id' => 'acme.reconcile',
            'name' => 'Reconcile fixture',
            'current_version' => '1.0.0',
            'enabled' => true,
            'load_status' => 'ready',
            'manifest' => [
                'id' => 'acme.reconcile',
                'runtime' => ['type' => 'bridge', 'managedImageId' => 'gateway'],
            ],
            'dependency_state' => [],
        ]);
        $resolvedState = [
            'runtime' => ['baseUrl' => 'http://acme-reconcile-gateway:9311'],
            'images' => [],
        ];
        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('reconcile')
            ->once()
            ->withArgs(fn (array $plugins): bool => collect($plugins)->contains(
                fn (array $candidate): bool => ($candidate['id'] ?? null) === 'acme.reconcile'
            ))
            ->andReturn([[
                'id' => 'acme.reconcile',
                'status' => 'ready',
                'state' => $resolvedState,
            ]]);
        $this->app->instance(PluginDependencyService::class, $dependencyService);

        try {
            $this->artisan('plugin:reconcile-runtime')
                ->expectsOutput('acme.reconcile: ready')
                ->assertExitCode(0);

            $plugin->refresh();
            $this->assertSame($resolvedState, $plugin->dependency_state);
            $this->assertSame('ready', $plugin->load_status);
            $this->assertNull($plugin->last_error);
            $this->assertNotNull($plugin->last_healthcheck_at);
        } finally {
            $plugin->delete();
        }
    }

    public function test_cura_builtin_can_be_disabled_without_affecting_other_builtins(): void
    {
        $archivePath = sys_get_temp_dir().'/cura-web-ui-fixture.w3dp';
        config()->set('plugins.builtins.inventory', __DIR__.'/missing-builtins.json');
        config()->set('plugins.builtins.entries', [[
            'id' => 'cura-web-ui',
            'version' => '1.0.0',
            'archive' => $archivePath,
            'required' => false,
            'feature' => 'builtin_cura_enabled',
        ]]);
        config()->set('plugins.rollout.builtin_cura_enabled', false);

        $manager = Mockery::mock(PluginManager::class);
        $this->app->instance(PluginManager::class, $manager);

        $this->artisan('plugin:install-builtins')
            ->expectsOutput('Skipped disabled built-in cura-web-ui.')
            ->assertExitCode(0);
    }

    public function test_cura_builtin_auto_enable_is_a_separate_rollout_switch(): void
    {
        $archivePath = sys_get_temp_dir().'/cura-web-ui-fixture-'.uniqid().'.w3dp';
        file_put_contents($archivePath, 'fixture');
        config()->set('plugins.builtins.inventory', __DIR__.'/missing-builtins.json');
        config()->set('plugins.builtins.entries', [[
            'id' => 'cura-web-ui',
            'version' => '1.0.0',
            'archive' => $archivePath,
            'required' => false,
            'defaultEnabled' => false,
            'feature' => 'builtin_cura_enabled',
        ]]);
        config()->set('plugins.rollout.builtin_cura_enabled', true);
        config()->set('plugins.rollout.builtin_cura_auto_enable', true);

        $plugin = new Plugin;
        $plugin->plugin_id = 'cura-web-ui';
        $plugin->enabled = false;

        $manager = Mockery::mock(PluginManager::class);
        $archiveService = Mockery::mock(PluginArchiveService::class);
        $archiveService->shouldReceive('inspect')
            ->once()
            ->with($archivePath, 'builtin')
            ->andReturn(new PluginPackage(
                manifest: [
                    'id' => 'cura-web-ui',
                    'name' => 'Cura Web UI',
                    'version' => '1.0.0',
                    'signature' => ['algorithm' => 'openssl-sha256'],
                ],
                rawManifest: null,
                archivePath: $archivePath,
                archiveSha256: hash('sha256', 'fixture'),
                sourceType: 'builtin',
                trustLevel: 'signed',
            ));
        $manager->shouldReceive('findModel')
            ->twice()
            ->with('cura-web-ui')
            ->andReturn(null, $plugin);
        $manager->shouldReceive('installFromArchive')
            ->once()
            ->with($archivePath, 'builtin', [
                'builtinId' => 'cura-web-ui',
                'bundledVersion' => '1.0.0',
                'archiveSha256' => hash('sha256', 'fixture'),
                'path' => $archivePath,
            ])
            ->andReturn(['id' => 'cura-web-ui']);
        $manager->shouldReceive('enable')
            ->once()
            ->with('cura-web-ui')
            ->andReturn(['id' => 'cura-web-ui', 'enabled' => true]);
        $this->app->instance(PluginManager::class, $manager);
        $this->app->instance(PluginArchiveService::class, $archiveService);

        try {
            $this->artisan('plugin:install-builtins')
                ->expectsOutput('Installed built-in cura-web-ui.')
                ->assertExitCode(0);
        } finally {
            @unlink($archivePath);
        }
    }

    public function test_verify_builtins_checks_the_inventory_archive_and_trust_level(): void
    {
        $root = sys_get_temp_dir().'/wprint3d-builtins-command-'.uniqid();
        mkdir($root.'/archives', 0777, true);
        $archivePath = $root.'/archives/cura-web-ui-1.0.0.w3dp';
        file_put_contents($archivePath, 'fixture');
        file_put_contents($root.'/index.json', json_encode([
            'schemaVersion' => 1,
            'plugins' => [[
                'id' => 'cura-web-ui',
                'version' => '1.0.0',
                'archive' => 'archives/cura-web-ui-1.0.0.w3dp',
                'sha256' => hash_file('sha256', $archivePath),
            ]],
        ]));
        config()->set('plugins.builtins.inventory', $root.'/index.json');

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $archiveService->shouldReceive('inspect')
            ->once()
            ->with($archivePath, 'builtin_verify')
            ->andReturn(new PluginPackage(
                manifest: ['id' => 'cura-web-ui', 'version' => '1.0.0'],
                rawManifest: null,
                archivePath: $archivePath,
                archiveSha256: hash_file('sha256', $archivePath),
                sourceType: 'builtin_verify',
                trustLevel: 'signed',
            ));
        $archiveService->shouldReceive('verifyIntegrity')
            ->once()
            ->with($archivePath, ['id' => 'cura-web-ui', 'version' => '1.0.0']);
        $this->app->instance(PluginArchiveService::class, $archiveService);

        try {
            $this->artisan('plugin:verify-builtins')
                ->expectsOutput('Verified built-in cura-web-ui 1.0.0.')
                ->assertExitCode(0);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
