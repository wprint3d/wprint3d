<?php

namespace Tests\Feature\Plugins;

use App\Exceptions\PrintJobException;
use App\Models\Plugin;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\Contracts\PluginManager;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\PluginDependencyService;
use App\Services\PrintJobService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_browser_plugin_payload_redacts_runtime_state_and_internal_paths(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('listInstalled')
            ->once()
            ->andReturn([
                [
                    'id' => 'cura-web-ui',
                    'manifest' => [
                        'runtime' => [
                            'baseUrl' => 'http://cura-web-ui-gateway:9311',
                            'managedImageId' => 'cura-gateway',
                        ],
                    ],
                    'dependencies' => [
                        'runtime' => [
                            'baseUrl' => 'http://cura-web-ui-gateway:9311',
                            'authTokenCiphertext' => 'encrypted-secret',
                        ],
                        'images' => [[
                            'service' => [
                                'containerName' => 'wprint3d-plugin-cura-web-ui-cura-gateway',
                            ],
                        ]],
                    ],
                    'runtimePath' => '/var/www/storage/app/plugins/runtime/cura-web-ui',
                    'storagePath' => '/var/www/storage/app/plugins/runtime/cura-web-ui',
                ],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $response = $this->withoutMiddleware()->getJson('/api/plugins');

        $response
            ->assertOk()
            ->assertJsonPath('0.id', 'cura-web-ui')
            ->assertJsonPath('0.manifest.runtime.managedImageId', 'cura-gateway')
            ->assertJsonMissingPath('0.manifest.runtime.baseUrl')
            ->assertJsonMissingPath('0.dependencies.runtime.baseUrl')
            ->assertJsonMissingPath('0.dependencies.runtime.authTokenCiphertext')
            ->assertJsonMissingPath('0.dependencies.images.0.service.containerName')
            ->assertJsonMissingPath('0.runtimePath')
            ->assertJsonMissingPath('0.storagePath');
    }

    public function test_administrator_can_delete_only_an_explicit_managed_runtime_volume(): void
    {
        $plugin = new Plugin;
        $plugin->plugin_id = 'cura-web-ui';
        $plugin->enabled = false;
        $plugin->manifest = [
            'id' => 'cura-web-ui',
            'images' => [[
                'id' => 'cura-gateway',
                'service' => ['storage' => [['name' => 'data', 'target' => '/data']]],
            ]],
        ];

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('findModel')->once()->with('cura-web-ui')->andReturn($plugin);
        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('persistentStorageNames')
            ->once()
            ->with(['id' => 'cura-web-ui', 'manifest' => $plugin->manifest])
            ->andReturn(['wprint3d-plugin-cura-web-ui-data']);
        $dependencyService->shouldReceive('deletePersistentStorage')
            ->once()
            ->with(['id' => 'cura-web-ui', 'manifest' => $plugin->manifest])
            ->andReturn(['wprint3d-plugin-cura-web-ui-data']);

        $this->app->instance(PluginManager::class, $manager);
        $this->app->instance(PluginDependencyService::class, $dependencyService);

        $this->withoutMiddleware()
            ->deleteJson('/api/plugins/cura-web-ui/runtime-storage', [
                'expectedVolume' => 'wprint3d-plugin-cura-web-ui-data',
            ])
            ->assertOk()
            ->assertJsonPath('deletedVolumes.0', 'wprint3d-plugin-cura-web-ui-data');
    }

    public function test_runtime_storage_deletion_rejects_enabled_or_unmanaged_targets(): void
    {
        $plugin = new Plugin;
        $plugin->plugin_id = 'cura-web-ui';
        $plugin->enabled = true;
        $plugin->manifest = ['id' => 'cura-web-ui', 'images' => []];

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('findModel')->twice()->with('cura-web-ui')->andReturn($plugin);
        $this->app->instance(PluginManager::class, $manager);

        $this->withoutMiddleware()
            ->deleteJson('/api/plugins/cura-web-ui/runtime-storage', [
                'expectedVolume' => 'wprint3d-plugin-cura-web-ui-data',
            ])
            ->assertStatus(409);

        $plugin->enabled = false;
        $dependencyService = Mockery::mock(PluginDependencyService::class);
        $dependencyService->shouldReceive('persistentStorageNames')
            ->once()
            ->andReturn(['wprint3d-plugin-cura-web-ui-data']);
        $dependencyService->shouldNotReceive('deletePersistentStorage');
        $this->app->instance(PluginDependencyService::class, $dependencyService);

        $this->withoutMiddleware()
            ->deleteJson('/api/plugins/cura-web-ui/runtime-storage', [
                'expectedVolume' => 'other-volume',
            ])
            ->assertStatus(422);
    }

    public function test_it_enables_a_plugin(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('enable')
            ->once()
            ->with('acme.demo', false, null)
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

    public function test_it_forwards_an_explicit_requirement_override_when_enabling_a_plugin(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('enable')
            ->once()
            ->with('acme.demo', true, null)
            ->andReturn([
                'id' => 'acme.demo',
                'enabled' => true,
            ]);
        $this->app->instance(PluginManager::class, $manager);

        $this->withoutMiddleware()
            ->postJson('/api/plugins/acme.demo/enable', ['overrideRequirements' => true])
            ->assertOk()
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

    public function test_runtime_proxy_streams_the_response_and_replaces_browser_identity_headers(): void
    {
        $pluginId = 'acme.proxy-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                ],
            ],
            'dependency_state' => [
                'runtime' => [
                    'baseUrl' => 'http://cura-gateway:9311',
                    'authTokenCiphertext' => Crypt::encryptString('runtime-secret'),
                ],
            ],
        ]);
        Http::fake(['http://cura-gateway:9311/*' => Http::response('{"status":"ok"}', 200, ['Content-Type' => 'application/json'])]);

        try {
            $response = $this->withoutMiddleware()->withHeaders([
                'X-WPrint-User-Id' => 'spoofed-browser-user',
                'Accept' => 'application/json',
            ])->get('/api/plugins/'.$pluginId.'/runtime/api/v1/capabilities?package_type=plugin&limit=100&search=bed%20leveling');

            $response->assertOk();
            $this->assertSame('{"status":"ok"}', $response->streamedContent());
            $recorded = Http::recorded();
            $this->assertCount(1, $recorded);
            /** @var \Illuminate\Http\Client\Request $outbound */
            $outbound = $recorded->first()[0];
            parse_str((string) parse_url($outbound->url(), PHP_URL_QUERY), $query);
            $this->assertSame('plugin', $query['package_type'] ?? null);
            $this->assertSame('100', $query['limit'] ?? null);
            $this->assertSame('bed leveling', $query['search'] ?? null);
            $this->assertTrue($outbound->hasHeader('Authorization', 'Bearer runtime-secret'));
            $this->assertTrue($outbound->hasHeader('x-wprint-plugin-id'));
            $this->assertTrue($outbound->hasHeader('x-wprint-user-id', 'wprint-user'));
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_forwards_json_request_bodies_without_a_streaming_upload(): void
    {
        $pluginId = 'acme.proxy-json-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'JSON proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['POST']],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);
        Http::fake(['http://cura-gateway:9311/*' => Http::response('{"id":"job-1"}', 201, ['Content-Type' => 'application/json'])]);

        try {
            $response = $this->withoutMiddleware()->postJson('/api/plugins/'.$pluginId.'/runtime/api/v1/jobs', [
                'apiVersion' => '1.1',
                'models' => [],
            ]);

            $response->assertCreated();
            $this->assertSame('{"id":"job-1"}', $response->streamedContent());
            Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->body() === '{"apiVersion":"1.1","models":[]}');
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_rebuilds_parsed_multipart_uploads_as_bounded_streams(): void
    {
        $pluginId = 'acme.proxy-multipart-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Multipart proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1'], 'methods' => ['POST']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['POST'], 'maxUploadMb' => 2],
                ],
            ],
            'dependency_state' => [
                'runtime' => [
                    'baseUrl' => 'http://cura-gateway:9311',
                    'authTokenCiphertext' => Crypt::encryptString('runtime-secret'),
                ],
            ],
        ]);
        Http::fake(['http://cura-gateway:9311/*' => Http::response(['uploadId' => 'upload-1'], 201)]);
        $stl = "solid proxy-audit\nvertex 1 2 3\nendsolid proxy-audit\n";
        $temporaryFile = tempnam(sys_get_temp_dir(), 'runtime-proxy-upload-');
        if ($temporaryFile === false || file_put_contents($temporaryFile, $stl) === false) {
            $this->fail('Unable to create the multipart proxy fixture.');
        }

        try {
            $response = $this->withoutMiddleware()
                ->withHeader('Content-Type', 'multipart/form-data; boundary=browser-fixture')
                ->post('/api/plugins/'.$pluginId.'/runtime/api/v1/uploads', [
                    'file' => new UploadedFile($temporaryFile, 'audit cube.stl', 'model/stl', UPLOAD_ERR_OK, true),
                    'metadata' => ['label' => 'Audit cube'],
                    'tags' => ['small', 'watertight'],
                ]);

            $response->assertCreated();
            $this->assertSame('upload-1', json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR)['uploadId']);
            $recorded = Http::recorded();
            $this->assertCount(1, $recorded);
            /** @var \Illuminate\Http\Client\Request $outbound */
            $outbound = $recorded->first()[0];
            $headers = array_change_key_case($outbound->headers(), CASE_LOWER);
            $contentType = $headers['content-type'][0] ?? '';
            preg_match('/boundary=([^;]+)/', $contentType, $matches);
            $boundary = $matches[1] ?? '';
            $body = $outbound->body();

            $this->assertTrue($outbound->hasHeader('Authorization', 'Bearer runtime-secret'));
            $this->assertNotSame('', $boundary);
            $this->assertStringContainsString('--'.$boundary, $body);
            $this->assertStringContainsString('name="file"; filename="audit cube.stl"', $body);
            $this->assertStringContainsString('Content-Type: model/stl', $body);
            $this->assertStringContainsString($stl, $body);
            $this->assertStringContainsString('name="metadata[label]"', $body);
            $this->assertStringContainsString('Audit cube', $body);
            $this->assertStringContainsString('name="tags[0]"', $body);
            $this->assertStringContainsString('small', $body);
            $this->assertSame(strlen($body), (int) ($headers['content-length'][0] ?? 0));
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    public function test_runtime_proxy_enforces_declared_limit_on_rebuilt_multipart_body(): void
    {
        $pluginId = 'acme.proxy-multipart-limit-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Multipart limit proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1'], 'methods' => ['POST']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['POST'], 'maxUploadMb' => 1],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);
        Http::fake();

        try {
            $this->withoutMiddleware()
                ->withHeader('Content-Type', 'multipart/form-data; boundary=browser-fixture')
                ->post('/api/plugins/'.$pluginId.'/runtime/api/v1/uploads', [
                    'files' => [
                        UploadedFile::fake()->createWithContent('one.stl', str_repeat('a', 600 * 1024)),
                        UploadedFile::fake()->createWithContent('two.stl', str_repeat('b', 600 * 1024)),
                    ],
                ])->assertStatus(413);
            Http::assertNothingSent();
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_raw_multipart_fallback_terminates_at_eof(): void
    {
        $pluginId = 'acme.proxy-raw-multipart-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Raw multipart proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1'], 'methods' => ['POST']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['POST'], 'maxUploadMb' => 1],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);
        Http::fake(['http://cura-gateway:9311/*' => Http::response(['detail' => 'invalid fixture'], 422)]);
        $body = "--fixture\r\nContent-Disposition: form-data; name=\"label\"\r\n\r\naudit\r\n--fixture--\r\n";

        try {
            $response = $this->withoutMiddleware()->call(
                'POST',
                '/api/plugins/'.$pluginId.'/runtime/api/v1/uploads',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'multipart/form-data; boundary=fixture',
                    'CONTENT_LENGTH' => (string) strlen($body),
                ],
                $body,
            );

            $response->assertStatus(422);
            $recorded = Http::recorded();
            $this->assertCount(1, $recorded);
            /** @var \Illuminate\Http\Client\Request $outbound */
            $outbound = $recorded->first()[0];
            $this->assertSame($body, $outbound->body());
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_maps_sidecar_connection_failures_to_bad_gateway(): void
    {
        $pluginId = 'acme.proxy-unavailable-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Unavailable proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://unavailable-gateway:9311'],
            ],
        ]);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Connection refused'));

        try {
            $this->withoutMiddleware()
                ->get('/api/plugins/'.$pluginId.'/runtime/api/v1/health')
                ->assertStatus(502)
                ->assertSee('Plugin runtime is unavailable.');
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_rejects_requests_for_disabled_plugins(): void
    {
        $pluginId = 'acme.proxy-disabled-plugin-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Disabled runtime fixture',
            'enabled' => false,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                ],
            ],
        ]);
        Http::fake();

        try {
            $this->withoutMiddleware()->withoutExceptionHandling();
            $this->expectException(PluginRuntimeException::class);
            $this->expectExceptionMessage("Plugin {$pluginId} is not enabled.");
            $this->get('/api/plugins/'.$pluginId.'/runtime/api/v1/health');
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
            Http::assertNothingSent();
        }
    }

    public function test_runtime_artifact_import_enforces_declared_path_and_content_type(): void
    {
        $pluginId = 'acme.proxy-artifact-policy-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Artifact policy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                    'artifactImports' => [[
                        'id' => 'gcode',
                        'pathPattern' => '^/api/v1/jobs/[A-Za-z0-9_-]+/gcode$',
                        'contentTypes' => ['text/x-gcode'],
                        'maxSizeMb' => 1,
                    ]],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);

        try {
            $this->withoutMiddleware()->withoutExceptionHandling();
            try {
                $this->postJson('/api/plugins/'.$pluginId.'/runtime-artifacts/gcode', [
                    'jobId' => 'job-1',
                    'artifactPath' => '/api/v1/packages/private',
                ]);
                $this->fail('An undeclared artifact path was accepted.');
            } catch (PluginRuntimeException $exception) {
                $this->assertSame('Runtime artifact path does not match the declared import pattern.', $exception->getMessage());
            }

            Http::fake(['http://cura-gateway:9311/*' => Http::response('archive bytes', 200, ['Content-Type' => 'application/zip'])]);
            try {
                $this->postJson('/api/plugins/'.$pluginId.'/runtime-artifacts/gcode', [
                    'jobId' => 'job-1',
                    'artifactPath' => '/api/v1/jobs/job-1/gcode',
                ]);
                $this->fail('An undeclared artifact content type was accepted.');
            } catch (PluginRuntimeException $exception) {
                $this->assertSame('Runtime artifact content type is not allowed by the plugin manifest.', $exception->getMessage());
            }
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_artifact_can_be_imported_and_started_on_the_active_printer(): void
    {
        Storage::fake('gcode');
        $pluginId = 'acme.proxy-artifact-print-'.uniqid();
        $plugin = Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Artifact print fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                    'artifactImports' => [[
                        'id' => 'gcode',
                        'pathPattern' => '^/api/v1/jobs/[A-Za-z0-9_-]+/gcode$',
                        'contentTypes' => ['text/x-gcode'],
                        'maxSizeMb' => 1,
                    ]],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);
        $user = new User;
        $user->name = 'artifact-user';
        $user->email = uniqid().'@example.test';
        $user->password = Hash::make('password');
        $user->firstLogin = false;
        $user->save();
        $printer = new Printer;
        $printer->connected = true;
        $printer->machine = ['uuid' => 'artifact-printer-'.uniqid(), 'machineType' => 'Test printer'];
        $printer->save();

        try {
            $this->actingAs($user);
            $user->setActivePrinterId((string) $printer->_id);
            Http::fake([
                'http://cura-gateway:9311/*' => Http::response("G90\nG1 X10 Y10\n", 200, ['Content-Type' => 'text/x-gcode']),
            ]);
            $jobs = Mockery::mock(PrintJobService::class);
            $jobs->shouldReceive('start')
                ->once()
                ->withArgs(fn (User $owner, Printer $target, string $path) => (
                    (string) $owner->_id === (string) $user->_id
                    && (string) $target->_id === (string) $printer->_id
                    && str_starts_with($path, 'cura/')
                    && str_ends_with($path, '-benchy.gcode')
                ));
            $this->app->instance(PrintJobService::class, $jobs);

            $response = $this->withoutMiddleware()->postJson('/api/plugins/'.$pluginId.'/runtime-artifacts/gcode', [
                'jobId' => 'job-1',
                'filename' => 'benchy.gcode',
                'artifactPath' => '/api/v1/jobs/job-1/gcode',
                'startPrint' => true,
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('name', 'benchy.gcode')
                ->assertJsonPath('printing', true)
                ->assertJsonPath('printerId', (string) $printer->_id);
            Storage::disk('gcode')->assertExists($response->json('path'));
        } finally {
            $plugin->delete();
            $printer->delete();
            $user->delete();
        }
    }

    public function test_runtime_artifact_remains_in_files_when_starting_the_print_fails(): void
    {
        Storage::fake('gcode');
        $pluginId = 'acme.proxy-artifact-busy-'.uniqid();
        $plugin = Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Artifact busy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 6,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v2']],
                    'httpProxy' => ['pathPrefix' => '/api/v2', 'methods' => ['GET']],
                    'artifactImports' => [[
                        'id' => 'gcode-v2',
                        'pathPattern' => '^/api/v2/slice-jobs/[A-Za-z0-9_-]+/artifacts/gcode$',
                        'contentTypes' => ['text/x-gcode'],
                        'maxSizeMb' => 1,
                    ]],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);
        $user = new User;
        $user->name = 'artifact-busy-user';
        $user->email = uniqid().'@example.test';
        $user->password = Hash::make('password');
        $user->firstLogin = false;
        $user->save();
        $printer = new Printer;
        $printer->connected = true;
        $printer->machine = ['uuid' => 'artifact-busy-printer-'.uniqid(), 'machineType' => 'Test printer'];
        $printer->save();

        try {
            $this->actingAs($user);
            $user->setActivePrinterId((string) $printer->_id);
            Http::fake([
                'http://cura-gateway:9311/*' => Http::response("G90\nG1 X10 Y10\n", 200, ['Content-Type' => 'text/x-gcode']),
            ]);
            $jobs = Mockery::mock(PrintJobService::class);
            $jobs->shouldReceive('start')
                ->once()
                ->andThrow(new PrintJobException('busy', 'The printer is already printing.'));
            $this->app->instance(PrintJobService::class, $jobs);

            $response = $this->withoutMiddleware()->postJson('/api/plugins/'.$pluginId.'/runtime-artifacts/gcode-v2', [
                'jobId' => 'job-busy',
                'filename' => 'benchy.gcode',
                'artifactPath' => '/api/v2/slice-jobs/job-busy/artifacts/gcode',
                'startPrint' => true,
            ]);

            $response
                ->assertConflict()
                ->assertJsonPath('message', 'The printer is already printing.')
                ->assertJsonPath('error.code', 'busy')
                ->assertJsonPath('artifact.name', 'benchy.gcode')
                ->assertJsonPath('artifact.printing', false);
            $artifactPath = $response->json('artifact.path');
            $this->assertIsString($artifactPath);
            Storage::disk('gcode')->assertExists($artifactPath);
            $this->assertSame("G90\nG1 X10 Y10\n", Storage::disk('gcode')->get($artifactPath));
        } finally {
            $plugin->delete();
            $printer->delete();
            $user->delete();
        }
    }

    public function test_runtime_proxy_can_be_disabled_by_the_host_without_exposing_the_runtime(): void
    {
        config()->set('plugins.rollout.runtime_proxy_enabled', false);
        $pluginId = 'acme.proxy-disabled-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Proxy disabled fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                ],
            ],
        ]);

        try {
            $this->withoutMiddleware()
                ->getJson('/api/plugins/'.$pluginId.'/runtime/api/v1/health')
                ->assertStatus(503)
                ->assertJsonPath('message', 'The plugin runtime proxy is temporarily disabled by the host.');
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_rejects_websocket_upgrades_for_polling_clients(): void
    {
        $pluginId = 'acme.proxy-websocket-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Proxy websocket fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => ['pathPrefix' => '/api/v1', 'methods' => ['GET']],
                ],
            ],
        ]);

        try {
            $this->withoutMiddleware()
                ->withHeaders(['Upgrade' => 'websocket', 'Connection' => 'Upgrade'])
                ->get('/api/plugins/'.$pluginId.'/runtime/api/v1/events')
                ->assertStatus(426)
                ->assertSee('use polling');
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_rejects_legacy_or_undeclared_proxy_contracts(): void
    {
        $pluginId = 'acme.proxy-legacy-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Legacy proxy fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 4,
                'runtime' => ['type' => 'bridge', 'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']]],
            ],
        ]);

        try {
            $this->withoutMiddleware()->withoutExceptionHandling();
            $this->expectException(PluginRuntimeException::class);
            $this->expectExceptionMessage('Runtime proxy requires an SDK revision 5 httpProxy declaration.');
            $this->getJson('/api/plugins/'.$pluginId.'/runtime/api/v1/health');
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_runtime_proxy_enforces_declared_multipart_upload_limit(): void
    {
        $pluginId = 'acme.proxy-upload-limit-'.uniqid();
        Plugin::query()->create([
            'plugin_id' => $pluginId,
            'name' => 'Proxy upload limit fixture',
            'enabled' => true,
            'manifest' => [
                'sdkVersion' => 1,
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'proxy' => ['enabled' => true, 'allowedPaths' => ['/api/v1']],
                    'httpProxy' => [
                        'pathPrefix' => '/api/v1',
                        'methods' => ['POST'],
                        'maxUploadMb' => 1,
                    ],
                ],
            ],
            'dependency_state' => [
                'runtime' => ['baseUrl' => 'http://cura-gateway:9311'],
            ],
        ]);

        try {
            $this->withoutMiddleware()
                ->call('POST', '/api/plugins/'.$pluginId.'/runtime/api/v1/jobs', [], [], [], [
                    'CONTENT_TYPE' => 'multipart/form-data; boundary=fixture',
                    'CONTENT_LENGTH' => (string) (2 * 1048576),
                ], 'small-body')
                ->assertStatus(413);
        } finally {
            Plugin::where('plugin_id', $pluginId)->delete();
        }
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

    public function test_it_returns_permission_filtered_embedded_host_context(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('sdkMetadata')->zeroOrMoreTimes();
        $manager->shouldReceive('hostContext')
            ->once()
            ->with('acme.demo', null, 'es_AR')
            ->andReturn([
                'apiVersion' => '2.0',
                'pluginId' => 'acme.demo',
                'hostMode' => 'embedded',
                'runtimeBase' => '/backend/api/plugins/acme.demo/runtime',
                'artifactImportBase' => null,
                'locale' => 'es',
                'features' => ['printerRead' => false, 'storageRead' => true, 'storageWrite' => false],
            ]);

        $this->app->instance(PluginManager::class, $manager);

        $this->withoutMiddleware()
            ->withHeaders(['Accept-Language' => 'es-AR'])
            ->getJson('/api/plugins/acme.demo/host-context')
            ->assertOk()
            ->assertJsonPath('hostMode', 'embedded')
            ->assertJsonPath('runtimeBase', '/backend/api/plugins/acme.demo/runtime')
            ->assertJsonPath('features.storageWrite', false);
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
                ->assertJsonPath('configuredMountPath', $mountPath)
                ->assertJsonPath('plugins.0.id', 'acme.demo');
            $body = $response->json();
            $this->assertSame($body['mountPaths'][0], $body['mountPath']);
            $this->assertContains($mountPath, $body['mountPaths']);
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
                ->assertJsonPath('configuredMountPath', '/var/www/plugins-dev');
            $body = $response->json();
            $this->assertSame($body['mountPaths'][0], $body['mountPath']);
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
                ->assertJsonPath('available', true);
            $body = $response->json();
            $this->assertSame($body['mountPaths'][0], $body['mountPath']);
            $this->assertContains($mountPath, $body['mountPaths']);
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
            ->assertJsonPath('configuredMountPath', '/path/that/does/not/exist')
            ->assertJsonPath('configuredMountPaths.0', '/path/that/does/not/exist');
        $body = $response->json();
        $this->assertSame($body['mountPaths'][0], $body['mountPath']);
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
