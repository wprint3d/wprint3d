<?php

namespace Tests\Feature\Plugins;

use App\Plugins\PluginArchiveService;
use App\Plugins\PluginPackage;
use App\Plugins\PluginSignatureService;
use Mockery;
use Tests\TestCase;

class PluginPackageCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_plugin_verify_reports_embedded_signature_and_instance_trust(): void
    {
        $packagePath = '/tmp/acme-demo.w3dp';
        $publicKey = "-----BEGIN PUBLIC KEY-----\nTEST\n-----END PUBLIC KEY-----\n";
        $manifest = [
            'id' => 'acme.demo',
            'version' => '1.2.3',
            'signature' => [
                'algorithm' => 'openssl-sha256',
            ],
        ];

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $archiveService->shouldReceive('inspect')
            ->once()
            ->with($packagePath, 'verify')
            ->andReturn(new PluginPackage(
                manifest: $manifest,
                archivePath: $packagePath,
                archiveSha256: 'abc',
                sourceType: 'verify',
                trustLevel: 'signed',
                warnings: [],
            ));

        $signatureService = Mockery::mock(PluginSignatureService::class);
        $signatureService->shouldReceive('embeddedPublicKey')
            ->once()
            ->with($manifest)
            ->andReturn($publicKey);
        $signatureService->shouldReceive('verifyManifestWithPublicKeyContents')
            ->once()
            ->with($manifest, $publicKey)
            ->andReturn(true);

        $this->app->instance(PluginArchiveService::class, $archiveService);
        $this->app->instance(PluginSignatureService::class, $signatureService);

        $this->artisan("plugin:verify {$packagePath}")
            ->expectsOutput('Plugin: acme.demo')
            ->expectsOutput('Version: 1.2.3')
            ->expectsOutput('Embedded signature: valid')
            ->expectsOutput('Trusted by this WPrint 3D instance: yes')
            ->assertExitCode(0);
    }

    public function test_plugin_restore_restores_the_archive_to_the_requested_directory(): void
    {
        $packagePath = '/tmp/acme-demo.w3dp';
        $restorePath = '/var/www/plugins/acme-demo';
        $publicKey = "-----BEGIN PUBLIC KEY-----\nTEST\n-----END PUBLIC KEY-----\n";
        $manifest = [
            'id' => 'acme.demo',
            'version' => '1.2.3',
            'signature' => [
                'algorithm' => 'openssl-sha256',
            ],
        ];

        $archiveService = Mockery::mock(PluginArchiveService::class);
        $archiveService->shouldReceive('inspect')
            ->once()
            ->with($packagePath, 'restore')
            ->andReturn(new PluginPackage(
                manifest: $manifest,
                archivePath: $packagePath,
                archiveSha256: 'abc',
                sourceType: 'restore',
                trustLevel: 'signed',
                warnings: [],
            ));
        $archiveService->shouldReceive('restoreToDirectory')
            ->once()
            ->with($packagePath, $restorePath, false)
            ->andReturn($restorePath);

        $signatureService = Mockery::mock(PluginSignatureService::class);
        $signatureService->shouldReceive('embeddedPublicKey')
            ->once()
            ->with($manifest)
            ->andReturn($publicKey);
        $signatureService->shouldReceive('verifyManifestWithPublicKeyContents')
            ->once()
            ->with($manifest, $publicKey)
            ->andReturn(true);

        $this->app->instance(PluginArchiveService::class, $archiveService);
        $this->app->instance(PluginSignatureService::class, $signatureService);

        $this->artisan("plugin:restore {$packagePath} --output={$restorePath}")
            ->expectsOutput("Restored plugin source to {$restorePath}")
            ->assertExitCode(0);
    }
}
