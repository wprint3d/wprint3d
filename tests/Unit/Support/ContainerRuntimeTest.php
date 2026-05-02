<?php

namespace Tests\Unit\Support;

use App\Support\ContainerRuntime;
use PHPUnit\Framework\TestCase;

class ContainerRuntimeTest extends TestCase
{
    public function test_it_uses_docker_defaults_when_configuration_is_missing(): void
    {
        $runtime = ContainerRuntime::fromConfig([]);

        $this->assertSame('docker', $runtime->containerCli());
        $this->assertSame(['docker-compose', 'pull'], $runtime->composeCommand(['pull']));
        $this->assertSame(['docker', 'image', 'inspect', 'wprint3d/wprint3d:latest'], $runtime->imageInspectCommand('wprint3d/wprint3d:latest'));
        $this->assertNull($runtime->composeDir());
    }

    public function test_it_parses_custom_runtime_configuration(): void
    {
        $runtime = ContainerRuntime::fromConfig([
            'compose_dir' => '/srv/wprint3d',
            'container_cli' => 'docker',
            'compose_command' => 'docker compose',
        ]);

        $this->assertSame('docker', $runtime->containerCli());
        $this->assertSame('/srv/wprint3d', $runtime->composeDir());
        $this->assertSame(['docker', 'compose', 'up', '-d'], $runtime->composeCommand(['up', '-d']));
        $this->assertSame(['docker', 'image', 'inspect', 'redis:7.2.5'], $runtime->imageInspectCommand('redis:7.2.5'));
    }

    public function test_it_falls_back_when_custom_values_are_blank(): void
    {
        $runtime = ContainerRuntime::fromConfig([
            'container_cli' => '   ',
            'compose_command' => '',
        ]);

        $this->assertSame('docker', $runtime->containerCli());
        $this->assertSame(['docker-compose', 'down'], $runtime->composeCommand(['down']));
    }
}
