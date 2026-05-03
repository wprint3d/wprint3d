<?php

namespace Tests\Unit;

use App\Console\Services\Concurrent\PollSerialConnections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PollSerialConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'logging.channels.printers-poller' => [
                'driver' => 'single',
                'path' => sys_get_temp_dir().'/wprint3d-test-printers-poller.log',
            ],
        ]);
    }

    private function makePrinter(bool $connected = false): object
    {
        return new class($connected)
        {
            public string $_id;

            public string $node;

            public int $baudRate;

            public bool $connected;

            public mixed $activeFile = null;

            public bool $saved = false;

            public function __construct(bool $connected)
            {
                $this->_id = 'printer-1';
                $this->node = 'ACM0';
                $this->baudRate = 115200;
                $this->connected = $connected;
                $this->activeFile = null;
            }

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
    }

    public function test_successful_poll_marks_a_stale_printer_as_connected(): void
    {
        Event::fake();
        Cache::forget(config('cache.mapper_busy_key'));

        $printer = $this->makePrinter();

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

    public function test_missing_serial_node_marks_printer_as_disconnected(): void
    {
        Event::fake();
        Cache::forget(config('cache.mapper_busy_key'));

        $printer = $this->makePrinter(connected: true);

        $service = new class extends PollSerialConnections
        {
            public function poll(array $printers): void
            {
                $this->pollPrinters($printers, 0, 5, 7, []);
            }

            protected function serialNodeExists(?string $node): bool
            {
                return false;
            }
        };

        $service->poll([$printer]);

        $this->assertFalse($printer->connected);
        $this->assertTrue($printer->saved);
    }

    public function test_poll_failure_marks_printer_as_disconnected(): void
    {
        Event::fake();
        Cache::forget(config('cache.mapper_busy_key'));

        $printer = $this->makePrinter(connected: true);

        $serial = new class
        {
            public bool $closed = false;

            public function query(string $command): string
            {
                throw new \RuntimeException('serial timeout');
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

        $this->assertFalse($printer->connected);
        $this->assertTrue($printer->saved);
        $this->assertSame('serial timeout', $printer->getLastError());
        $this->assertTrue($serial->closed);
    }
}
