<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginArchiveService;
use App\Plugins\PluginManifestValidator;
use Tests\TestCase;
use ZipArchive;

class PluginArchiveServiceTest extends TestCase
{
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
}
