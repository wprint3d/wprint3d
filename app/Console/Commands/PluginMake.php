<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PluginMake extends Command
{
    protected $signature = 'plugin:make
        {pluginId : Plugin identifier like acme.hello-world}
        {name : Human readable plugin name}
        {--path= : Target directory for the scaffold}';

    protected $description = 'Create a new plugin scaffold';

    public function handle(): int
    {
        $pluginId = Str::lower((string) $this->argument('pluginId'));
        $name = (string) $this->argument('name');
        $targetPath = $this->option('path') ?: base_path('plugins/' . str_replace('.', '-', $pluginId));

        if (is_dir($targetPath)) {
            $this->error("Target directory already exists: {$targetPath}");

            return self::FAILURE;
        }

        mkdir($targetPath . '/hooks', 0777, true);
        mkdir($targetPath . '/actions', 0777, true);

        $sdkVersion = (int) config('plugins.sdk.current.version', config('plugins.sdk_version', 1));
        $sdkRevision = (int) config('plugins.sdk.current.revision', config('plugins.sdk_revision', 0));

        file_put_contents($targetPath . '/plugin.json', json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '0.1.0',
            'sdkVersion' => $sdkVersion,
            'sdkRevision' => $sdkRevision,
            'minCoreVersion' => config('plugins.core_version', '0.0.0'),
            'description' => 'Describe what this plugin adds to WPrint3D.',
            'author' => 'Your Name',
            'runtime' => [
                'type' => 'php',
                'entry' => 'plugin.php',
            ],
            'permissions' => [
                'printer.read',
                'ui.settings_tab',
            ],
            'hooks' => [
                'app.boot' => [
                    'handler' => 'hooks/on_boot.php',
                ],
            ],
            'actions' => [
                [
                    'id' => 'ping',
                    'label' => 'Ping',
                    'handler' => 'actions/ping.php',
                ],
            ],
            'uiExtensions' => [
                [
                    'id' => 'settings',
                    'surface' => 'settings_tab',
                    'mode' => 'declarative',
                    'title' => 'Example Settings',
                    'schema' => [
                        'component' => 'section',
                        'title' => 'Lightweight template',
                        'children' => [
                            [
                                'component' => 'text',
                                'text' => 'This default scaffold uses host-rendered declarative UI because it is the lightest option for small devices.',
                            ],
                            [
                                'component' => 'button',
                                'label' => 'Run Ping Action',
                                'actionId' => 'ping',
                            ],
                        ],
                    ],
                ],
            ],
            'signature' => [
                'algorithm' => 'none',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($targetPath . '/plugin.php', <<<'PHP'
<?php

echo json_encode([
    'ok' => true,
]);
PHP);

        file_put_contents($targetPath . '/hooks/on_boot.php', <<<'PHP'
<?php

$input = json_decode(stream_get_contents(STDIN), true);

echo json_encode([
    'data' => [
        'message' => 'Plugin booted.',
        'input' => $input,
    ],
]);
PHP);

        file_put_contents($targetPath . '/actions/ping.php', <<<'PHP'
<?php

$input = json_decode(stream_get_contents(STDIN), true);

echo json_encode([
    'data' => [
        'message' => 'pong',
        'payload' => $input['payload'] ?? [],
    ],
]);
PHP);

        file_put_contents($targetPath . '/README.md', <<<MD
# {$name}

This plugin scaffold defaults to declarative host-rendered UI because it is the most lightweight option on low-memory devices.

Alternative UI modes are available in the manifest:
- `webview`
- `custom_bundle`

Asset-backed WebView and custom bundle extensions should declare their HTML entrypoint in `assets` and reference it with an `asset://` URL.

Package the plugin with:

```bash
php artisan plugin:pack {$targetPath}
```
MD);

        $this->info("Plugin scaffold created at {$targetPath}");

        return self::SUCCESS;
    }
}
