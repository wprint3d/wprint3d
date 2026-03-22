<?php

namespace Tests\Unit;

use App\Console\Services\Concurrent\PollSerialConnections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PollSerialConnectionsTest extends TestCase
{
    public function test_successful_poll_marks_a_stale_printer_as_connected(): void
    {
        Event::fake();
        Cache::forget(config('cache.mapper_busy_key'));

        $printer = new class
        {
            public string $_id;

            public string $node;

            public int $baudRate;

            public bool $connected;

            public mixed $activeFile = null;

            public bool $saved = false;

            private ?int $lastSeenValue = null;

            private ?string $lastErrorValue = null;

            private array $statisticsValue = [
                'extruders' => [
                    0 => [],
                ],
            ];

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }

            public function getLastSeen()
            {
                return $this->lastSeenValue;
            }

            public function updateLastSeen(): int
            {
                $this->lastSeenValue = time();

                return $this->lastSeenValue;
            }

            public function setStatistics(string $lines, int $extruderIndex): bool
            {
                $this->statisticsValue['extruders'][$extruderIndex] = [
                    'raw' => $lines,
                ];

                return true;
            }

            public function getStatistics(): array
            {
                return $this->statisticsValue;
            }

            public function setLastError(string $message): bool
            {
                $this->lastErrorValue = $message;

                return true;
            }

            public function getLastError(): ?string
            {
                return $this->lastErrorValue;
            }
        };

        $printer->_id = 'printer-1';
        $printer->node = 'ACM0';
        $printer->baudRate = 115200;
        $printer->connected = false;
        $printer->activeFile = null;

        $serial = new class
        {
            public array $queries = [];

            public bool $closed = false;

            public function query(string $command): string
            {
                $this->queries[] = $command;

                return 'ok T:27.89 /0.00 B:25.54 /0.00';
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };

        $service = new class($serial) extends PollSerialConnections
        {
            public function __construct(
                private object $serial
            ) {}

            public function poll(array $printers): void
            {
                $this->pollPrinters($printers, 0, 5, 7, []);
            }

            protected function serialNodeExists(?string $node): bool
            {
                return true;
            }

            protected function makeSerialConnection($printer, int $commandTimeoutSecs, array $serialPluginHooks)
            {
                return $this->serial;
            }
        };

        $service->poll([$printer]);

        $this->assertTrue($printer->connected);
        $this->assertTrue($printer->saved);
        $this->assertNotNull($printer->getLastSeen());
        $this->assertNull($printer->getLastError());
        $this->assertSame(['M105'], $serial->queries);
        $this->assertTrue($serial->closed);
    }
}
