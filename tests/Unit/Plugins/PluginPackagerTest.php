<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginPackager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PluginPackagerTest extends TestCase
{
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

    public function test_it_rejects_non_writable_output_directories_with_a_clear_error(): void
    {
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
