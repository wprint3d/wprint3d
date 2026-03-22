<?php

namespace Tests\Unit;

use App\Jobs\PrintGcode;
use App\Models\Printer;
use App\Plugins\PluginHookDispatcher;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PrintGcodeFailureStateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_failed_print_keeps_recovery_file_but_clears_active_job_state(): void
    {
        Mockery::mock('alias:App\Events\PrintJobFailed')
            ->shouldReceive('dispatch')
            ->once()
            ->with('printer-1');

        Mockery::mock('alias:App\Events\PrintJobFinished')
            ->shouldReceive('dispatch')
            ->once()
            ->with('printer-1');

        app()->instance(PluginHookDispatcher::class, new class
        {
            public array $calls = [];

            public function dispatch(string $hook, array $context = []): array
            {
                $this->calls[] = compact('hook', 'context');

                return [];
            }
        });

        $printer = new class extends Printer
        {
            public string $_id;

            public bool $hasActiveJob;

            public bool $lastJobHasFailed;

            public ?string $activeFile;

            public ?int $lastLine;

            public bool $saved = false;

            public function __construct() {}

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }

            public function setCurrentLine(int $line): bool
            {
                return true;
            }

            public function setMaxLine(int $line): bool
            {
                return true;
            }

            public function setLastCommand(?string $command): bool
            {
                return true;
            }

            public function resume(): bool
            {
                return true;
            }
        };

        $printer->_id = 'printer-1';
        $printer->hasActiveJob = true;
        $printer->lastJobHasFailed = false;
        $printer->activeFile = 'test_connectivity.gcode';
        $printer->lastLine = 39;

        $job = (new \ReflectionClass(PrintGcode::class))->newInstanceWithoutConstructor();

        \Closure::bind(function () use ($job, $printer) {
            $job->printer = $printer;
            $job->filePath = 'test_connectivity.gcode';
            $job->uid = 'job-uid';
            $job->shouldRecord = false;
            $job->recordableCameras = [];
        }, null, PrintGcode::class)();

        $job->failed(new RuntimeException('serial timeout'));

        $this->assertTrue($printer->saved);
        $this->assertTrue($printer->lastJobHasFailed);
        $this->assertFalse($printer->hasActiveJob);
        $this->assertSame('test_connectivity.gcode', $printer->activeFile);
        $this->assertSame(39, $printer->lastLine);
    }
}
