<?php

namespace App\Plugins;

use App\Plugins\Container\PluginContainerClient;
use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PluginDependencyService
{
    /**
     * @param  callable(array<int, string>, int|null): array{successful: bool, output?: string, errorOutput?: string}|null  $commandRunner
     */
    public function __construct(
        private ?array $hostMetrics = null,
        private $commandRunner = null,
        private ?string $currentContainerName = null,
        private ?string $containerCli = null,
        private ?PluginContainerClient $containerClient = null,
    ) {
        $this->currentContainerName ??= gethostname() ?: 'backend';
        $this->containerCli ??= (string) config('plugins.container.cli', env('CONTAINER_CLI', 'docker'));
        $this->commandRunner ??= function (array $command, ?int $timeout = null): array {
            $result = Process::timeout($timeout ?? (int) config('plugins.container.command_timeout_secs', 60))
                ->run($command);

            return [
                'successful' => $result->successful(),
                'output' => $result->output(),
                'errorOutput' => $result->errorOutput(),
            ];
        };
        $this->containerClient ??= new PluginContainerClient(
            cli: $this->containerCli,
            runner: fn (array $command, ?int $timeout): array => ($this->commandRunner)($command, $timeout),
            defaultTimeoutSeconds: (int) config('plugins.container.command_timeout_secs', 60),
        );
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

        if (isset($requirements['diskMb']) && ($host['diskFreeMb'] ?? 0) > 0 && $host['diskFreeMb'] < (int) $requirements['diskMb']) {
            $warnings[] = "This plugin requests at least {$requirements['diskMb']} MB of disk space, but this host reports {$host['diskFreeMb']} MB free.";
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

    /**
     * Return a bounded, administrator-facing snapshot of managed runtime state.
     * Raw Docker inspection, host mountpoints, environment, and credentials are
     * intentionally never returned.
     */
    public function diagnostics(array $plugin): array
    {
        $manifest = $plugin['manifest'] ?? $plugin;
        $state = $plugin['dependency_state'] ?? $plugin['dependencyState'] ?? [];
        $diagnostics = [];

        foreach (array_values($manifest['images'] ?? []) as $image) {
            $service = $image['service'] ?? [];
            $imageId = (string) ($image['id'] ?? '');
            $entry = [
                'id' => $imageId,
                'desiredImage' => (string) ($image['image'] ?? ''),
                'container' => [
                    'name' => null,
                    'present' => false,
                    'running' => false,
                    'health' => null,
                    'imageId' => null,
                    'resolvedDigests' => [],
                    'specHash' => null,
                    'expectedSpecHash' => null,
                    'specMatches' => false,
                ],
                'volumes' => [],
            ];

            if (empty($service['port'])) {
                $diagnostics[] = $entry;

                continue;
            }

            $serviceState = $state['images'][$imageId]['service'] ?? [];
            $containerName = (string) ($serviceState['containerName'] ?? $this->managedContainerName(
                (string) ($plugin['id'] ?? $manifest['id'] ?? 'plugin'),
                $imageId,
            ));
            // Revision 5 is host-owned. Preserve the legacy network field for
            // older manifests so their persisted fingerprints remain readable.
            $legacyNetwork = ((int) ($manifest['sdkRevision'] ?? 0) < 5)
                ? trim((string) ($service['network'] ?? ''))
                : '';
            $networkName = (string) ($serviceState['networkName'] ?? ($legacyNetwork !== '' ? $legacyNetwork : config('plugins.container.network', '')));
            $networkAlias = (string) ($serviceState['networkAlias'] ?? $service['networkAlias'] ?? $containerName);
            $expectedSpecHash = $networkName !== ''
                ? $this->serviceConfigFingerprint($image, $service, $networkName, $networkAlias)
                : null;
            $entry['container']['name'] = $containerName;
            $entry['container']['expectedSpecHash'] = $expectedSpecHash;

            $inspect = $this->run([
                $this->containerCli,
                'inspect',
                $containerName,
                '--format',
                '{{json .}}',
            ]);
            $decoded = $this->decodeJsonOutput($inspect);
            if (is_array($decoded)) {
                $containerState = is_array($decoded['State'] ?? null) ? $decoded['State'] : [];
                $config = is_array($decoded['Config'] ?? null) ? $decoded['Config'] : [];
                $labels = is_array($config['Labels'] ?? null) ? $config['Labels'] : [];
                $repoDigests = is_array($decoded['RepoDigests'] ?? null) ? $decoded['RepoDigests'] : [];
                $entry['container']['present'] = true;
                $entry['container']['running'] = (bool) ($containerState['Running'] ?? false);
                $entry['container']['health'] = data_get($containerState, 'Health.Status');
                $entry['container']['imageId'] = is_string($decoded['Image'] ?? null) ? $decoded['Image'] : null;
                $entry['container']['resolvedDigests'] = array_values(array_filter($repoDigests, 'is_string'));
                $entry['container']['specHash'] = isset($labels['wprint3d.plugin.config'])
                    ? (string) $labels['wprint3d.plugin.config']
                    : null;
                $entry['container']['specMatches'] = $expectedSpecHash !== null
                    && $entry['container']['specHash'] === $expectedSpecHash;
            }

            foreach (($service['storage'] ?? []) as $mount) {
                $mountName = trim((string) ($mount['name'] ?? ''), '-');
                if ($mountName === '') {
                    continue;
                }

                $volumeName = 'wprint3d-plugin-'.trim((string) ($plugin['id'] ?? $manifest['id'] ?? $imageId), '-').'-'.$mountName;
                $volumeInspect = $this->run([
                    $this->containerCli,
                    'volume',
                    'inspect',
                    $volumeName,
                    '--format',
                    '{{json .}}',
                ]);
                $volume = $this->decodeJsonOutput($volumeInspect);
                $entry['volumes'][] = [
                    'name' => $volumeName,
                    'present' => is_array($volume),
                    'driver' => is_array($volume) && is_string($volume['Driver'] ?? null) ? $volume['Driver'] : null,
                ];
            }

            $diagnostics[] = $entry;
        }

        return $diagnostics;
    }

    public function prepare(array $manifest, array $state = []): array
    {
        $summary = $this->summarize($manifest, $state);
        $preparedImages = [];

        foreach ($summary['images'] as $image) {
            $pullTimeout = max(1, (int) ($image['pullTimeoutSecs'] ?? config('plugins.container.pull_timeout_secs', 300)));
            $this->runOrFail([$this->containerCli, 'pull', $image['image']], "Unable to pull plugin image {$image['image']}.", $pullTimeout);
            $this->assertPulledDigest($image, $manifest, $pullTimeout);

            $imageState = [
                'pulled' => true,
                'pulledAt' => now()->toAtomString(),
            ];

            if (! empty($image['healthcheck']['command'])) {
                $healthCommand = $this->normalizeCommand($image['healthcheck']['command']);
                $entrypoint = array_shift($healthCommand);
                $command = array_merge(
                    [$this->containerCli, 'run', '--rm', '--network', 'none', '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m', '--entrypoint', $entrypoint, $image['image']],
                    $healthCommand
                );

                $this->runOrFail(
                    $command,
                    "Healthcheck failed for plugin image {$image['id']}.",
                    (int) ($image['healthcheck']['timeoutSecs'] ?? config('plugins.container.healthcheck_timeout_secs', 30)),
                );
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
            $pullTimeout = max(1, (int) ($managedImage['pullTimeoutSecs'] ?? config('plugins.container.pull_timeout_secs', 300)));
            $this->runOrFail([$this->containerCli, 'pull', $managedImage['image']], "Unable to pull managed plugin image {$managedImage['image']}.", $pullTimeout);
            $this->assertPulledDigest($managedImage, $manifest, $pullTimeout);
            $canonicalContainerName = $this->managedContainerName((string) ($plugin['id'] ?? $manifest['id'] ?? 'plugin'), (string) $managedImage['id']);
            // Revision 5 managed services may communicate on the WPrint network
            // only; older revisions retain their declared-network behavior.
            $declaredNetwork = trim((string) ($service['network'] ?? ''));
            $networkName = ((int) ($manifest['sdkRevision'] ?? 0) < 5 && $declaredNetwork !== '')
                ? $declaredNetwork
                : $this->managedNetworkName();
            $canonicalNetworkAlias = $service['networkAlias'] ?: $canonicalContainerName;
            $containerName = $canonicalContainerName;
            $networkAlias = $canonicalNetworkAlias;
            $inspect = $this->run([$this->containerCli, 'inspect', $canonicalContainerName, '--format', '{{.State.Running}}']);
            $candidate = false;
            $expectedConfig = $this->serviceConfigFingerprint($managedImage, $service, $networkName, $canonicalNetworkAlias);

            if (($inspect['successful'] ?? false) && trim((string) ($inspect['output'] ?? '')) === 'true') {
                $labels = $this->run([$this->containerCli, 'inspect', $canonicalContainerName, '--format', '{{index .Config.Labels "wprint3d.plugin.config"}}']);

                if (trim((string) ($labels['output'] ?? '')) !== $expectedConfig) {
                    // Keep the healthy canonical container serving traffic while
                    // the new digest starts on an isolated candidate alias.
                    $candidate = true;
                    $containerName = $canonicalContainerName.'-candidate-'.substr($expectedConfig, 0, 12);
                    $networkAlias = $canonicalNetworkAlias.'-candidate';
                    $this->run([$this->containerCli, 'rm', '-f', $containerName]);
                    $inspect = ['successful' => false];
                }
            }

            $runtimeAuth = $this->runtimeAuthState($runtime, $state);

            if (! ($inspect['successful'] ?? false) || trim((string) ($inspect['output'] ?? '')) !== 'true') {
                $command = $this->buildServiceRunCommand(
                    plugin: $plugin,
                    image: $managedImage,
                    service: $service,
                    containerName: $containerName,
                    networkName: $networkName,
                    networkAlias: $networkAlias,
                    runtimeAuth: $runtimeAuth,
                    modernRuntime: (int) ($manifest['sdkRevision'] ?? 0) >= 5,
                );

                $this->runOrFail($command, "Unable to start managed plugin image {$managedImage['id']}.");
            }

            $nextState['images'][$managedImage['id']] = [
                'service' => [
                    'status' => 'running',
                    'containerName' => $containerName,
                    'networkName' => $networkName,
                    'networkAlias' => $networkAlias,
                    'port' => (int) $service['port'],
                    'configFingerprint' => $this->serviceConfigFingerprint($managedImage, $service, $networkName, $networkAlias),
                    ...($candidate ? [
                        'candidateContainerName' => $containerName,
                        'candidateNetworkAlias' => $networkAlias,
                        'canonicalContainerName' => $canonicalContainerName,
                        'canonicalNetworkAlias' => $canonicalNetworkAlias,
                    ] : []),
                ],
            ];

            $runtimeState['baseUrl'] = 'http://'.$networkAlias.':'.(int) $service['port'];

            if ($runtimeAuth !== null) {
                $runtimeState['authTokenCiphertext'] = $runtimeAuth['ciphertext'];
            }
        }

        return array_merge(
            $this->summarize($manifest, array_replace_recursive($state, $nextState)),
            ['runtime' => $runtimeState]
        );
    }

    public function hasCandidate(array $state): bool
    {
        foreach (($state['images'] ?? []) as $imageState) {
            if (! empty($imageState['service']['candidateContainerName'])) {
                return true;
            }
        }

        return false;
    }

    public function discardCandidate(array $state): array
    {
        foreach (($state['images'] ?? []) as $imageState) {
            $candidate = $imageState['service']['candidateContainerName'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $this->run([$this->containerCli, 'rm', '-f', $candidate]);
            }
        }

        return $state;
    }

    /**
     * Switch a previously healthchecked candidate into the canonical network
     * alias. The old container is stopped only after the candidate is attached
     * to the live alias, so failed candidates never interrupt the old version.
     */
    public function promoteCandidate(array $plugin, array $state): array
    {
        $manifest = $plugin['manifest'] ?? $plugin;
        foreach ($state['images'] ?? [] as $stateKey => $imageState) {
            // `summarize()` presents images as a numeric list, while persisted
            // dependency state is keyed by image id. Resolve both shapes so a
            // restart/promote cannot leave a stale candidate fingerprint.
            $imageId = (string) ($imageState['id'] ?? $stateKey);
            $serviceState = $imageState['service'] ?? [];
            $candidate = $serviceState['candidateContainerName'] ?? null;
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $network = (string) ($serviceState['networkName'] ?? '');
            $canonical = (string) ($serviceState['canonicalContainerName'] ?? '');
            $alias = (string) ($serviceState['canonicalNetworkAlias'] ?? $canonical);
            if ($network === '' || $canonical === '') {
                throw new PluginRuntimeException('Managed runtime candidate is missing promotion metadata.');
            }

            $this->runOrFail(
                [$this->containerCli, 'network', 'connect', '--alias', $alias, $network, $candidate],
                "Unable to attach managed runtime candidate {$candidate} to the live network alias."
            );
            $image = collect($manifest['images'] ?? [])->firstWhere('id', (string) $imageId);
            $stopGrace = is_array($image)
                ? (int) ($image['service']['stopGracePeriodSecs'] ?? config('plugins.container.stop_grace_secs', 30))
                : (int) config('plugins.container.stop_grace_secs', 30);
            $this->run([$this->containerCli, 'stop', '-t', (string) max(1, $stopGrace), $canonical]);
            $this->run([$this->containerCli, 'rm', '-f', $canonical]);
            $this->runOrFail(
                [$this->containerCli, 'rename', $candidate, $canonical],
                "Unable to promote managed runtime candidate {$candidate}."
            );

            $serviceState['status'] = 'running';
            $serviceState['containerName'] = $canonical;
            $serviceState['networkAlias'] = $alias;
            if (is_array($image)) {
                $serviceState['configFingerprint'] = $this->serviceConfigFingerprint(
                    $image,
                    $image['service'] ?? [],
                    $network,
                    $alias,
                );
            }
            unset($serviceState['candidateContainerName'], $serviceState['candidateNetworkAlias'], $serviceState['canonicalContainerName'], $serviceState['canonicalNetworkAlias']);
            $state['images'][$stateKey]['service'] = $serviceState;
            $state['runtime']['baseUrl'] = 'http://'.$alias.':'.(int) ($serviceState['port'] ?? 0);
        }

        return $state;
    }

    public function deactivate(array $plugin): void
    {
        $manifest = $plugin['manifest'] ?? $plugin;

        $this->discardCandidate($plugin['dependency_state'] ?? $plugin['dependencyState'] ?? []);

        foreach (array_values($manifest['images'] ?? []) as $image) {
            if (empty($image['service']['port'])) {
                continue;
            }

            $containerName = $this->managedContainerName((string) ($plugin['id'] ?? $manifest['id'] ?? 'plugin'), (string) $image['id']);
            $this->run([$this->containerCli, 'rm', '-f', $containerName]);
        }
    }

    /**
     * Explicitly delete retained named volumes. This is never part of disable/uninstall.
     */
    public function deletePersistentStorage(array $plugin): array
    {
        $deleted = [];

        foreach ($this->persistentStorageNames($plugin) as $volumeName) {
            $result = $this->run([$this->containerCli, 'volume', 'rm', $volumeName]);

            if (($result['successful'] ?? false) === true) {
                $deleted[] = $volumeName;
            }
        }

        return $deleted;
    }

    /**
     * Resolve only deterministic WPrint-managed volume identities for an installed plugin.
     * Callers may use this list to require an explicit administrator confirmation target.
     *
     * @return array<int, string>
     */
    public function persistentStorageNames(array $plugin): array
    {
        $manifest = $plugin['manifest'] ?? $plugin;
        $pluginId = trim((string) ($plugin['id'] ?? $manifest['id'] ?? ''), '-');

        if ($pluginId === '') {
            return [];
        }

        $names = [];
        foreach (array_values($manifest['images'] ?? []) as $image) {
            foreach (($image['service']['storage'] ?? []) as $mount) {
                $mountName = trim((string) ($mount['name'] ?? ''), '-');
                if ($mountName === '') {
                    continue;
                }

                $names[] = 'wprint3d-plugin-'.$pluginId.'-'.$mountName;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Reconcile enabled managed services after a host/container restart.
     *
     * @param  array<int, array<string, mixed>>  $plugins
     * @return array<int, array<string, mixed>>
     */
    public function reconcile(array $plugins): array
    {
        $results = [];

        foreach ($plugins as $plugin) {
            $manifest = $plugin['manifest'] ?? $plugin;

            if (($plugin['enabled'] ?? true) !== true || ($manifest['runtime']['type'] ?? null) !== 'bridge') {
                continue;
            }

            try {
                $results[] = [
                    'id' => $plugin['id'] ?? $manifest['id'] ?? null,
                    'status' => 'ready',
                    'state' => $this->activate($plugin, $plugin['dependency_state'] ?? $plugin['dependencyState'] ?? []),
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'id' => $plugin['id'] ?? $manifest['id'] ?? null,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function hostCapabilities(): array
    {
        if ($this->hostMetrics !== null) {
            return [
                'cpuCores' => (float) ($this->hostMetrics['cpu']['cores'] ?? 0),
                'memoryMb' => (int) ($this->hostMetrics['ram']['totalMegabytes'] ?? 0),
                'diskFreeMb' => (int) ($this->hostMetrics['disk']['freeMegabytes'] ?? 0),
            ];
        }

        $cpuInfo = @file_get_contents('/proc/cpuinfo') ?: '';
        $memoryInfo = @file_get_contents('/proc/meminfo') ?: '';
        preg_match_all('/^processor\s*:/m', $cpuInfo, $cpuMatches);
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/mi', $memoryInfo, $memoryMatch);

        return [
            'cpuCores' => max(1, (float) count($cpuMatches[0] ?? [])),
            'memoryMb' => isset($memoryMatch[1]) ? (int) floor(((int) $memoryMatch[1]) / 1024) : 0,
            'diskFreeMb' => $this->diskFreeMegabytes(),
        ];
    }

    private function diskFreeMegabytes(): int
    {
        $bytes = @disk_free_space(base_path());

        return is_numeric($bytes) ? (int) floor(((float) $bytes) / 1048576) : 0;
    }

    private function currentNetworkName(): string
    {
        $configuredNetwork = trim((string) config('plugins.container.network', ''));

        if ($configuredNetwork !== '') {
            return $configuredNetwork;
        }

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

    private function buildServiceRunCommand(
        array $plugin,
        array $image,
        array $service,
        string $containerName,
        string $networkName,
        string $networkAlias,
        ?array $runtimeAuth,
        bool $modernRuntime,
    ): array {
        $command = [
            $this->containerCli,
            'run',
            '-d',
            '--name',
            $containerName,
            '--restart',
            'unless-stopped',
            '--label',
            'wprint3d.plugin.id='.($plugin['id'] ?? $image['id']),
            '--label',
            "wprint3d.plugin.image_id={$image['id']}",
            '--network',
            $networkName,
            '--network-alias',
            $networkAlias,
        ];

        if ($modernRuntime && (($service['resources'] ?? []) !== [] || ($service['storage'] ?? []) !== [] || ($service['security'] ?? []) !== [] || $runtimeAuth !== null)) {
            $command = array_merge($command, [
                '--label',
                'wprint3d.plugin.config='.$this->serviceConfigFingerprint($image, $service, $networkName, $networkAlias),
            ]);
        }

        $resources = $service['resources'] ?? [];

        if ($resources !== []) {
            $memoryMb = (int) ($resources['memoryMb'] ?? config('plugins.container.default_memory_mb', 2048));
            $cpuQuota = (int) ($resources['cpuQuota'] ?? config('plugins.container.default_cpu_quota', 100000));
            $pidsLimit = (int) ($resources['pidsLimit'] ?? config('plugins.container.default_pids_limit', 256));
            $command = array_merge($command, ['--memory', "{$memoryMb}m", '--cpu-quota', (string) $cpuQuota, '--pids-limit', (string) $pidsLimit]);
        }

        $security = $service['security'] ?? [];

        if (($security['readOnlyRootFs'] ?? false) === true) {
            $command = array_merge($command, ['--read-only']);
        }

        $tmpfs = $security['tmpfs'] ?? [];
        if ($tmpfs === [] && (($security['readOnlyRootFs'] ?? false) === true)) {
            $tmpfs = [['path' => '/tmp', 'sizeMb' => 256]];
        }
        foreach ($tmpfs as $mount) {
            $command = array_merge($command, [
                '--tmpfs',
                (string) ($mount['path'] ?? '/tmp').':rw,noexec,nosuid,size='.(int) ($mount['sizeMb'] ?? 256).'m',
            ]);
        }

        if (($security['noNewPrivileges'] ?? false) === true) {
            $command = array_merge($command, ['--security-opt', 'no-new-privileges:true']);
        }

        if (! empty($security['user'])) {
            $command = array_merge($command, ['--user', (string) $security['user']]);
        }

        if (isset($service['stopGracePeriodSecs'])) {
            $command = array_merge($command, ['--stop-timeout', (string) max(1, (int) $service['stopGracePeriodSecs'])]);
        }

        foreach (($security['capDrop'] ?? []) as $capability) {
            $command = array_merge($command, ['--cap-drop', (string) $capability]);
        }

        foreach (($service['storage'] ?? []) as $mount) {
            $volumeName = 'wprint3d-plugin-'.trim((string) ($plugin['id'] ?? $image['id']), '-').'-'.trim((string) $mount['name'], '-');
            $this->runOrFail([$this->containerCli, 'volume', 'create', $volumeName], "Unable to create plugin storage volume {$volumeName}.");
            $mountSpec = "type=volume,source={$volumeName},destination={$mount['target']}";
            if (($mount['readOnly'] ?? false) === true) {
                $mountSpec .= ',readonly';
            }
            $command = array_merge($command, ['--mount', $mountSpec]);
        }

        foreach (($service['environment'] ?? []) as $key => $value) {
            $command[] = '-e';
            $command[] = "{$key}={$value}";
        }

        if ($runtimeAuth !== null) {
            $command = array_merge($command, [
                '-e',
                'WPRINT_BRIDGE_AUTH_TOKEN='.$runtimeAuth['plaintext'],
                '-e',
                'WPRINT_BRIDGE_PLUGIN_ID='.($plugin['id'] ?? $image['id']),
            ]);
        }

        $command[] = $image['image'];

        return array_merge($command, $this->normalizeCommand($service['args'] ?? []));
    }

    private function serviceConfigFingerprint(array $image, array $service, string $networkName, string $networkAlias): string
    {
        unset($service['network']);

        return hash('sha256', json_encode([
            'image' => $image['image'],
            'service' => $service,
            'network' => $networkName,
            'alias' => $networkAlias,
        ], JSON_THROW_ON_ERROR));
    }

    private function managedNetworkName(): string
    {
        $configuredNetwork = trim((string) config('plugins.container.network', ''));

        return $configuredNetwork !== '' ? $configuredNetwork : $this->currentNetworkName();
    }

    private function runtimeAuthState(array $runtime, array $state): ?array
    {
        if (($runtime['auth']['mode'] ?? 'none') !== 'wprint-bridge') {
            return null;
        }

        $ciphertext = $state['runtime']['authTokenCiphertext'] ?? null;

        if (is_string($ciphertext) && $ciphertext !== '') {
            try {
                return ['ciphertext' => $ciphertext, 'plaintext' => Crypt::decryptString($ciphertext)];
            } catch (\Throwable) {
            }
        }

        $plaintext = Str::random(64);

        return ['ciphertext' => Crypt::encryptString($plaintext), 'plaintext' => $plaintext];
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

    private function decodeJsonOutput(array $result): ?array
    {
        if (! ($result['successful'] ?? false)) {
            return null;
        }

        $decoded = json_decode(trim((string) ($result['output'] ?? '')), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function run(array $command, ?int $timeoutSeconds = null): array
    {
        return $this->containerClient->run($command, $timeoutSeconds);
    }

    private function runOrFail(array $command, string $message, ?int $timeoutSeconds = null): array
    {
        return $this->containerClient->runOrFail($command, $message, $timeoutSeconds);
    }

    private function assertPulledDigest(array $image, array $manifest, ?int $timeoutSeconds = null): void
    {
        $reference = (string) ($image['image'] ?? '');
        if ((int) ($manifest['sdkRevision'] ?? 0) < 5 || ! str_contains($reference, '@sha256:')) {
            return;
        }

        $expected = substr($reference, (int) strrpos($reference, '@') + 1);
        $result = $this->run([
            $this->containerCli,
            'image',
            'inspect',
            $reference,
            '--format',
            '{{index .RepoDigests 0}}',
        ], $timeoutSeconds);
        $actual = trim((string) ($result['output'] ?? ''));

        if (! ($result['successful'] ?? false) || ! str_ends_with($actual, $expected)) {
            throw new PluginRuntimeException("Pulled plugin image {$reference} did not resolve to its declared digest.");
        }
    }
}
