<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PluginMake extends Command
{
    protected $signature = 'plugin:make
        {pluginId? : Plugin identifier like acme.hello-world}
        {name? : Human readable plugin name}
        {--path= : Target directory for the scaffold}
        {--shape= : One of php-declarative, php-webview, php-custom-bundle, bridge-declarative, bridge-webview, bridge-custom-bundle}
        {--image= : Optional required container image reference}
        {--managed-service= : For bridge plugins, whether WPrint 3D should manage the declared service image}
        {--cpu= : Optional minimum CPU cores}
        {--memory= : Optional minimum memory in megabytes}';

    protected $description = 'Create a new plugin scaffold';

    public function handle(): int
    {
        $pluginId = Str::lower(trim((string) ($this->argument('pluginId') ?: $this->ask('Plugin identifier', 'acme.hello-world'))));
        $name = trim((string) ($this->argument('name') ?: $this->ask('Human friendly plugin name', 'Hello World')));
        $shape = $this->resolveShape();
        $shapeConfig = $this->shapeConfig($shape);
        $targetPath = $this->resolveTargetPath($pluginId);

        if (is_dir($targetPath)) {
            $this->error("Target directory already exists: {$targetPath}");

            return self::FAILURE;
        }

        $imageReference = trim((string) ($this->option('image') ?: ''));
        $includeImage = $imageReference !== '' || $this->confirm('Does this plugin depend on a Docker/Podman image?', false);

        if ($includeImage && $imageReference === '') {
            $imageReference = trim((string) $this->ask(
                'Required image reference',
                'ghcr.io/example/'.str_replace('.', '-', $pluginId).'-service:latest'
            ));
        }

        $managedBridgeService = $this->resolveManagedBridgeService($shapeConfig, $includeImage);

        $memoryMb = $this->normalizeIntegerOption(
            $this->option('memory'),
            'Minimum memory in MB (leave blank for none)'
        );
        $cpuCores = $this->normalizeFloatOption(
            $this->option('cpu'),
            'Minimum CPU cores (leave blank for none)'
        );

        $servicePort = $managedBridgeService
            ? $this->normalizeIntegerOption(null, 'Managed bridge service port', 9310)
            : null;
        $networkAlias = $managedBridgeService
            ? ($this->isInteractiveShell()
                ? trim((string) $this->ask('Managed bridge network alias', Str::slug($pluginId, '-').'-service'))
                : Str::slug($pluginId, '-').'-service')
            : null;

        $sdkVersion = (int) config('plugins.sdk.current.version', config('plugins.sdk_version', 1));
        $sdkRevision = (int) config('plugins.sdk.current.revision', config('plugins.sdk_revision', 0));

        $manifest = $this->buildManifest(
            pluginId: $pluginId,
            name: $name,
            sdkVersion: $sdkVersion,
            sdkRevision: $sdkRevision,
            shapeConfig: $shapeConfig,
            imageReference: $includeImage ? $imageReference : null,
            managedBridgeService: $managedBridgeService,
            servicePort: $servicePort,
            networkAlias: $networkAlias,
            memoryMb: $memoryMb,
            cpuCores: $cpuCores,
        );

        $this->writeScaffold($targetPath, $manifest, $shapeConfig, $managedBridgeService, $servicePort);

        $this->info("Plugin scaffold created at {$targetPath}");
        $this->line("Shape: {$shape}");
        $this->line('Footprint: '.(! empty($manifest['images']) ? 'heavyweight' : 'lightweight'));

        return self::SUCCESS;
    }

    private function resolveShape(): string
    {
        $shape = trim((string) ($this->option('shape') ?: ''));

        if ($shape !== '') {
            return $shape;
        }

        $choices = [
            'php-declarative' => 'PHP + declarative',
            'php-webview' => 'PHP + webview',
            'php-custom-bundle' => 'PHP + custom bundle',
            'bridge-declarative' => 'Bridge + declarative',
            'bridge-webview' => 'Bridge + webview',
            'bridge-custom-bundle' => 'Bridge + custom bundle',
        ];

        $label = $this->choice('Plugin shape', array_values($choices), $choices['php-declarative']);

        return array_search($label, $choices, true) ?: 'php-declarative';
    }

    private function resolveTargetPath(string $pluginId): string
    {
        return (string) ($this->option('path') ?: base_path('plugins/'.str_replace('.', '-', $pluginId)));
    }

    private function resolveManagedBridgeService(array $shapeConfig, bool $includeImage): bool
    {
        if (($shapeConfig['runtimeType'] ?? 'php') !== 'bridge' || ! $includeImage) {
            return false;
        }

        $option = $this->option('managed-service');

        if ($option !== null) {
            return filter_var($option, FILTER_VALIDATE_BOOL);
        }

        if (! $this->isInteractiveShell()) {
            return true;
        }

        return $this->confirm('Should WPrint 3D manage and start the bridge service image for this plugin?', true);
    }

    private function shapeConfig(string $shape): array
    {
        return match ($shape) {
            'php-webview' => ['runtimeType' => 'php', 'uiMode' => 'webview'],
            'php-custom-bundle' => ['runtimeType' => 'php', 'uiMode' => 'custom_bundle'],
            'bridge-declarative' => ['runtimeType' => 'bridge', 'uiMode' => 'declarative'],
            'bridge-webview' => ['runtimeType' => 'bridge', 'uiMode' => 'webview'],
            'bridge-custom-bundle' => ['runtimeType' => 'bridge', 'uiMode' => 'custom_bundle'],
            default => ['runtimeType' => 'php', 'uiMode' => 'declarative'],
        };
    }

    private function buildManifest(
        string $pluginId,
        string $name,
        int $sdkVersion,
        int $sdkRevision,
        array $shapeConfig,
        ?string $imageReference,
        bool $managedBridgeService,
        ?int $servicePort,
        ?string $networkAlias,
        ?int $memoryMb,
        ?float $cpuCores,
    ): array {
        $runtimeType = $shapeConfig['runtimeType'];
        $uiMode = $shapeConfig['uiMode'];
        $assets = [];
        $permissions = ['printer.read', 'ui.settings_tab'];
        $actions = [
            $runtimeType === 'php'
                ? [
                    'id' => 'ping',
                    'label' => 'Ping',
                    'handler' => 'actions/ping.php',
                ]
                : [
                    'id' => 'ping',
                    'label' => 'Ping',
                    'path' => '/actions/ping',
                ],
        ];
        $hooks = [
            'app.boot' => $runtimeType === 'php'
                ? ['handler' => 'hooks/on_boot.php']
                : ['path' => '/hooks/app.boot'],
        ];

        $runtime = $runtimeType === 'php'
            ? [
                'type' => 'php',
                'entry' => 'plugin.php',
            ]
            : [
                'type' => 'bridge',
                'baseUrl' => 'http://localhost:9310',
            ];

        $uiExtension = [
            'id' => 'settings',
            'surface' => 'settings_tab',
            'mode' => $uiMode,
            'title' => "{$name} Settings",
        ];

        if ($uiMode === 'declarative') {
            $uiExtension['schema'] = [
                'component' => 'section',
                'title' => 'Lightweight template',
                'children' => [
                    [
                        'component' => 'text',
                        'text' => 'This scaffold shows the default host-rendered settings path.',
                    ],
                    [
                        'component' => 'button',
                        'label' => 'Run Ping Action',
                        'actionId' => 'ping',
                    ],
                ],
            ];
        } elseif ($uiMode === 'webview') {
            $assets[] = ['path' => 'ui/settings.html'];
            $permissions[] = 'ui.webview';
            $uiExtension['url'] = 'asset://ui/settings.html';
        } else {
            $assets[] = ['path' => 'ui/settings.html'];
            $permissions[] = 'ui.custom_bundle';
            $uiExtension['bundle'] = ['url' => 'asset://ui/settings.html'];
        }

        $manifest = [
            'id' => $pluginId,
            'name' => $name,
            'version' => '0.1.0',
            'sdkVersion' => $sdkVersion,
            'sdkRevision' => $sdkRevision,
            'minCoreVersion' => config('plugins.core_version', '0.0.0'),
            'description' => 'Describe what this plugin adds to WPrint3D.',
            'author' => 'Your Name',
            'runtime' => $runtime,
            'permissions' => array_values(array_unique($permissions)),
            'hooks' => $hooks,
            'actions' => $actions,
            'uiExtensions' => [$uiExtension],
            'signature' => [
                'algorithm' => 'none',
            ],
        ];

        if ($assets !== []) {
            $manifest['assets'] = $assets;
        }

        if ($memoryMb || $cpuCores) {
            $manifest['requirements'] = array_filter([
                'memoryMb' => $memoryMb,
                'cpuCores' => $cpuCores,
            ], fn ($value) => $value !== null);
        }

        if ($imageReference) {
            $imageId = 'service-image';
            $manifest['images'] = [[
                'id' => $imageId,
                'image' => $imageReference,
                'engine' => 'auto',
                'healthcheck' => [
                    'command' => ['sh', '-lc', 'echo plugin dependency ready'],
                    'timeoutSecs' => 15,
                ],
            ]];

            if ($managedBridgeService && $servicePort) {
                $manifest['images'][0]['service'] = [
                    'port' => $servicePort,
                    'networkAlias' => $networkAlias ?: Str::slug($pluginId, '-').'-service',
                ];
                $manifest['runtime'] = [
                    'type' => 'bridge',
                    'managedImageId' => $imageId,
                    'healthcheck' => '/health',
                ];
            }
        }

        return $manifest;
    }

    private function writeScaffold(string $targetPath, array $manifest, array $shapeConfig, bool $managedBridgeService, ?int $servicePort): void
    {
        @mkdir($targetPath.'/hooks', 0777, true);
        @mkdir($targetPath.'/actions', 0777, true);

        if (($shapeConfig['uiMode'] ?? 'declarative') !== 'declarative') {
            @mkdir($targetPath.'/ui', 0777, true);
        }

        if (($shapeConfig['runtimeType'] ?? 'php') === 'bridge') {
            @mkdir($targetPath.'/bridge', 0777, true);
        }

        file_put_contents($targetPath.'/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (($shapeConfig['runtimeType'] ?? 'php') === 'php') {
            file_put_contents($targetPath.'/plugin.php', <<<'PHP'
<?php

echo json_encode([
    'ok' => true,
]);
PHP);

            file_put_contents($targetPath.'/hooks/on_boot.php', <<<'PHP'
<?php

$input = json_decode(stream_get_contents(STDIN), true);

echo json_encode([
    'data' => [
        'message' => 'Plugin booted.',
        'input' => $input,
    ],
]);
PHP);

            file_put_contents($targetPath.'/actions/ping.php', <<<'PHP'
<?php

$input = json_decode(stream_get_contents(STDIN), true);

echo json_encode([
    'data' => [
        'message' => 'pong',
        'payload' => $input['payload'] ?? [],
    ],
]);
PHP);
        } else {
            file_put_contents($targetPath.'/bridge/server.mjs', <<<'JS'
import http from "node:http";

const port = Number(process.env.PORT || 9310);

const server = http.createServer((request, response) => {
  const chunks = [];

  request.on("data", (chunk) => chunks.push(chunk));
  request.on("end", () => {
    const payload = chunks.length ? JSON.parse(Buffer.concat(chunks).toString("utf8")) : {};

    if (request.url === "/health") {
      response.writeHead(200, { "content-type": "application/json" });
      response.end(JSON.stringify({ ok: true }));
      return;
    }

    if (request.url === "/actions/ping" && request.method === "POST") {
      response.writeHead(200, { "content-type": "application/json" });
      response.end(JSON.stringify({
        data: {
          message: "pong",
          payload: payload.payload || {},
        },
      }));
      return;
    }

    if (request.url === "/hooks/app.boot" && request.method === "POST") {
      response.writeHead(200, { "content-type": "application/json" });
      response.end(JSON.stringify({
        data: {
          message: "Bridge plugin booted.",
          context: payload.context || {},
        },
      }));
      return;
    }

    response.writeHead(404, { "content-type": "application/json" });
    response.end(JSON.stringify({ message: "Not found" }));
  });
});

server.listen(port, () => {
  console.log(`Bridge plugin scaffold listening on ${port}`);
});
JS);

            if ($managedBridgeService && $servicePort) {
                file_put_contents($targetPath.'/bridge/Dockerfile', <<<DOCKER
FROM node:20-alpine
WORKDIR /app
COPY bridge/server.mjs /app/server.mjs
ENV PORT={$servicePort}
EXPOSE {$servicePort}
CMD ["node", "/app/server.mjs"]
DOCKER);
            }
        }

        if (($shapeConfig['uiMode'] ?? 'declarative') !== 'declarative') {
            file_put_contents($targetPath.'/ui/settings.html', <<<'HTML'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Plugin Settings</title>
    <style>
      body {
        margin: 0;
        font-family: sans-serif;
        background: #121212;
        color: #f5f5f5;
        display: grid;
        place-items: center;
        min-height: 100vh;
      }

      .panel {
        width: min(560px, 92vw);
        padding: 24px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.12);
      }
    </style>
  </head>
  <body>
    <section class="panel">
      <h1>Elevated plugin UI</h1>
      <p>This scaffold ships an asset-backed settings page for WebView/custom-bundle mode.</p>
    </section>
  </body>
</html>
HTML);
        }

        file_put_contents($targetPath.'/README.md', $this->readmeForScaffold($manifest, $shapeConfig, $managedBridgeService, $servicePort));
    }

    private function readmeForScaffold(array $manifest, array $shapeConfig, bool $managedBridgeService, ?int $servicePort): string
    {
        $lines = [
            "# {$manifest['name']}",
            '',
            'This scaffold was generated by `php artisan plugin:make`.',
            '',
            '## Shape',
            '',
            "- Runtime: `{$shapeConfig['runtimeType']}`",
            "- UI mode: `{$shapeConfig['uiMode']}`",
            '- Footprint: `'.(! empty($manifest['images']) ? 'heavyweight' : 'lightweight').'`',
            '',
            '## Next steps',
            '',
            '1. Edit `plugin.json` to describe the plugin properly.',
            '2. Implement the runtime handlers or bridge service.',
            '3. Package the plugin with `php artisan plugin:pack '.dirname($this->resolveTargetPath($manifest['id'])).'/'.basename($this->resolveTargetPath($manifest['id'])).'`.',
        ];

        if (($shapeConfig['runtimeType'] ?? 'php') === 'bridge') {
            $lines[] = '4. Run the bridge service locally with `node bridge/server.mjs`.';
        }

        if ($managedBridgeService && $servicePort) {
            $lines[] = '';
            $lines[] = '## Managed image';
            $lines[] = '';
            $lines[] = 'This scaffold declared a managed service image. Build and publish the `bridge/Dockerfile`, then update `plugin.json -> images[0].image` to the final registry URL.';
            $lines[] = "The generated bridge image expects port `{$servicePort}`.";
        }

        return implode("\n", $lines)."\n";
    }

    private function normalizeIntegerOption(mixed $value, string $question, ?int $default = null): ?int
    {
        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        if (! $this->isInteractiveShell()) {
            return $default;
        }

        $answer = trim((string) $this->ask($question, $default !== null ? (string) $default : ''));

        if ($answer === '') {
            return null;
        }

        return (int) $answer;
    }

    private function normalizeFloatOption(mixed $value, string $question): ?float
    {
        if ($value !== null && $value !== '') {
            return (float) $value;
        }

        if (! $this->isInteractiveShell()) {
            return null;
        }

        $answer = trim((string) $this->ask($question, ''));

        if ($answer === '') {
            return null;
        }

        return (float) $answer;
    }

    private function isInteractiveShell(): bool
    {
        return $this->input->isInteractive() && (! defined('STDIN') || @stream_isatty(STDIN));
    }
}
