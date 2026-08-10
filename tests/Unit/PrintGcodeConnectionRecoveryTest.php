<?php

namespace Tests\Unit;

use App\Events\PrinterConnectionStatusUpdated;
use App\Exceptions\TimedOutException;
use App\Jobs\PrintGcode;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;
use App\Services\PrintExecutionState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
        Event::fake([PrinterConnectionStatusUpdated::class]);

        [$job, $serial, $executionState, $printer] = $this->scenario(commandWasExecuted: true);

        $response = $this->querySourceCommand($job, $serial, 'G1 X11');
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame('ok', $response);
        $this->assertSame(1, $serial->sourceAttempts);
        $this->assertSame(1, $checkpoint['sourceCommandIndex']);
        $this->assertSame(11.0, $checkpoint['state']['position']['x']);
        $this->assertTrue($printer->hasActiveJob);
        $this->assertFalse($executionState->isReconnecting($printer->_id));
        Event::assertDispatchedTimes(PrinterConnectionStatusUpdated::class, 2);
        Event::assertDispatched(
            PrinterConnectionStatusUpdated::class,
            fn (PrinterConnectionStatusUpdated $event): bool => $event->isReconnecting
        );
        Event::assertDispatched(
            PrinterConnectionStatusUpdated::class,
            fn (PrinterConnectionStatusUpdated $event): bool => ! $event->isReconnecting
        );
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

    public function test_it_moves_the_active_connection_to_a_remapped_node_when_the_fingerprint_matches(): void
    {
        [$job, $serial, $executionState, $printer] = $this->remappedNodeScenario(
            'FAKE-UUID/canonical-suffix'
        );

        $response = $this->querySourceCommand(
            $job,
            $serial,
            'G0 F9000 X43.431 Y50.772'
        );
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame('ok', $response);
        $this->assertTrue($serial->wasClosed);
        $this->assertSame(['USB1'], $job->openedNodes);
        $this->assertSame(1, $checkpoint['sourceCommandIndex']);
        $this->assertSame(43.431, $checkpoint['state']['position']['x']);
        $this->assertSame(50.772, $checkpoint['state']['position']['y']);
    }

    public function test_it_uses_a_fresh_firmware_clamped_target_during_node_remapping(): void
    {
        [$job, $serial, $executionState, $printer] = $this->remappedNodeScenario(
            'FAKE-UUID/canonical-suffix'
        );

        $this->querySourceCommand($job, $serial, 'M140 S80');
        $requestedCheckpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame(80.0, $requestedCheckpoint['state']['thermal']['bed']['target']);
        $this->assertTrue(
            $requestedCheckpoint['state']['thermal']['bed']['targetConfirmationPending']
        );

        $this->queryRuntimeCommand($job, $serial, 'M105');
        $confirmedCheckpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame(70.0, $confirmedCheckpoint['state']['thermal']['bed']['target']);
        $this->assertSame(80.0, $confirmedCheckpoint['state']['thermal']['bed']['requestedTarget']);
        $this->assertFalse(
            $confirmedCheckpoint['state']['thermal']['bed']['targetConfirmationPending']
        );

        $response = $this->querySourceCommand(
            $job,
            $serial,
            'G0 F9000 X43.431 Y50.772'
        );
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame('ok', $response);
        $this->assertTrue($serial->wasClosed);
        $this->assertSame(['USB1'], $job->openedNodes);
        $this->assertSame(2, $checkpoint['sourceCommandIndex']);
        $this->assertSame(70.0, $checkpoint['state']['thermal']['bed']['target']);
        $this->assertSame(80.0, $checkpoint['state']['thermal']['bed']['requestedTarget']);
    }

    public function test_it_accepts_a_safe_firmware_clamp_during_remapping_before_the_next_poll(): void
    {
        [$job, $serial, $executionState, $printer] = $this->remappedNodeScenario(
            'FAKE-UUID/canonical-suffix'
        );

        $this->querySourceCommand($job, $serial, 'M140 S80');
        $requestedCheckpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame(80.0, $requestedCheckpoint['state']['thermal']['bed']['target']);
        $this->assertTrue(
            $requestedCheckpoint['state']['thermal']['bed']['targetConfirmationPending']
        );

        $response = $this->querySourceCommand(
            $job,
            $serial,
            'G0 F9000 X43.431 Y50.772'
        );
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame('ok', $response);
        $this->assertSame(70.0, $checkpoint['state']['thermal']['bed']['target']);
        $this->assertSame(80.0, $checkpoint['state']['thermal']['bed']['requestedTarget']);
        $this->assertFalse(
            $checkpoint['state']['thermal']['bed']['targetConfirmationPending']
        );
    }

    public function test_auto_report_on_a_subsequent_source_command_confirms_a_firmware_clamp(): void
    {
        [$job, $serial, $executionState, $printer] = $this->remappedNodeScenario(
            'FAKE-UUID/canonical-suffix',
            sourceResponses: [
                'M106 S85' => 'ok T:230.00 /230.00 B:70.00 /70.00',
            ]
        );

        $this->querySourceCommand($job, $serial, 'M140 S80');
        $requestedCheckpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame(80.0, $requestedCheckpoint['state']['thermal']['bed']['target']);
        $this->assertTrue(
            $requestedCheckpoint['state']['thermal']['bed']['targetConfirmationPending']
        );

        $this->querySourceCommand($job, $serial, 'M106 S85');
        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertSame(70.0, $checkpoint['state']['thermal']['bed']['target']);
        $this->assertSame(80.0, $checkpoint['state']['thermal']['bed']['requestedTarget']);
        $this->assertFalse(
            $checkpoint['state']['thermal']['bed']['targetConfirmationPending']
        );
    }

    public function test_it_rejects_a_remapped_node_when_the_fingerprint_changes(): void
    {
        [$job, $serial, $executionState, $printer] = $this->remappedNodeScenario(
            'OTHER-UUID/other-suffix'
        );

        try {
            $this->querySourceCommand($job, $serial, 'G0 F9000 X43.431 Y50.772');
            $this->fail('A different physical printer must not inherit the active print.');
        } catch (\App\Exceptions\PrintRecoveryRequiredException $exception) {
            $this->assertStringContainsString('fingerprint', $exception->getMessage());
        }

        $checkpoint = $executionState->checkpoint($printer->_id);

        $this->assertFalse($serial->wasClosed);
        $this->assertSame([], $job->openedNodes);
        $this->assertSame(0, $checkpoint['sourceCommandIndex']);
        $this->assertSame(43.304, $checkpoint['state']['position']['x']);
        $this->assertSame(50.372, $checkpoint['state']['position']['y']);
    }

    private function querySourceCommand(PrintGcode $job, Serial &$serial, string $command): string
    {
        return $this->queryCommand($job, $serial, $command, true);
    }

    private function queryRuntimeCommand(PrintGcode $job, Serial &$serial, string $command): string
    {
        return $this->queryCommand($job, $serial, $command, false);
    }

    private function queryCommand(
        PrintGcode $job,
        Serial &$serial,
        string $command,
        bool $sourceCommand
    ): string {
        return \Closure::bind(
            fn (): string => $job->queryPrintCommand(
                $serial,
                $command,
                sourceCommand: $sourceCommand,
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

            public string $node = 'USB0';

            public int $baudRate = 115200;

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

            public function refresh()
            {
                return $this;
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
            $job->serialNode = 'USB0';
            $job->serialBaudRate = 115200;
        }, null, PrintGcode::class)();

        return [$job, $serial, $executionState, $printer];
    }

    private function remappedNodeScenario(
        string $mappedFingerprint,
        string $heaterResponse = 'ok',
        array $sourceResponses = []
    ): array {
        $printer = new class($mappedFingerprint) extends Printer
        {
            public string $_id = 'remapped-node-printer';

            public bool $hasActiveJob = true;

            public string $node = 'USB0';

            public int $baudRate = 115200;

            public array $machine = ['uuid' => 'FAKE-UUID/canonical-suffix'];

            public mixed $activePrintExecution = null;

            public array $statistics = [
                'extruders' => [0 => ['temperature' => 229.77, 'target' => 230.0]],
                'bed' => ['temperature' => 70.0, 'target' => 70.0],
            ];

            public function __construct(private readonly string $mappedFingerprint) {}

            public function save(array $options = []): bool
            {
                return true;
            }

            public function refresh()
            {
                $this->node = 'USB1';
                $this->machine = ['uuid' => $this->mappedFingerprint];

                return $this;
            }

            public function setStatistics(string $lines, int $extruderIndex): bool
            {
                $this->statistics['extruders'][$extruderIndex] = [
                    'temperature' => 230.0,
                    'target' => 230.0,
                ];
                $this->statistics['bed'] = [
                    'temperature' => 70.01,
                    'target' => 70.0,
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
            public string $_id = 'remapped-node-owner';

            public function __construct() {}
        };
        $executionState = app(PrintExecutionState::class);
        $executionState->begin($printer, $owner, 'remapped-node-uid', 'remapped-node-token');
        $executionState->markReady(
            $printer->_id,
            'remapped-node-token',
            ['x' => 43.304, 'y' => 50.372, 'z' => 1.2, 'e' => 79.4985],
            $printer->statistics
        );

        $serial = new class($heaterResponse, $sourceResponses) extends Serial
        {
            public bool $wasClosed = false;

            public function __construct(
                private readonly string $heaterResponse,
                private readonly array $sourceResponses
            ) {}

            public function query(
                ?string $command = null,
                ?int $lineNumber = null,
                ?int $maxLine = null,
                ?int $timeout = null
            ): string {
                if ($command === 'M140 S80') {
                    return $this->heaterResponse;
                }

                if ($command === 'M105') {
                    return 'ok T:230.00 /230.00 B:70.00 /70.00';
                }

                if (isset($this->sourceResponses[$command])) {
                    return $this->sourceResponses[$command];
                }

                throw new TimedOutException('The original serial node disappeared.');
            }

            public function close(): void
            {
                $this->wasClosed = true;
            }
        };
        $replacementSerial = new class extends Serial
        {
            public function __construct() {}

            public function query(
                ?string $command = null,
                ?int $lineNumber = null,
                ?int $maxLine = null,
                ?int $timeout = null
            ): string {
                return match ($command) {
                    'M115' => 'FIRMWARE_NAME:Fake UUID:FAKE-UUID ok',
                    'M114' => 'X:43.43 Y:50.77 Z:1.20 E:79.50 ok',
                    'M105' => 'ok T:230.00 /230.00 B:70.01 /70.00',
                    default => 'ok',
                };
            }
        };
        $job = new class($replacementSerial) extends PrintGcode
        {
            public array $openedNodes = [];

            public function __construct(private readonly Serial $replacementSerial) {}

            protected function createSerialConnection(string $node, int $baudRate): Serial
            {
                $this->openedNodes[] = $node;

                return $this->replacementSerial;
            }
        };

        \Closure::bind(function () use ($job, $printer) {
            $job->printer = $printer;
            $job->executionToken = 'remapped-node-token';
            $job->lineNumber = 1;
            $job->lineNumberCount = 2;
            $job->lastMovementMode = 'G90';
            $job->serialNode = 'USB0';
            $job->serialBaudRate = 115200;
        }, null, PrintGcode::class)();

        // The main print loop refreshes the model periodically, so it may already
        // expose the remapped node while the current Serial still owns USB0.
        $printer->refresh();

        return [$job, $serial, $executionState, $printer];
    }
}
