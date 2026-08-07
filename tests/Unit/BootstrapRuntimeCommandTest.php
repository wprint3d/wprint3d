<?php

namespace Tests\Unit;

use App\Console\Commands\BootstrapRuntime;
use Illuminate\Console\Command;
use Tests\TestCase;

class BootstrapRuntimeCommandTest extends TestCase
{
    public function test_bootstrap_runs_server_init_and_queue_maintenance_in_single_orchestration_pass(): void
    {
        $contextFile = tempnam(sys_get_temp_dir(), 'bootstrap-runtime-');

        $command = new class extends BootstrapRuntime
        {
            public array $calls = [];

            protected function callNestedCommand(string $command, array $parameters = []): int
            {
                $this->calls[] = [$command, $parameters];

                return Command::SUCCESS;
            }

            protected function currentMachineUuid(): string
            {
                return '';
            }

            protected function createMachineUuid(): string
            {
                return 'generated-machine-uuid';
            }

            protected function isDeveloperMode(): bool
            {
                return true;
            }

            protected function octaneEnabled(): bool
            {
                return true;
            }
        };

        $result = $command->bootstrap(true, true, $contextFile);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertSame([
            ['optimize:clear', []],
            ['create:sample-user', []],
            ['migrate', ['--force' => true]],
            ['make:marlin-labels', []],
            ['reset:active-jobs', []],
            ['make:default-configuration', []],
            ['make:compose-path-config', []],
            ['cache:clear', []],
            ['queue:flush', []],
            ['queue:restart', []],
        ], $command->calls);

        $context = file_get_contents($contextFile);

        $this->assertStringContainsString("MACHINE_UUID=generated-machine-uuid\n", $context);
        $this->assertStringContainsString("WPRINT3D_OCTANE_ENABLED=true\n", $context);

        @unlink($contextFile);
    }

    public function test_bootstrap_reuses_existing_machine_uuid_and_skips_developer_only_steps_in_production_mode(): void
    {
        $contextFile = tempnam(sys_get_temp_dir(), 'bootstrap-runtime-');

        $command = new class extends BootstrapRuntime
        {
            public array $calls = [];

            protected function callNestedCommand(string $command, array $parameters = []): int
            {
                $this->calls[] = [$command, $parameters];

                return Command::SUCCESS;
            }

            protected function currentMachineUuid(): string
            {
                return 'existing-machine-uuid';
            }

            protected function isDeveloperMode(): bool
            {
                return false;
            }

            protected function octaneEnabled(): bool
            {
                return false;
            }
        };

        $result = $command->bootstrap(true, false, $contextFile);

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertSame([
            ['optimize:clear', []],
            ['create:sample-user', []],
            ['migrate', ['--force' => true]],
            ['reset:active-jobs', []],
            ['make:default-configuration', []],
            ['make:compose-path-config', []],
        ], $command->calls);

        $context = file_get_contents($contextFile);

        $this->assertStringContainsString("MACHINE_UUID=existing-machine-uuid\n", $context);
        $this->assertStringContainsString("WPRINT3D_OCTANE_ENABLED=false\n", $context);

        @unlink($contextFile);
    }

    public function test_bootstrap_can_disable_runtime_reconciliation_without_disabling_builtin_installation(): void
    {
        config()->set('plugins.rollout.runtime_reconcile_enabled', false);

        $command = new class extends BootstrapRuntime
        {
            public array $calls = [];

            protected function callNestedCommand(string $command, array $parameters = []): int
            {
                $this->calls[] = [$command, $parameters];

                return Command::SUCCESS;
            }

            protected function currentMachineUuid(): string
            {
                return 'existing-machine-uuid';
            }

            protected function isDeveloperMode(): bool
            {
                return false;
            }

            protected function shouldBootstrapPluginRuntime(): bool
            {
                return true;
            }
        };

        $this->assertSame(Command::SUCCESS, $command->bootstrap(true, false));
        $this->assertContains(['plugin:install-builtins', []], $command->calls);
        $this->assertNotContains(['plugin:reconcile-runtime', []], $command->calls);
    }
}
