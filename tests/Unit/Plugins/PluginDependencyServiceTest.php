<?php

namespace Tests\Unit\Plugins;

use App\Plugins\PluginDependencyService;
use Tests\TestCase;

class PluginDependencyServiceTest extends TestCase
{
    public function test_it_classifies_plugins_and_warns_when_host_requirements_are_not_met(): void
    {
        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 1],
                'ram' => ['totalMegabytes' => 512],
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
            ],
        ]);

        $this->assertSame('heavyweight', $summary['classification']);
        $this->assertSame('This plugin is heavyweight and may require additional resources to run properly.', $summary['hint']);
        $this->assertFalse($summary['host']['meetsRequirements']);
        $this->assertSame(512, $summary['host']['memoryMb']);
        $this->assertSame(1.0, $summary['host']['cpuCores']);
        $this->assertCount(2, $summary['warnings']);
    }

    public function test_it_pulls_declared_images_and_runs_install_healthchecks(): void
    {
        $commands = [];

        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 4],
                'ram' => ['totalMegabytes' => 4096],
            ],
            commandRunner: function (array $command) use (&$commands) {
                $commands[] = $command;

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
                    'healthcheck' => [
                        'command' => ['php', '-v'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($state['images'][0]['pulled']);
        $this->assertTrue($state['images'][0]['healthcheck']['successful']);
        $this->assertSame(
            [
                ['podman', 'pull', 'ghcr.io/acme/metrics-service:1.2.3'],
                ['podman', 'run', '--rm', 'ghcr.io/acme/metrics-service:1.2.3', 'php', '-v'],
            ],
            $commands
        );
    }

    public function test_it_can_resolve_a_managed_bridge_service_runtime_url(): void
    {
        $commands = [];

        $service = new PluginDependencyService(
            hostMetrics: [
                'cpu' => ['cores' => 4],
                'ram' => ['totalMegabytes' => 4096],
            ],
            commandRunner: function (array $command) use (&$commands) {
                $commands[] = $command;

                if ($command === ['podman', 'inspect', 'wprint3d-backend-1', '--format', '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}']) {
                    return [
                        'successful' => true,
                        'output' => "wprint3d_default\n",
                        'errorOutput' => '',
                    ];
                }

                if ($command === ['podman', 'inspect', 'wprint3d-plugin-acme-bridge-metrics-service', '--format', '{{.State.Running}}']) {
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
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('http://acme-bridge-metrics:9310', $activation['runtime']['baseUrl']);
        $this->assertSame('running', $activation['images'][0]['service']['status']);
        $this->assertContains(
            ['podman', 'run', '-d', '--name', 'wprint3d-plugin-acme-bridge-metrics-service', '--restart', 'unless-stopped', '--label', 'wprint3d.plugin.id=acme.bridge', '--label', 'wprint3d.plugin.image_id=metrics-service', '--network', 'wprint3d_default', '--network-alias', 'acme-bridge-metrics', 'ghcr.io/acme/metrics-service:1.2.3'],
            $commands
        );
    }
}
