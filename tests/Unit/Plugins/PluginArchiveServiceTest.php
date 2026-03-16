<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginArchiveService;
use App\Plugins\PluginManifestValidator;
use App\Plugins\PluginSignatureService;
use App\Plugins\PluginTrustedKeySynchronizer;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class PluginArchiveServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_reads_and_extracts_a_plugin_archive(): void
    {
        $basePath = storage_path('framework/testing/plugins-'.uniqid());
        $archivePath = $basePath.'.w3dp';

        @mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/plugin.php', '<?php echo json_encode(["ok" => true]);');
        file_put_contents($basePath.'/plugin.json', json_encode([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip = new ZipArchive;
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($basePath.'/plugin.json', 'plugin.json');
        $zip->addFile($basePath.'/plugin.php', 'plugin.php');
        $zip->close();

        $service = new PluginArchiveService(new PluginManifestValidator);

        $package = $service->inspect($archivePath, 'local_upload');
        $extractedPath = $service->extract($archivePath, $package->manifest);

        $this->assertSame('acme.demo', $package->manifest['id']);
        $this->assertSame('local_upload', $package->sourceType);
        $this->assertNotEmpty($package->archiveSha256);
        $this->assertFileExists($extractedPath.'/plugin.json');
        $this->assertFileExists($extractedPath.'/plugin.php');
    }

    public function test_it_reads_an_unpacked_plugin_directory(): void
    {
        $basePath = storage_path('framework/testing/plugins-dir-'.uniqid());

        @mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/plugin.php', '<?php echo json_encode(["ok" => true]);');
        file_put_contents($basePath.'/plugin.json', json_encode([
            'id' => 'acme.live-demo',
            'name' => 'ACME Live Demo',
            'version' => '9.9.9',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $service = new PluginArchiveService(new PluginManifestValidator);

        $package = $service->inspectDirectory($basePath, 'development_mount');

        $this->assertSame('acme.live-demo', $package->manifest['id']);
        $this->assertSame('development_mount', $package->sourceType);
        $this->assertSame($basePath, $package->archivePath);
        $this->assertNotEmpty($package->archiveSha256);
    }

    public function test_it_trusts_a_signed_unpacked_runtime_directory_when_the_signer_key_is_synced(): void
    {
        [$privateKeyPath, $publicKeyPem] = $this->generateKeyPair();
        $basePath = storage_path('framework/testing/plugins-dir-signed-'.uniqid());
        $publicKeyPath = storage_path('framework/testing/plugin-signature-public-dir-'.uniqid().'.pem');

        @mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/plugin.php', '<?php echo json_encode(["ok" => true]);');

        $signatureService = new PluginSignatureService;
        $manifest = (new PluginManifestValidator)->validate([
            'id' => 'acme.runtime-signed-demo',
            'name' => 'ACME Runtime Signed Demo',
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
        ]);
        $signedManifest = $signatureService->signManifest($manifest, $privateKeyPath);

        file_put_contents($basePath.'/plugin.json', json_encode($signedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($publicKeyPath, $publicKeyPem);

        $trustedKeys = Mockery::mock(PluginTrustedKeySynchronizer::class);
        $trustedKeys->shouldReceive('allTrustedKeyPaths')
            ->once()
            ->andReturn([$publicKeyPath]);

        try {
            $service = new PluginArchiveService(new PluginManifestValidator, $signatureService, null, $trustedKeys);

            $package = $service->inspectDirectory($basePath, 'installed_runtime');

            $this->assertSame('signed', $package->trustLevel);
            $this->assertSame([], $package->warnings);
        } finally {
            File::delete([$privateKeyPath, $publicKeyPath]);
            File::deleteDirectory($basePath);
        }
    }

    public function test_it_preserves_signature_verification_for_legacy_signed_manifests_after_validation(): void
    {
        [$privateKeyPath, $publicKeyPem] = $this->generateKeyPair();
        $basePath = storage_path('framework/testing/plugins-dir-legacy-signed-'.uniqid());
        $publicKeyPath = storage_path('framework/testing/plugin-signature-public-legacy-'.uniqid().'.pem');

        @mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/plugin.php', '<?php echo json_encode(["ok" => true]);');

        $signatureService = new PluginSignatureService;
        $legacyManifest = [
            'id' => 'acme.legacy-signed-demo',
            'name' => 'ACME Legacy Signed Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ];
        $signedManifest = $signatureService->signManifest($legacyManifest, $privateKeyPath);

        file_put_contents($basePath.'/plugin.json', json_encode($signedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($publicKeyPath, $publicKeyPem);

        $trustedKeys = Mockery::mock(PluginTrustedKeySynchronizer::class);
        $trustedKeys->shouldReceive('allTrustedKeyPaths')
            ->once()
            ->andReturn([$publicKeyPath]);

        try {
            $service = new PluginArchiveService(new PluginManifestValidator, $signatureService, null, $trustedKeys);

            $package = $service->inspectDirectory($basePath, 'installed_runtime');

            $this->assertSame('signed', $package->trustLevel);
            $this->assertSame([], $package->warnings);
            $this->assertArrayHasKey('homepageUrl', $package->manifest);
            $this->assertNull($package->manifest['homepageUrl']);
        } finally {
            File::delete([$privateKeyPath, $publicKeyPath]);
            File::deleteDirectory($basePath);
        }
    }

    public function test_it_trusts_signed_archives_when_the_signer_key_was_synced_from_a_registry(): void
    {
        [$privateKeyPath, $publicKeyPem] = $this->generateKeyPair();
        $basePath = storage_path('framework/testing/plugins-signed-'.uniqid());
        $archivePath = $basePath.'.w3dp';
        $publicKeyPath = storage_path('framework/testing/plugin-signature-public-'.uniqid().'.pem');

        @mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/plugin.php', '<?php echo json_encode(["ok" => true]);');

        $signatureService = new PluginSignatureService;
        $manifest = (new PluginManifestValidator)->validate([
            'id' => 'acme.signed-demo',
            'name' => 'ACME Signed Demo',
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
        ]);
        $signedManifest = $signatureService->signManifest($manifest, $privateKeyPath);

        file_put_contents($basePath.'/plugin.json', json_encode($signedManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($publicKeyPath, $publicKeyPem);

        $zip = new ZipArchive;
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($basePath.'/plugin.json', 'plugin.json');
        $zip->addFile($basePath.'/plugin.php', 'plugin.php');
        $zip->close();

        $trustedKeys = Mockery::mock(PluginTrustedKeySynchronizer::class);
        $trustedKeys->shouldReceive('allTrustedKeyPaths')
            ->once()
            ->andReturn([$publicKeyPath]);

        try {
            $service = new PluginArchiveService(new PluginManifestValidator, $signatureService, null, $trustedKeys);

            $package = $service->inspect($archivePath, 'local_upload');

            $this->assertSame('signed', $package->trustLevel);
            $this->assertSame([], $package->warnings);
        } finally {
            File::delete([$privateKeyPath, $publicKeyPath, $archivePath]);
            File::deleteDirectory($basePath);
        }
    }

    public function test_it_can_restore_a_plugin_archive_to_a_source_directory(): void
    {
        $basePath = storage_path('framework/testing/plugins-restore-'.uniqid());
        $archivePath = $basePath.'.w3dp';
        $restorePath = storage_path('framework/testing/plugins-restored-'.uniqid());

        @mkdir($basePath, 0777, true);

        file_put_contents($basePath.'/plugin.php', '<?php echo json_encode(["ok" => true]);');
        file_put_contents($basePath.'/plugin.json', json_encode([
            'id' => 'acme.restore-demo',
            'name' => 'ACME Restore Demo',
            'version' => '1.2.3',
            'sdkVersion' => 1,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip = new ZipArchive;
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($basePath.'/plugin.json', 'plugin.json');
        $zip->addFile($basePath.'/plugin.php', 'plugin.php');
        $zip->close();

        try {
            $service = new PluginArchiveService(new PluginManifestValidator);

            $restoredPath = $service->restoreToDirectory($archivePath, $restorePath);

            $this->assertSame($restorePath, $restoredPath);
            $this->assertFileExists($restorePath.'/plugin.json');
            $this->assertFileExists($restorePath.'/plugin.php');
        } finally {
            File::delete($archivePath);
            File::deleteDirectory($basePath);
            File::deleteDirectory($restorePath);
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
