<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginRegistryClient;
use App\Plugins\PluginSignatureService;
use App\Plugins\PluginTrustedKeySynchronizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PluginTrustedKeySynchronizerTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::preventStrayRequests(false);
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_downloads_and_stores_registry_signer_keys(): void
    {
        [$privateKeyPath, $publicKeyPem] = $this->generateKeyPair();
        $storageRoot = storage_path('framework/testing/trusted-plugin-keys-'.uniqid());
        $signatureService = new PluginSignatureService;

        $registryClient = Mockery::mock(PluginRegistryClient::class);
        $registryClient->shouldReceive('listSources')
            ->once()
            ->andReturn([
                [
                    'id' => 'official',
                    'name' => 'Official registry',
                    'indexUrl' => 'https://plugins.example.com/index.json',
                    'websiteUrl' => 'https://plugins.example.com',
                    'official' => true,
                ],
            ]);

        Http::fake([
            'https://plugins.example.com/signers/index.json' => Http::response([
                'keys' => [
                    [
                        'id' => 'octoprint.navbartemp-port',
                        'url' => 'https://plugins.example.com/signers/octoprint.navbartemp-port.pub.pem',
                        'publicKeySha256' => $signatureService->publicKeySha256($publicKeyPem),
                    ],
                ],
            ], 200),
            'https://plugins.example.com/signers/octoprint.navbartemp-port.pub.pem' => Http::response($publicKeyPem, 200),
        ]);

        try {
            $synchronizer = new PluginTrustedKeySynchronizer($registryClient, $signatureService, $storageRoot, []);

            $summary = $synchronizer->sync();
            $paths = $synchronizer->allTrustedKeyPaths();

            $this->assertSame(1, $summary['sourcesSynced']);
            $this->assertSame(1, $summary['keysSynced']);
            $this->assertCount(1, $paths);
            $this->assertFileExists($paths[0]);
            $this->assertStringContainsString('BEGIN PUBLIC KEY', File::get($paths[0]));
        } finally {
            File::delete($privateKeyPath);
            File::deleteDirectory($storageRoot);
        }
    }

    private function generateKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKeyPem));

        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);

        $privateKeyPath = storage_path('framework/testing/plugin-signature-private-'.uniqid().'.pem');
        File::put($privateKeyPath, $privateKeyPem);

        return [$privateKeyPath, $details['key']];
    }
}
