<?php

namespace Tests\Unit;

use App\Console\Commands\ResetActiveJobs;
use Illuminate\Console\Command;
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
}
