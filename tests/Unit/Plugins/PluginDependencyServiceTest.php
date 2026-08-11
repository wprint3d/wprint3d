<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginDependencyService;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PluginDependencyServiceTest extends TestCase
{
    public function test_it_classifies_plugins_and_warns_when_host_requirements_are_not_met(): void
    {
        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 1],
                'ram' => ['totalMegabytes' => 512],
                'disk' => ['freeMegabytes' => 128],
            ],
        );

        $summary = $service->summarize([
            'id' => 'acme.heavy',
            'images' => [
                [
                    'id' => 'metrics-service',
                    'image' => 'ghcr.io/acme/metrics-service:1.2.3',
                ],
            ],
            'requirements' => [
                'memoryMb' => 1024,
                'cpuCores' => 2,
                'diskMb' => 256,
            ],
        ]);

        $this->assertSame('heavyweight', $summary['classification']);
        $this->assertSame('This plugin is heavyweight and may require additional resources to run properly.', $summary['hint']);
        $this->assertFalse($summary['host']['meetsRequirements']);
        $this->assertSame(512, $summary['host']['memoryMb']);
        $this->assertSame(1.0, $summary['host']['cpuCores']);
        $this->assertSame(128, $summary['host']['diskFreeMb']);
        $this->assertCount(3, $summary['warnings']);
    }

    public function test_it_pulls_declared_images_and_runs_install_healthchecks(): void
    {
        $commands = [];
        $timeouts = [];

        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 4],
                'ram' => ['totalMegabytes' => 4096],
            ],
            commandRunner: function (array $command, ?int $timeout = null) use (&$commands, &$timeouts) {
                $commands[] = $command;
                $timeouts[] = $timeout;

                return [
                    'successful' => true,
                    'output' => '',
                    'errorOutput' => '',
                ];
            },
        );

        $state = $service->prepare([
            'id' => 'acme.heavy',
            'images' => [
                [
                    'id' => 'metrics-service',
                    'image' => 'ghcr.io/acme/metrics-service:1.2.3',
                    'pullTimeoutSecs' => 123,
                    'healthcheck' => [
                        'command' => ['php', '-v'],
                        'timeoutSecs' => 7,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($state['images'][0]['pulled']);
        $this->assertTrue($state['images'][0]['healthcheck']['successful']);
        $this->assertSame(
            [
                ['docker', 'pull', 'ghcr.io/acme/metrics-service:1.2.3'],
                ['docker', 'run', '--rm', '--network', 'none', '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m', '--entrypoint', 'php', 'ghcr.io/acme/metrics-service:1.2.3', '-v'],
            ],
            $commands
        );
        $this->assertSame([123, 7], $timeouts);
    }

    public function test_prepare_preserves_a_running_service_from_summarized_dependency_state(): void
    {
        $service = new PluginDependencyService(
            commandRunner: fn (): array => [
                'successful' => true,
                'output' => '',
                'errorOutput' => '',
            ],
        );
        $manifest = [
            'id' => 'acme.heavy',
            'images' => [[
                'id' => 'metrics-service',
                'image' => 'ghcr.io/acme/metrics-service:2.0.0',
                'service' => [
                    'port' => 9310,
                    'security' => ['readOnlyRootFs' => true],
                ],
            ]],
        ];
        $existingState = [
            'runtime' => ['baseUrl' => 'http://acme-metrics:9310'],
            'images' => [[
                'id' => 'metrics-service',
                'service' => [
                    'status' => 'running',
                    'containerName' => 'wprint3d-plugin-acme-heavy-metrics-service',
                    'networkName' => 'wprint3d_default',
                    'networkAlias' => 'acme-metrics',
                    'port' => 9310,
                ],
            ]],
        ];

        $prepared = $service->prepare($manifest, $existingState);

        $this->assertSame('http://acme-metrics:9310', data_get($prepared, 'runtime.baseUrl'));
        $this->assertSame('running', data_get($prepared, 'images.0.service.status'));
        $this->assertSame(
            'wprint3d-plugin-acme-heavy-metrics-service',
            data_get($prepared, 'images.0.service.containerName'),
        );
        $this->assertTrue(data_get($prepared, 'images.0.service.security.readOnlyRootFs'));
        $this->assertTrue(data_get($prepared, 'images.0.pulled'));
    }

    public function test_it_can_resolve_a_managed_bridge_service_runtime_url(): void
    {
        $commands = [];
        config()->set('plugins.container.network', 'wprint3d_default');

        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 4],
                'ram' => ['totalMegabytes' => 4096],
            ],
            commandRunner: function (array $command) use (&$commands) {
                $commands[] = $command;

                if ($command === ['docker', 'inspect', 'wprint3d-backend-1', '--format', '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}']) {
                    return [
                        'successful' => true,
                        'output' => "wprint3d_default\n",
                        'errorOutput' => '',
                    ];
                }

                if ($command === ['docker', 'inspect', 'wprint3d-plugin-acme-bridge-metrics-service', '--format', '{{.State.Running}}']) {
                    return [
                        'successful' => false,
                        'output' => '',
                        'errorOutput' => 'No such container',
                    ];
                }

                return [
                    'successful' => true,
                    'output' => '',
                    'errorOutput' => '',
                ];
            },
            currentContainerName: 'wprint3d-backend-1',
        );

        $activation = $service->activate([
            'id' => 'acme.bridge',
            'manifest' => [
                'runtime' => [
                    'type' => 'bridge',
                    'managedImageId' => 'metrics-service',
                ],
                'images' => [
                    [
                        'id' => 'metrics-service',
                        'image' => 'ghcr.io/acme/metrics-service:1.2.3',
                        'service' => [
                            'port' => 9310,
                            'networkAlias' => 'acme-bridge-metrics',
                            'security' => [
                                'readOnlyRootFs' => true,
                                'noNewPrivileges' => true,
                                'capDrop' => ['ALL'],
                                'user' => '10001:10001',
                                'tmpfs' => [['path' => '/tmp', 'sizeMb' => 512]],
                            ],
                            'stopGracePeriodSecs' => 17,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('http://acme-bridge-metrics:9310', $activation['runtime']['baseUrl']);
        $this->assertSame('running', $activation['images'][0]['service']['status']);
        $this->assertContains(
            ['docker', 'run', '-d', '--name', 'wprint3d-plugin-acme-bridge-metrics-service', '--restart', 'unless-stopped', '--label', 'wprint3d.plugin.id=acme.bridge', '--label', 'wprint3d.plugin.image_id=metrics-service', '--network', 'wprint3d_default', '--network-alias', 'acme-bridge-metrics', '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=512m', '--security-opt', 'no-new-privileges:true', '--user', '10001:10001', '--stop-timeout', '17', '--cap-drop', 'ALL', 'ghcr.io/acme/metrics-service:1.2.3'],
            $commands
        );
    }

    public function test_it_reports_safe_managed_runtime_diagnostics(): void
    {
        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 4],
                'ram' => ['totalMegabytes' => 4096],
            ],
            commandRunner: function (array $command): array {
                if ($command[1] === 'inspect' && $command[2] === 'wprint3d-plugin-cura-web-ui-cura-gateway') {
                    return [
                        'successful' => true,
                        'output' => json_encode([
                            'Image' => 'sha256:resolved-image',
                            'RepoDigests' => ['ghcr.io/wprint3d/cura-gateway@sha256:'.str_repeat('a', 64)],
                            'Config' => [
                                'Labels' => [
                                    'wprint3d.plugin.config' => 'expected-config',
                                ],
                                'Env' => ['WPRINT_BRIDGE_AUTH_TOKEN=secret'],
                            ],
                            'State' => [
                                'Running' => true,
                                'Health' => ['Status' => 'healthy'],
                            ],
                            'Mounts' => [['Source' => '/var/lib/docker/volumes/private/_data']],
                        ]),
                        'errorOutput' => '',
                    ];
                }

                if ($command[1] === 'volume' && $command[2] === 'inspect') {
                    return [
                        'successful' => true,
                        'output' => json_encode([
                            'Name' => 'wprint3d-plugin-cura-web-ui-data',
                            'Driver' => 'local',
                            'Mountpoint' => '/var/lib/docker/volumes/private/_data',
                        ]),
                        'errorOutput' => '',
                    ];
                }

                return ['successful' => false, 'output' => '', 'errorOutput' => 'not found'];
            },
        );

        $diagnostics = $service->diagnostics([
            'id' => 'cura-web-ui',
            'manifest' => [
                'runtime' => ['type' => 'bridge', 'managedImageId' => 'cura-gateway'],
                'images' => [[
                    'id' => 'cura-gateway',
                    'image' => 'ghcr.io/wprint3d/cura-gateway@sha256:'.str_repeat('a', 64),
                    'service' => [
                        'port' => 9311,
                        'network' => 'wprint3d_default',
                        'networkAlias' => 'cura-web-ui-gateway',
                        'storage' => [['name' => 'data', 'target' => '/data']],
                    ],
                ]],
            ],
            'dependency_state' => [
                'images' => [
                    'cura-gateway' => [
                        'service' => [
                            'networkName' => 'wprint3d_default',
                            'networkAlias' => 'cura-web-ui-gateway',
                        ],
                    ],
                ],
            ],
        ]);

        $container = $diagnostics[0]['container'];
        $this->assertTrue($container['present']);
        $this->assertTrue($container['running']);
        $this->assertSame('healthy', $container['health']);
        $this->assertSame('sha256:resolved-image', $container['imageId']);
        $this->assertNotEmpty($container['resolvedDigests']);
        $this->assertSame('expected-config', $container['specHash']);
        $this->assertFalse($container['specMatches']);
        $this->assertTrue($diagnostics[0]['volumes'][0]['present']);
        $this->assertArrayNotHasKey('Mountpoint', $diagnostics[0]['volumes'][0]);
        $this->assertArrayNotHasKey('Env', $container);
    }

    public function test_it_resolves_only_deterministic_plugin_volume_names(): void
    {
        $service = new PluginDependencyService;

        $names = $service->persistentStorageNames([
            'id' => 'cura-web-ui',
            'manifest' => [
                'images' => [
                    [
                        'id' => 'cura-gateway',
                        'service' => [
                            'storage' => [
                                ['name' => 'data', 'target' => '/data'],
                                ['name' => '--cache--', 'target' => '/cache'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            'wprint3d-plugin-cura-web-ui-data',
            'wprint3d-plugin-cura-web-ui-cache',
        ], $names);
    }

    public function test_it_starts_a_candidate_without_interrupting_the_healthy_container_then_promotes_it(): void
    {
        $commands = [];
        config()->set('plugins.container.network', 'wprint3d_default');
        $service = new PluginDependencyService(
            commandRunner: function (array $command) use (&$commands) {
                $commands[] = $command;
                if ($command === ['docker', 'inspect', 'wprint3d-plugin-acme-bridge-metrics-service', '--format', '{{.State.Running}}']) {
                    return ['successful' => true, 'output' => "true\n", 'errorOutput' => ''];
                }
                if (str_contains(implode(' ', $command), 'Config.Labels')) {
                    return ['successful' => true, 'output' => "old-config\n", 'errorOutput' => ''];
                }

                return ['successful' => true, 'output' => '', 'errorOutput' => ''];
            },
        );
        $plugin = [
            'id' => 'acme.bridge',
            'manifest' => [
                'sdkRevision' => 5,
                'runtime' => ['type' => 'bridge', 'managedImageId' => 'metrics-service'],
                'images' => [[
                    'id' => 'metrics-service',
                    'image' => 'ghcr.io/acme/metrics-service:2.0.0',
                    'service' => ['port' => 9310, 'network' => 'wprint3d_default', 'networkAlias' => 'acme-bridge-metrics'],
                ]],
            ],
        ];

        $candidate = $service->activate($plugin);
        $candidateService = $candidate['images'][0]['service'];
        $this->assertNotEmpty($candidateService['candidateContainerName']);
        $this->assertStringStartsWith('acme-bridge-metrics-candidate-', $candidateService['candidateNetworkAlias']);
        $this->assertSame('http://'.$candidateService['candidateNetworkAlias'].':9310', $candidate['runtime']['baseUrl']);
        $this->assertSame('running', $candidateService['status']);
        $this->assertTrue(collect($commands)->contains(function (array $command) use ($candidateService): bool {
            return $command[0] === 'docker'
                && $command[1] === 'run'
                && in_array($candidateService['candidateContainerName'], $command, true)
                && in_array($candidateService['candidateNetworkAlias'], $command, true)
                && in_array('ghcr.io/acme/metrics-service:2.0.0', $command, true);
        }));

        $promoted = $service->promoteCandidate($plugin, $candidate);
        $this->assertSame('http://acme-bridge-metrics:9310', $promoted['runtime']['baseUrl']);
        $this->assertSame('wprint3d-plugin-acme-bridge-metrics-service', $promoted['images'][0]['service']['containerName']);
        $this->assertArrayNotHasKey('candidateContainerName', $promoted['images'][0]['service']);
        $this->assertArrayNotHasKey('canonicalContainerName', $promoted['images'][0]['service']);
        $this->assertNotSame($candidateService['configFingerprint'], $promoted['images'][0]['service']['configFingerprint']);
        $this->assertSame('running', $promoted['images'][0]['service']['status']);
        $this->assertContains(
            ['docker', 'network', 'disconnect', 'wprint3d_default', $candidateService['candidateContainerName']],
            $commands
        );
        $this->assertContains(
            ['docker', 'network', 'connect', '--alias', $candidateService['candidateNetworkAlias'], '--alias', 'acme-bridge-metrics', 'wprint3d_default', $candidateService['candidateContainerName']],
            $commands
        );
        $this->assertContains(
            ['docker', 'rename', $candidateService['candidateContainerName'], 'wprint3d-plugin-acme-bridge-metrics-service'],
            $commands
        );
    }

    public function test_it_replaces_a_running_managed_bridge_when_its_auth_token_changes(): void
    {
        $commands = [];
        $running = false;
        $installedFingerprint = '';
        config()->set('plugins.container.network', 'wprint3d_default');
        $service = new PluginDependencyService(
            commandRunner: function (array $command) use (&$commands, &$running, &$installedFingerprint) {
                $commands[] = $command;
                if ($command === ['docker', 'inspect', 'wprint3d-plugin-acme-bridge-metrics-service', '--format', '{{.State.Running}}']) {
                    return $running
                        ? ['successful' => true, 'output' => "true\n", 'errorOutput' => '']
                        : ['successful' => false, 'output' => '', 'errorOutput' => 'not found'];
                }
                if (str_contains(implode(' ', $command), 'Config.Labels')) {
                    return ['successful' => true, 'output' => $installedFingerprint."\n", 'errorOutput' => ''];
                }

                return ['successful' => true, 'output' => '', 'errorOutput' => ''];
            },
        );
        $plugin = [
            'id' => 'acme.bridge',
            'manifest' => [
                'sdkRevision' => 5,
                'runtime' => [
                    'type' => 'bridge',
                    'managedImageId' => 'metrics-service',
                    'auth' => ['mode' => 'wprint-bridge'],
                ],
                'images' => [[
                    'id' => 'metrics-service',
                    'image' => 'ghcr.io/acme/metrics-service:2.0.0',
                    'service' => ['port' => 9310, 'networkAlias' => 'acme-bridge-metrics'],
                ]],
            ],
        ];

        $initial = $service->activate($plugin, [
            'runtime' => ['authTokenCiphertext' => Crypt::encryptString('initial-token')],
        ]);
        $installedFingerprint = $initial['images'][0]['service']['configFingerprint'];
        $running = true;
        $rotatedState = $initial;
        $rotatedState['runtime']['authTokenCiphertext'] = Crypt::encryptString('rotated-token');

        $rotated = $service->activate($plugin, $rotatedState);

        $this->assertNotEmpty($rotated['images'][0]['service']['candidateContainerName']);
        $this->assertNotSame($installedFingerprint, $rotated['images'][0]['service']['configFingerprint']);
        $this->assertTrue(collect($commands)->contains(function (array $command): bool {
            return $command[0] === 'docker'
                && $command[1] === 'run'
                && collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'WPRINT_BRIDGE_AUTH_TOKEN='));
        }));
    }
}
