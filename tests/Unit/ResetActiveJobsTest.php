<?php

namespace Tests\Unit;

use App\Console\Commands\ResetActiveJobs;
use App\Models\Printer;
use App\Models\User;
use App\Services\PrintExecutionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResetActiveJobsTest extends TestCase
{
    public function test_handle_clears_stalled_active_jobs_and_marks_them_as_failed(): void
    {
        $stalledPrinter = new class
        {
            public string $_id = 'printer-stalled';

            public bool $hasActiveJob = true;

            public bool $lastJobHasFailed = false;

            public bool $saved = false;

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };

        $idlePrinter = new class
        {
            public string $_id = 'printer-idle';

            public bool $hasActiveJob = false;

            public bool $lastJobHasFailed = false;

            public bool $saved = false;

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }
        };

        $command = new class([$stalledPrinter, $idlePrinter]) extends ResetActiveJobs
        {
            public function __construct(
                private array $printers
            ) {
                parent::__construct();
            }

            protected function getPrinters(): iterable
            {
                return $this->printers;
            }
        };

        $result = $command->handle();

        $this->assertSame(Command::SUCCESS, $result);
        $this->assertFalse($stalledPrinter->hasActiveJob);
        $this->assertTrue($stalledPrinter->lastJobHasFailed);
        $this->assertTrue($stalledPrinter->saved);
        $this->assertFalse($idlePrinter->lastJobHasFailed);
        $this->assertFalse($idlePrinter->saved);
    }

    public function test_handle_preserves_an_active_job_with_a_valid_restart_checkpoint(): void
    {
        config(['cache.print_execution_store' => 'array']);
        Cache::store('array')->flush();

        $printer = new class extends Printer
        {
            public string $_id = 'printer-resumable';

            public bool $hasActiveJob = true;

            public bool $lastJobHasFailed = false;

            public string $activeFile = 'resumable.gcode';

            public mixed $activePrintExecution = null;

            public int $saveCount = 0;

            public function __construct() {}

            public function save(array $options = []): bool
            {
                $this->saveCount++;

                return true;
            }
        };
        $owner = new class extends User
        {
            public string $_id = 'resumable-owner';

            public function __construct() {}
        };
        app(PrintExecutionState::class)->begin($printer, $owner, 'resumable-uid', 'resumable-token');
        $saveCountBeforeReset = $printer->saveCount;
        $command = new class([$printer]) extends ResetActiveJobs
        {
            public function __construct(
                private array $printers
            ) {
                parent::__construct();
            }

            protected function getPrinters(): iterable
            {
                return $this->printers;
            }
        };

        $this->assertSame(Command::SUCCESS, $command->handle());
        $this->assertTrue($printer->hasActiveJob);
        $this->assertFalse($printer->lastJobHasFailed);
        $this->assertSame($saveCountBeforeReset, $printer->saveCount);
    }
}
