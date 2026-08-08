<?php

namespace Tests\Unit;

use App\Exceptions\TimedOutException;
use App\Jobs\PrintGcode;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;
use App\Services\PrintExecutionState;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrintGcodeConnectionRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.print_execution_store' => 'array']);
        Cache::store('array')->flush();
        Configuration::query()->delete();
        Configuration::create(['key' => 'negotiationMaxRetries', 'value' => 0]);
        Configuration::create(['key' => 'negotiationTimeoutSecs', 'value' => 1]);
    }

    protected function tearDown(): void
    {
        Configuration::query()->delete();

        parent::tearDown();
    }

    public function test_lost_ack_for_an_executed_movement_is_renegotiated_without_resending(): void
    {
        [$job, $serial, $executionState, $printer] = $this->scenario(commandWasExecuted: true);

        $response = $this->querySourceCommand($job, $serial, 'G1 X11');
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame('ok', $response);
        $this->assertSame(1, $serial->sourceAttempts);
        $this->assertSame(1, $checkpoint['sourceCommandIndex']);
        $this->assertSame(11.0, $checkpoint['state']['position']['x']);
        $this->assertTrue($printer->hasActiveJob);
    }

    public function test_lost_ack_for_an_unexecuted_movement_is_safely_resent(): void
    {
        [$job, $serial, $executionState, $printer] = $this->scenario(commandWasExecuted: false);

        $response = $this->querySourceCommand($job, $serial, 'G1 X11');
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame('ok', $response);
        $this->assertSame(2, $serial->sourceAttempts);
        $this->assertSame(1, $checkpoint['sourceCommandIndex']);
        $this->assertSame(11.0, $checkpoint['state']['position']['x']);
        $this->assertTrue($printer->hasActiveJob);
    }

    private function querySourceCommand(PrintGcode $job, Serial &$serial, string $command): string
    {
        return \Closure::bind(
            fn (): string => $job->queryPrintCommand(
                $serial,
                $command,
                sourceCommand: true,
                lineNumber: 1,
                maxLine: 2
            ),
            null,
            PrintGcode::class
        )();
    }

    private function scenario(bool $commandWasExecuted): array
    {
        $printer = new class extends Printer
        {
            public string $_id = 'print-timeout-printer';

            public bool $hasActiveJob = true;

            public array $machine = ['uuid' => 'FAKE-UUID/canonical-suffix'];

            public mixed $activePrintExecution = null;

            public array $statistics = [
                'extruders' => [0 => ['temperature' => 205.0, 'target' => 210.0]],
                'bed' => ['temperature' => 60.0, 'target' => 60.0],
            ];

            public function __construct() {}

            public function save(array $options = []): bool
            {
                return true;
            }

            public function setStatistics(string $lines, int $extruderIndex): bool
            {
                preg_match('/T:([\d.]+) \/([\d.]+) B:([\d.]+) \/([\d.]+)/', $lines, $matches);
                $this->statistics['extruders'][$extruderIndex] = [
                    'temperature' => (float) $matches[1],
                    'target' => (float) $matches[2],
                ];
                $this->statistics['bed'] = [
                    'temperature' => (float) $matches[3],
                    'target' => (float) $matches[4],
                ];

                return true;
            }

            public function getStatistics(): array
            {
                return $this->statistics;
            }

            public function setCurrentLine(int $line): bool
            {
                return true;
            }

            public function setAbsolutePosition(?float $x, ?float $y, ?float $z, ?float $e): bool
            {
                return true;
            }
        };
        $owner = new class extends User
        {
            public string $_id = 'print-timeout-owner';

            public function __construct() {}
        };
        $executionState = app(PrintExecutionState::class);
        $executionState->begin($printer, $owner, 'print-timeout-uid', 'print-timeout-token');
        $executionState->markReady(
            $printer->_id,
            'print-timeout-token',
            ['x' => 10, 'y' => 20, 'z' => 0.2, 'e' => 4],
            $printer->statistics
        );

        $serial = new class($commandWasExecuted) extends Serial
        {
            public int $sourceAttempts = 0;

            private float $positionX = 10.0;

            public function __construct(
                private readonly bool $commandWasExecuted
            ) {}

            public function query(
                ?string $command = null,
                ?int $lineNumber = null,
                ?int $maxLine = null,
                ?int $timeout = null
            ): string {
                if ($command === 'G1 X11') {
                    $this->sourceAttempts++;

                    if ($this->sourceAttempts === 1) {
                        if ($this->commandWasExecuted) {
                            $this->positionX = 11.0;
                        }

                        throw new TimedOutException('The acknowledgement was lost.');
                    }

                    $this->positionX = 11.0;

                    return 'ok';
                }

                return match ($command) {
                    'M115' => 'FIRMWARE_NAME:Fake UUID:FAKE-UUID ok',
                    'M114' => sprintf('X:%.2f Y:20.00 Z:0.20 E:4.00 ok', $this->positionX),
                    'M105' => 'ok T:205.00 /210.00 B:60.00 /60.00',
                    default => 'ok',
                };
            }
        };
        $job = (new \ReflectionClass(PrintGcode::class))->newInstanceWithoutConstructor();

        \Closure::bind(function () use ($job, $printer) {
            $job->printer = $printer;
            $job->executionToken = 'print-timeout-token';
            $job->lineNumber = 1;
            $job->lineNumberCount = 2;
            $job->lastMovementMode = 'G90';
        }, null, PrintGcode::class)();

        return [$job, $serial, $executionState, $printer];
    }
}
