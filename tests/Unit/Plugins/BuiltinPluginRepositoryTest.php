<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Builtins\BuiltinPluginRepository;
use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BuiltinPluginRepositoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/wprint3d-builtins-'.uniqid();
        File::ensureDirectoryExists($this->root.'/archives');
        config()->set('plugins.builtins.inventory', $this->root.'/index.json');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_loads_inventory_and_verifies_archive_checksum(): void
    {
        $archive = $this->root.'/archives/cura-web-ui-1.0.0.w3dp';
        File::put($archive, 'signed fixture');
        File::put($this->root.'/index.json', json_encode([
            'schemaVersion' => 1,
            'plugins' => [[
                'id' => 'cura-web-ui',
                'version' => '1.0.0',
                'archive' => 'archives/cura-web-ui-1.0.0.w3dp',
                'sha256' => hash_file('sha256', $archive),
                'defaultEnabled' => false,
                'required' => false,
            ]],
        ]));

        $repository = new BuiltinPluginRepository;
        $descriptor = $repository->descriptors()[0];

        $this->assertSame('cura-web-ui', $descriptor->id);
        $this->assertSame($archive, $repository->archivePath($descriptor));
    }

    public function test_it_rejects_archive_checksum_mismatch(): void
    {
        $archive = $this->root.'/archives/cura-web-ui-1.0.0.w3dp';
        File::put($archive, 'tampered fixture');
        File::put($this->root.'/index.json', json_encode([
            'schemaVersion' => 1,
            'plugins' => [[
                'id' => 'cura-web-ui',
                'version' => '1.0.0',
                'archive' => 'archives/cura-web-ui-1.0.0.w3dp',
                'sha256' => str_repeat('a', 64),
            ]],
        ]));

        $repository = new BuiltinPluginRepository;

        $this->expectException(PluginRuntimeException::class);
        $repository->archivePath($repository->descriptors()[0]);
    }

    public function test_it_rejects_duplicate_and_traversing_inventory_entries(): void
    {
        File::put($this->root.'/index.json', json_encode([
            'schemaVersion' => 1,
            'plugins' => [
                [
                    'id' => 'cura-web-ui',
                    'version' => '1.0.0',
                    'archive' => '../escape.w3dp',
                    'sha256' => str_repeat('a', 64),
                ],
                [
                    'id' => 'cura-web-ui',
                    'version' => '1.0.1',
                    'archive' => 'archives/other.w3dp',
                    'sha256' => str_repeat('b', 64),
                ],
            ],
        ]));

        $repository = new BuiltinPluginRepository;

        $this->expectException(\InvalidArgumentException::class);
        $repository->descriptors();
    }

    public function test_it_rejects_duplicate_ids_in_an_inventory(): void
    {
        $entry = [
            'id' => 'cura-web-ui',
            'version' => '1.0.0',
            'archive' => 'archives/cura-web-ui-1.0.0.w3dp',
            'sha256' => str_repeat('a', 64),
        ];
        File::put($this->root.'/index.json', json_encode([
            'schemaVersion' => 1,
            'plugins' => [$entry, array_merge($entry, ['version' => '1.0.1'])],
        ]));

        $this->expectException(PluginRuntimeException::class);
        (new BuiltinPluginRepository)->descriptors();
    }
}
