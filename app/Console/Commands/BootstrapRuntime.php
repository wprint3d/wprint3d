<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class BootstrapRuntime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:bootstrap-runtime
                            { --server : Run the server startup sequence }
                            { --queue-maintenance : Clear caches and restart queue workers }
                            { --context-file= : Write shell-compatible startup values to a file }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run startup runtime tasks without repeatedly rebooting Artisan.';

    public function handle(): int
    {
        return $this->bootstrap(
            (bool) $this->option('server'),
            (bool) $this->option('queue-maintenance'),
            $this->option('context-file') ?: null
        );
    }

    public function bootstrap(bool $server, bool $queueMaintenance, ?string $contextFile = null): int
    {
        $machineUuid = null;

        if ($server) {
            $this->logInfo('Flushing cached files...');

            if ($this->runNestedCommand('optimize:clear') === null) {
                return Command::FAILURE;
            }

            $machineUuid = trim((string) $this->runNestedCommand('get:machine-uuid'));

            if ($machineUuid === '') {
                $machineUuid = trim((string) $this->runNestedCommand('make:machine-uuid'));

                if ($machineUuid === '') {
                    $this->logError('Failed to generate the machine UUID.');

                    return Command::FAILURE;
                }

                $this->logInfo("A machine UUID was generated: {$machineUuid}");
            } else {
                $this->logInfo("Machine UUID loaded: {$machineUuid}");
            }

            $steps = [
                ['Creating the sample user (if it doesn\'t exist)...', 'create:sample-user', []],
                ['Running migrations...', 'migrate', ['--force' => true]],
                ['Resetting stalled jobs...', 'reset:active-jobs', []],
                ['Declare default configurations...', 'make:default-configuration', []],
                ['Declare the Docker Compose directory...', 'make:compose-path-config', []],
            ];

            if ($this->isDeveloperMode()) {
                array_splice($steps, 2, 0, [[
                    'Generating Marlin labels...',
                    'make:marlin-labels',
                    [],
                ]]);
            }

            foreach ($steps as [$message, $command, $parameters]) {
                $this->logInfo($message);

                if ($this->runNestedCommand($command, $parameters) === null) {
                    return Command::FAILURE;
                }
            }
        }

        if ($queueMaintenance) {
            $steps = [
                ['Clearing application cache...', 'cache:clear', []],
                ['Flushing queues...', 'queue:flush', []],
                ['Restarting queue workers...', 'queue:restart', []],
            ];

            foreach ($steps as [$message, $command, $parameters]) {
                $this->logInfo($message);

                if ($this->runNestedCommand($command, $parameters) === null) {
                    return Command::FAILURE;
                }
            }
        }

        if ($contextFile !== null && $contextFile !== '') {
            $this->writeContextFile($contextFile, [
                'MACHINE_UUID' => $machineUuid,
                'WPRINT3D_OCTANE_ENABLED' => $this->octaneEnabled() ? 'true' : 'false',
            ]);
        }

        return Command::SUCCESS;
    }

    protected function callNestedCommand(string $command, array $parameters = []): int
    {
        return Artisan::call($command, $parameters);
    }

    protected function nestedCommandOutput(): string
    {
        return Artisan::output();
    }

    protected function isDeveloperMode(): bool
    {
        return filter_var(env('DEVELOPER_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function octaneEnabled(): bool
    {
        return filter_var(env('OCTANE_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function writeContextFile(string $path, array $context): void
    {
        $lines = [];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = "{$key}={$value}";
        }

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    private function runNestedCommand(string $command, array $parameters = []): ?string
    {
        $exitCode = $this->callNestedCommand($command, $parameters);
        $output = $this->nestedCommandOutput();

        if ($output !== '') {
            $this->writeNestedOutput($output);
        }

        if ($exitCode !== Command::SUCCESS) {
            $this->logError("The {$command} command failed with exit code {$exitCode}.");

            return null;
        }

        return $output;
    }

    private function logInfo(string $message): void
    {
        if ($this->output === null) {
            return;
        }

        $this->info($message);
    }

    private function logError(string $message): void
    {
        if ($this->output === null) {
            return;
        }

        $this->error($message);
    }

    private function writeNestedOutput(string $output): void
    {
        if ($this->output === null) {
            return;
        }

        $this->output->write($output);

        if (! str_ends_with($output, PHP_EOL)) {
            $this->newLine();
        }
    }
}
