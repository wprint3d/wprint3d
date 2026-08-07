<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Container\PluginContainerClient;
use Tests\TestCase;

class PluginContainerClientTest extends TestCase
{
    public function test_it_keeps_container_commands_typed_and_timeout_scoped(): void
    {
        $seen = [];
        $client = new PluginContainerClient(
            cli: 'docker',
            runner: function (array $command, ?int $timeout) use (&$seen): array {
                $seen = [$command, $timeout];

                return ['successful' => true, 'output' => 'ok', 'errorOutput' => ''];
            },
        );

        $result = $client->runOrFail(['docker', 'pull', 'example/image@sha256:abc'], 'pull failed', 123);

        $this->assertSame('ok', $result['output']);
        $this->assertSame([['docker', 'pull', 'example/image@sha256:abc'], 123], $seen);
    }
}
