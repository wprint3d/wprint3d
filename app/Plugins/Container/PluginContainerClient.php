<?php

namespace App\Plugins\Container;

use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\Process;

/**
 * Small typed boundary around the host container CLI.
 *
 * Keeping command execution here makes lifecycle operations deterministic and
 * testable without granting plugin code direct access to Docker.
 */
class PluginContainerClient
{
    /**
     * @param  callable(array<int, string>, int|null): array{successful: bool, output?: string, errorOutput?: string}|null  $runner
     */
    public function __construct(
        private readonly string $cli = 'docker',
        private $runner = null,
        private readonly int $defaultTimeoutSeconds = 60,
    ) {
        $this->runner ??= function (array $command, ?int $timeout): array {
            $result = Process::timeout($timeout ?? $this->defaultTimeoutSeconds)->run($command);

            return [
                'successful' => $result->successful(),
                'output' => $result->output(),
                'errorOutput' => $result->errorOutput(),
            ];
        };
    }

    /** @return array{successful: bool, output?: string, errorOutput?: string} */
    public function run(array $command, ?int $timeoutSeconds = null): array
    {
        return ($this->runner)($command, $timeoutSeconds);
    }

    /** @return array{successful: bool, output?: string, errorOutput?: string} */
    public function runOrFail(array $command, string $message, ?int $timeoutSeconds = null): array
    {
        $result = $this->run($command, $timeoutSeconds);

        if (! ($result['successful'] ?? false)) {
            $errorOutput = trim((string) ($result['errorOutput'] ?? ''));
            throw new PluginRuntimeException($message.($errorOutput !== '' ? " {$errorOutput}" : ''));
        }

        return $result;
    }

    public function cli(): string
    {
        return $this->cli;
    }
}
