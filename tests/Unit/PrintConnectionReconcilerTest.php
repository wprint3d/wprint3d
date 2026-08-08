<?php

namespace Tests\Unit;

use App\Exceptions\PrintRecoveryRequiredException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Services\PrintConnectionReconciler;
use Tests\TestCase;

class PrintConnectionReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Configuration::query()->delete();
        Configuration::create(['key' => 'negotiationMaxRetries', 'value' => 0]);
        Configuration::create(['key' => 'negotiationTimeoutSecs', 'value' => 1]);
    }

    protected function tearDown(): void
    {
        Configuration::query()->delete();

        parent::tearDown();
    }

    public function test_matching_identity_position_and_heat_continue(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario();

        $outcome = (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);

        $this->assertSame('continue', $outcome);
        $this->assertSame(['M115', 'M400', 'M114', 'M105'], $serial->queries);
    }

    public function test_temperature_drop_over_ten_degrees_requires_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(temperature: 194.9);

        try {
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
            $this->fail('A material temperature drop must require recovery.');
        } catch (PrintRecoveryRequiredException $exception) {
            $this->assertTrue($exception->identityValidated);
        }
    }

    public function test_exactly_ten_degrees_of_temperature_drop_is_allowed(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(temperature: 195.0);

        $this->assertSame(
            'continue',
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint)
        );
    }

    public function test_pending_movement_is_classified_as_executed_or_not_executed(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(positionX: 11.0);
        $checkpoint['pending'] = [
            'command' => 'G1 X11',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0],
            ]),
            'classification' => 'observable',
        ];

        $this->assertSame(
            'executed',
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint)
        );

        [$printer, $serial] = $this->scenario(positionX: 10.0);

        $this->assertSame(
            'resend',
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint)
        );
    }

    public function test_unobservable_pending_command_requires_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario();
        $checkpoint['pending'] = [
            'command' => 'M600',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => $checkpoint['state'],
            'classification' => 'ambiguous',
        ];

        $this->expectException(PrintRecoveryRequiredException::class);

        (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
    }

    private function scenario(float $positionX = 10.0, float $temperature = 205.0): array
    {
        $printer = new class extends Printer
        {
            public string $_id = 'reconcile-printer';

            public array $machine = ['uuid' => 'FAKE-UUID/canonical-suffix'];

            public array $statistics = [];

            public function __construct() {}

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
        };
        $serial = new class($positionX, $temperature) extends Serial
        {
            public array $queries = [];

            public function __construct(
                private readonly float $positionX,
                private readonly float $temperature
            ) {}

            public function query(
                ?string $command = null,
                ?int $lineNumber = null,
                ?int $maxLine = null,
                ?int $timeout = null
            ): string {
                $this->queries[] = $command;

                return match ($command) {
                    'M115' => 'FIRMWARE_NAME:Fake UUID:FAKE-UUID ok',
                    'M114' => sprintf('X:%.2f Y:20.00 Z:0.20 E:4.00 ok', $this->positionX),
                    'M105' => sprintf('ok T:%.2f /210.00 B:60.00 /60.00', $this->temperature),
                    default => 'ok',
                };
            }
        };
        $checkpoint = [
            'state' => [
                'position' => ['x' => 10.0, 'y' => 20.0, 'z' => 0.2, 'e' => 4.0],
                'modal' => ['movement' => 'G90', 'extruder' => 'M82', 'units' => 'G21', 'tool' => 0],
                'thermal' => [
                    'extruders' => [0 => ['temperature' => 205.0, 'target' => 210.0]],
                    'bed' => ['temperature' => 60.0, 'target' => 60.0],
                ],
            ],
            'pending' => null,
        ];

        return [$printer, $serial, $checkpoint];
    }
}
