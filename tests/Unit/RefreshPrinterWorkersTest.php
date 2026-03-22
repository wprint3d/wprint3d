<?php

namespace Tests\Unit;

use App\Console\Services\Concurrent\RefreshPrinterWorkers;
use Tests\TestCase;

class RefreshPrinterWorkersTest extends TestCase
{
    public function test_refresh_cycle_generates_scalable_and_per_printer_workers_in_the_same_pass(): void
    {
        $service = new class extends RefreshPrinterWorkers
        {
            public bool $scalableWorkersWereChecked = false;

            public bool $perPrinterWorkersWereChecked = false;

            public function refreshOnce(array $queues, int $sleepSecs): bool
            {
                return $this->refreshWorkers($queues, $sleepSecs);
            }

            protected function createScalableWorkers(array $queues, int $sleepSecs): bool
            {
                $this->scalableWorkersWereChecked = true;

                return true;
            }

            protected function createPerPrinterWorkers(array $queues, int $sleepSecs): bool
            {
                $this->perPrinterWorkersWereChecked = true;

                return true;
            }
        };

        $didRefreshWorkers = $service->refreshOnce(['default:1', 'prints'], 5);

        $this->assertTrue($didRefreshWorkers);
        $this->assertTrue($service->scalableWorkersWereChecked);
        $this->assertTrue($service->perPrinterWorkersWereChecked);
    }
}
