<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Support\HostMetricsReader;
use PHPUnit\Framework\TestCase;

class HostMetricsReaderTest extends TestCase
{
    public function test_it_calculates_memory_usage_percentage_from_meminfo(): void
    {
        $reader = new HostMetricsReader;

        $metrics = $reader->fromSnapshots(
            statBefore: 'cpu  100 20 50 200 10 0 0 0 0 0',
            statAfter: 'cpu  140 30 70 240 10 0 0 0 0 0',
            memInfo: implode(PHP_EOL, [
                'MemTotal:       1000000 kB',
                'MemAvailable:    250000 kB',
            ]),
        );

        $this->assertSame(63.6, $metrics['cpu']['usedPercentage']);
        $this->assertSame(75.0, $metrics['ram']['usedPercentage']);
    }
}
