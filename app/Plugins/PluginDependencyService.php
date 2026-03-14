<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\Process;

class PluginDependencyService
{
    /**
     * @param  callable(array<int, string>): array{successful: bool, output?: string, errorOutput?: string}|null  $commandRunner
     */
    public function __construct(
        private ?array $hostMetrics = null,
        private $commandRunner = null,
        private ?string $currentContainerName = null,
        private ?string $containerCli = null,
    ) {
        $this->currentContainerName ??= gethostname() ?: 'backend';
        $this->containerCli ??= (string) config('plugins.container.cli', env('CONTAINER_CLI', 'podman'));
        $this->commandRunner ??= function (array $command): array {
            $result = Process::timeout((int) config('plugins.container.command_timeout_secs', 60))
                ->run($command);

            return [
                'successful' => $result->successful(),
                'output' => $result->output(),
                'errorOutput' => $result->errorOutput(),
            ];
        };
    }

    public function summarize(array $manifest, array $state = []): array
    {
        $images = array_values($manifest['images'] ?? []);
        $classification = $images === [] ? 'lightweight' : 'heavyweight';
        $requirements = $manifest['requirements'] ?? [];
        $host = $this->hostCapabilities();
        $warnings = [];
        $hint = $classification === 'heavyweight'
            ? 'This plugin is heavyweight and may require additional resources to run properly.'
            : 'This plugin is lightweight and runs without declared container image dependencies.';

        if (isset($requirements['memoryMb']) && $host['memoryMb'] < (int) $requirements['memoryMb']) {
            $warnings[] = "This plugin requests at least {$requirements['memoryMb']} MB of memory, but this host reports {$host['memoryMb']} MB.";
        }

        if (isset($requirements['cpuCores']) && $host['cpuCores'] < (float) $requirements['cpuCores']) {
            $warnings[] = "This plugin requests at least {$requirements['cpuCores']} CPU cores, but this host reports {$host['cpuCores']}.";
        }

        $host['meetsRequirements'] = count(array_filter($warnings, fn (string $warning) => str_contains($warning, 'requests at least'))) === 0;

        return [
            'classification' => $classification,
            'hint' => $hint,
            'requirements' => $requirements,
            'host' => $host,
            'warnings' => array_values(array_unique($warnings)),
            'runtime' => $state['runtime'] ?? [],
            'images' => array_map(function (array $image) use ($state) {
                $imageState = $state['images'][$image['id']] ?? [];

                return array_merge($image, $imageState);
            }, $images),
        ];
    }

    public function prepare(array $manifest, array $state = []): array
    {
        $summary = $this->summarize($manifest, $state);
        $preparedImages = [];

        foreach ($summary['images'] as $image) {
            $this->runOrFail([$this->containerCli, 'pull', $image['image']], "Unable to pull plugin image {$image['image']}.");

            $imageState = [
                'pulled' => true,
                'pulledAt' => now()->toAtomString(),
            ];

            if (! empty($image['healthcheck']['command'])) {
                $command = array_merge(
                    [$this->containerCli, 'run', '--rm', $image['image']],
                    $this->normalizeCommand($image['healthcheck']['command'])
                );

                $this->runOrFail($command, "Healthcheck failed for plugin image {$image['id']}.");
                $imageState['healthcheck'] = [
                    'successful' => true,
                    'checkedAt' => now()->toAtomString(),
                ];
            }

            $preparedImages[$image['id']] = $imageState;
        }

        return $this->summarize($manifest, ['images' => $preparedImages]);
    }

    public function activate(array $plugin, array $state = []): array
    {
        $manifest = $plugin['manifest'] ?? $plugin;
        $summary = $this->summarize($manifest, $state);
        $runtime = $manifest['runtime'] ?? [];
        $runtimeState = [];
        $nextState = ['images' => []];

        if (($runtime['type'] ?? null) === 'bridge' && ! empty($runtime['managedImageId'])) {
            $managedImage = collect($summary['images'])->firstWhere('id', (string) $runtime['managedImageId']);

            if (! $managedImage) {
                throw new PluginRuntimeException("Managed plugin image {$runtime['managedImageId']} is not declared.");
            }

            $service = $managedImage['service'] ?? [];
            $this->runOrFail([$this->containerCli, 'pull', $managedImage['image']], "Unable to pull managed plugin image {$managedImage['image']}.");
            $containerName = $this->managedContainerName((string) ($plugin['id'] ?? $manifest['id'] ?? 'plugin'), (string) $managedImage['id']);
            $networkName = $this->currentNetworkName();
            $networkAlias = $service['networkAlias'] ?: $containerName;
            $inspect = $this->run([$this->containerCli, 'inspect', $containerName, '--format', '{{.State.Running}}']);

            if (! ($inspect['successful'] ?? false) || trim((string) ($inspect['output'] ?? '')) !== 'true') {
                $command = [
                    $this->containerCli,
                    'run',
                    '-d',
                    '--name',
                    $containerName,
                    '--restart',
                    'unless-stopped',
                    '--label',
                    'wprint3d.plugin.id='.($plugin['id'] ?? $manifest['id']),
                    '--label',
                    "wprint3d.plugin.image_id={$managedImage['id']}",
                    '--network',
                    $networkName,
                    '--network-alias',
                    $networkAlias,
                ];

                foreach (($service['environment'] ?? []) as $key => $value) {
                    $command[] = '-e';
                    $command[] = "{$key}={$value}";
                }

                $command[] = $managedImage['image'];
                $command = array_merge($command, $this->normalizeCommand($service['args'] ?? []));

                $this->runOrFail($command, "Unable to start managed plugin image {$managedImage['id']}.");
            }

            $nextState['images'][$managedImage['id']] = [
                'service' => [
                    'status' => 'running',
                    'containerName' => $containerName,
                    'networkName' => $networkName,
                    'networkAlias' => $networkAlias,
                    'port' => (int) $service['port'],
                ],
            ];

            $runtimeState['baseUrl'] = 'http://'.$networkAlias.':'.(int) $service['port'];
        }

        return array_merge(
            $this->summarize($manifest, array_replace_recursive($state, $nextState)),
            ['runtime' => $runtimeState]
        );
    }

    public function deactivate(array $plugin): void
    {
        $manifest = $plugin['manifest'] ?? $plugin;

        foreach (array_values($manifest['images'] ?? []) as $image) {
            if (empty($image['service']['port'])) {
                continue;
            }

            $containerName = $this->managedContainerName((string) ($plugin['id'] ?? $manifest['id'] ?? 'plugin'), (string) $image['id']);
            $this->run([$this->containerCli, 'rm', '-f', $containerName]);
        }
    }

    private function hostCapabilities(): array
    {
        if ($this->hostMetrics !== null) {
            return [
                'cpuCores' => (float) ($this->hostMetrics['cpu']['cores'] ?? 0),
                'memoryMb' => (int) ($this->hostMetrics['ram']['totalMegabytes'] ?? 0),
            ];
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo') ?: '';
        $memoryInfo = @file_get_contents('/proc/meminfo') ?: '';
        preg_match_all('/^processor\s*:/m', $cpuInfo, $cpuMatches);
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/mi', $memoryInfo, $memoryMatch);

        return [
            'cpuCores' => max(1, (float) count($cpuMatches[0] ?? [])),
            'memoryMb' => isset($memoryMatch[1]) ? (int) floor(((int) $memoryMatch[1]) / 1024) : 0,
        ];
    }

    private function currentNetworkName(): string
    {
        $inspect = $this->run([
            $this->containerCli,
            'inspect',
            $this->currentContainerName,
            '--format',
            '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}',
        ]);

        if (! ($inspect['successful'] ?? false)) {
            throw new PluginRuntimeException('Unable to determine the current WPrint 3D container network.');
        }

        $network = trim((string) collect(preg_split('/\r\n|\r|\n/', (string) ($inspect['output'] ?? '')) ?: [])
            ->filter()
            ->first());

        if ($network === '') {
            throw new PluginRuntimeException('The current WPrint 3D container is not attached to a plugin-capable network.');
        }

        return $network;
    }

    private function managedContainerName(string $pluginId, string $imageId): string
    {
        return 'wprint3d-plugin-'.trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($pluginId)), '-').'-'.trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($imageId)), '-');
    }

    private function normalizeCommand(mixed $command): array
    {
        if (is_string($command)) {
            $command = preg_split('/\s+/', trim($command)) ?: [];
        }

        if (! is_array($command)) {
            return [];
        }

        return array_values(array_map(
            fn ($part) => trim((string) $part),
            array_filter($command, fn ($part) => trim((string) $part) !== '')
        ));
    }

    private function run(array $command): array
    {
        return ($this->commandRunner)($command);
    }

    private function runOrFail(array $command, string $message): array
    {
        $result = $this->run($command);

        if (! ($result['successful'] ?? false)) {
            $errorOutput = trim((string) ($result['errorOutput'] ?? ''));
            $message .= $errorOutput !== '' ? " {$errorOutput}" : '';

            throw new PluginRuntimeException($message);
        }

        return $result;
    }
}
