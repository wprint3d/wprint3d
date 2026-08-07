<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginArchiveService;
use App\Plugins\PluginPackager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PluginPackagerTest extends TestCase
{
    public function test_it_rejects_zip_path_traversal_during_restore(): void
    {
        $archivePath = sys_get_temp_dir().'/wprint3d-unsafe-'.uniqid().'.w3dp';
        $targetPath = sys_get_temp_dir().'/wprint3d-restore-'.uniqid();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath, ZipArchive::CREATE) === true);
        $zip->addFromString('../outside.txt', 'must not escape');
        $zip->addFromString('plugin.json', json_encode([
            'id' => 'acme.unsafe',
            'name' => 'Unsafe',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => ['type' => 'php', 'entry' => 'plugin.php'],
            'permissions' => ['printer.read'],
        ]));
        $zip->close();

        try {
            $this->expectException(\App\Plugins\Exceptions\PluginRuntimeException::class);
            $this->expectExceptionMessage('path traversal');
            app(PluginArchiveService::class)->restoreToDirectory($archivePath, $targetPath);
        } finally {
            @unlink($archivePath);
            \Illuminate\Support\Facades\File::deleteDirectory($targetPath);
        }
    }

    public function test_it_excludes_existing_build_artifacts_from_packaged_plugins(): void
    {
        $pluginPath = sys_get_temp_dir().'/wprint3d-packager-'.uniqid();
        $buildsPath = $pluginPath.'/builds';
        $outputPath = $buildsPath.'/acme-demo.w3dp';

        File::ensureDirectoryExists($buildsPath);
        File::put($pluginPath.'/plugin.php', "<?php\n");
        File::put($pluginPath.'/builds/old-package.w3dp', 'legacy-build');
        File::put($pluginPath.'/plugin.json', json_encode([
            'id' => 'acme.demo',
            'name' => 'ACME Demo',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            app(PluginPackager::class)->build($pluginPath, $outputPath);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($outputPath) === true);

            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index += 1) {
                $entries[] = $zip->getNameIndex($index);
            }

            $zip->close();

            $this->assertContains('plugin.json', $entries);
            $this->assertContains('plugin.php', $entries);
            $this->assertNotContains('builds/old-package.w3dp', $entries);
            $this->assertSame([], glob($outputPath.'.part-*') ?: []);
        } finally {
            File::deleteDirectory($pluginPath);
        }
    }

    public function test_it_embeds_the_signer_public_key_inside_signed_packages(): void
    {
        $pluginPath = sys_get_temp_dir().'/wprint3d-packager-signed-'.uniqid();
        $buildsPath = $pluginPath.'/builds';
        $outputPath = $buildsPath.'/acme-signed.w3dp';
        $privateKeyPath = $pluginPath.'/signing-key.pem';

        File::ensureDirectoryExists($buildsPath);
        File::put($pluginPath.'/plugin.php', "<?php\n");
        File::put($pluginPath.'/plugin.json', json_encode([
            'id' => 'acme.signed-demo',
            'name' => 'ACME Signed Demo',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKeyPem));
        File::put($privateKeyPath, $privateKeyPem);

        try {
            app(PluginPackager::class)->build($pluginPath, $outputPath, $privateKeyPath);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($outputPath) === true);

            $manifest = json_decode((string) $zip->getFromName('plugin.json'), true);
            $zip->close();

            $this->assertIsArray($manifest);
            $this->assertSame('openssl-sha256', $manifest['signature']['algorithm'] ?? null);
            $this->assertNotEmpty($manifest['signature']['publicKey'] ?? null);
            $this->assertNotEmpty($manifest['signature']['publicKeySha256'] ?? null);
        } finally {
            File::deleteDirectory($pluginPath);
        }
    }

    public function test_it_rejects_tampered_asset_contents_after_extraction(): void
    {
        $pluginPath = sys_get_temp_dir().'/wprint3d-packager-integrity-'.uniqid();
        $assetsPath = $pluginPath.'/assets';
        $archivePath = $pluginPath.'/builds/integrity.w3dp';
        File::ensureDirectoryExists($assetsPath);
        File::put($assetsPath.'/widget.js', 'console.log("trusted");');
        File::put($pluginPath.'/plugin.json', json_encode([
            'id' => 'acme.integrity-demo',
            'name' => 'ACME Integrity Demo',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => ['type' => 'php', 'entry' => 'plugin.php'],
            'permissions' => ['printer.read'],
            'assets' => [[
                'id' => 'assets',
                'path' => 'assets',
                'integrity' => 'sha256-'.base64_encode(hash('sha256', "widget.js\0console.log(\"trusted\");", true)),
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($pluginPath.'/plugin.php', "<?php\n");

        try {
            app(PluginPackager::class)->build($pluginPath, $archivePath);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archivePath) === true);
            $this->assertTrue($zip->deleteName('assets/widget.js'));
            $this->assertTrue($zip->addFromString('assets/widget.js', 'console.log("tampered");'));
            $this->assertTrue($zip->close());

            $this->expectException(\App\Plugins\Exceptions\PluginRuntimeException::class);
            $this->expectExceptionMessage('asset integrity mismatch');
            app(PluginArchiveService::class)->restoreToDirectory($archivePath, $pluginPath.'/restore');
        } finally {
            File::deleteDirectory($pluginPath);
        }
    }

    public function test_it_rejects_non_writable_output_directories_with_a_clear_error(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can write chmod 0555 directories; run this assertion as an unprivileged user.');
        }

        $pluginPath = sys_get_temp_dir().'/wprint3d-packager-permissions-'.uniqid();
        $lockedPath = $pluginPath.'/locked';
        $outputPath = $lockedPath.'/acme-demo.w3dp';

        File::ensureDirectoryExists($lockedPath);
        File::put($pluginPath.'/plugin.php', "<?php\n");
        File::put($pluginPath.'/plugin.json', json_encode([
            'id' => 'acme.permissions-demo',
            'name' => 'ACME Permissions Demo',
            'version' => '0.1.0',
            'sdkVersion' => 1,
            'sdkRevision' => 4,
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($lockedPath, 0555);

        try {
            $this->expectException(\App\Plugins\Exceptions\PluginRuntimeException::class);
            $this->expectExceptionMessage('Plugin package output directory is not writable');

            app(PluginPackager::class)->build($pluginPath, $outputPath);
        } finally {
            chmod($lockedPath, 0755);
            File::deleteDirectory($pluginPath);
        }
    }
}
