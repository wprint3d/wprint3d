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
}
