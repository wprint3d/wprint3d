<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginSignatureService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginSignatureServiceTest extends TestCase
{
    public function test_it_embeds_the_signer_public_key_and_fingerprint_in_the_manifest(): void
    {
        [$privateKeyPath, $publicKeyPem] = $this->generateKeyPair();

        try {
            $service = new PluginSignatureService;
            $signedManifest = $service->signManifest($this->baseManifest(), $privateKeyPath);

            $this->assertSame('openssl-sha256', $signedManifest['signature']['algorithm']);
            $this->assertSame($service->keyIdForPublicKey($publicKeyPem), $signedManifest['signature']['keyId']);
            $this->assertSame(trim($publicKeyPem), trim($signedManifest['signature']['publicKey']));
            $this->assertSame($service->publicKeySha256($publicKeyPem), $signedManifest['signature']['publicKeySha256']);
            $this->assertTrue($service->verifyManifestWithPublicKeyContents($signedManifest, $publicKeyPem));
        } finally {
            File::delete($privateKeyPath);
        }
    }

    public function test_it_verifies_signed_manifests_with_a_matching_public_key_file(): void
    {
        [$privateKeyPath, $publicKeyPem] = $this->generateKeyPair();
        $publicKeyPath = storage_path('framework/testing/plugin-signature-public-'.uniqid().'.pem');

        try {
            File::put($publicKeyPath, $publicKeyPem);

            $service = new PluginSignatureService;
            $signedManifest = $service->signManifest($this->baseManifest(), $privateKeyPath);

            $this->assertTrue($service->verifyManifest($signedManifest, [$publicKeyPath]));
        } finally {
            File::delete([$privateKeyPath, $publicKeyPath]);
        }
    }

    private function baseManifest(): array
    {
        return [
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ];
    }

    private function generateKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($resource);

        $privateKeyPem = '';
        $this->assertTrue(openssl_pkey_export($resource, $privateKeyPem));

        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);

        $privateKeyPath = storage_path('framework/testing/plugin-signature-private-'.uniqid().'.pem');
        File::put($privateKeyPath, $privateKeyPem);

        return [$privateKeyPath, $details['key']];
    }
}
