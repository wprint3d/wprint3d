<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\PluginSupportBundleService;
use App\Plugins\Runtime\PluginRuntimeHttpClient;
use Illuminate\Http\Client\Response;
use Mockery;
use Tests\TestCase;

class PluginSupportBundleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_builds_metadata_only_support_snapshot(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('listInstalled')->once()->andReturn([[
            'id' => 'cura-web-ui',
            'name' => 'Cura Web UI',
            'version' => '0.1.0',
            'trustLevel' => 'signed',
            'classification' => 'heavyweight',
            'enabled' => false,
            'manifest' => [
                'id' => 'cura-web-ui',
                'runtime' => [
                    'baseUrl' => 'http://internal-gateway:9311',
                    'authTokenCiphertext' => 'encrypted-secret',
                ],
                'signature' => [
                    'algorithm' => 'ed25519',
                    'keyId' => 'release-key',
                    'fingerprint' => 'fingerprint',
                    'value' => 'signature-value',
                ],
            ],
        ]]);
        $manager->shouldReceive('getLogs')->once()->with('cura-web-ui')->andReturn([[
            'message' => 'runtime started',
            'context' => [
                'token' => 'secret-token',
                'hostPath' => '/var/lib/docker/volumes/private/_data',
            ],
        ]]);
        $manager->shouldReceive('doctor')->once()->andReturn([[
            'id' => 'cura-web-ui',
            'runtimeDiagnostics' => [[
                'container' => ['name' => 'wprint3d-plugin-cura-web-ui-cura-gateway'],
                'volumes' => [['name' => 'wprint3d-plugin-cura-web-ui-data', 'present' => true]],
            ]],
        ]]);

        $snapshot = (new PluginSupportBundleService($manager))->snapshot();
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $snapshot['schemaVersion']);
        $this->assertSame('release-key', $snapshot['plugins'][0]['manifest']['signature']['keyId']);
        $this->assertArrayNotHasKey('value', $snapshot['plugins'][0]['manifest']['signature']);
        $this->assertStringNotContainsString('internal-gateway', $encoded);
        $this->assertStringNotContainsString('encrypted-secret', $encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('/var/lib/docker', $encoded);
        $this->assertStringContainsString('wprint3d-plugin-cura-web-ui-data', $encoded);
    }

    public function test_it_includes_sanitized_gateway_diagnostics_for_enabled_heavyweight_plugins(): void
    {
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('listInstalled')->once()->andReturn([[
            'id' => 'cura-web-ui',
            'version' => '0.1.0',
            'classification' => 'heavyweight',
            'enabled' => true,
            'manifest' => ['runtime' => ['baseUrl' => 'http://gateway:9311']],
        ]]);
        $manager->shouldReceive('getLogs')->once()->with('cura-web-ui')->andReturn([]);
        $manager->shouldReceive('doctor')->once()->andReturn([]);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('json')->once()->andReturn([
            'status' => 'ok',
            'buildMetadata' => ['version' => '0.1.0', 'sourceRevision' => 'abc'],
            'readiness' => ['engineReady' => true, 'storagePath' => '/data/private.sqlite'],
        ]);
        $client = Mockery::mock(PluginRuntimeHttpClient::class);
        $client->shouldReceive('get')->once()->with(
            Mockery::type('array'),
            '/api/v1/diagnostics',
        )->andReturn($response);

        $snapshot = (new PluginSupportBundleService($manager, $client))->snapshot();
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $snapshot['plugins'][0]['gatewayDiagnostics']['status']);
        $this->assertStringNotContainsString('/data/private.sqlite', $encoded);
        $this->assertStringContainsString('sourceRevision', $encoded);
    }
}
