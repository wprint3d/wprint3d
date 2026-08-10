<?php

namespace Tests\Unit;

use App\Exceptions\PrintRecoveryRequiredException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Services\PrintConnectionReconciler;
use Illuminate\Log\Logger;
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
            $this->assertStringContainsString('cooled below', $exception->getMessage());
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

    public function test_pending_safe_firmware_clamp_does_not_block_executed_movement(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(
            positionX: 11.0,
            bedTemperature: 70.0,
            bedTarget: 70.0
        );
        $checkpoint['state']['thermal']['bed'] = [
            'temperature' => 70.0,
            'target' => 80.0,
            'requestedTarget' => 80.0,
            'targetConfirmationPending' => true,
        ];
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
    }

    public function test_pending_unsafe_firmware_target_reduction_still_requires_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(
            positionX: 11.0,
            bedTemperature: 70.0,
            bedTarget: 65.0
        );
        $checkpoint['state']['thermal']['bed'] = [
            'temperature' => 70.0,
            'target' => 80.0,
            'requestedTarget' => 80.0,
            'targetConfirmationPending' => true,
        ];
        $checkpoint['pending'] = [
            'command' => 'G1 X11',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0],
            ]),
            'classification' => 'observable',
        ];

        $this->expectException(PrintRecoveryRequiredException::class);
        $this->expectExceptionMessage('bed target changed unexpectedly');

        (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
    }

    public function test_pending_heater_target_is_classified_as_executed_or_not_executed(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(target: 215.0);
        $checkpoint['pending'] = [
            'command' => 'M104 S215',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'thermal' => [
                    'extruders' => [0 => ['target' => 215.0]],
                ],
            ]),
            'classification' => 'observable',
        ];

        $this->assertSame(
            'executed',
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint)
        );

        [$printer, $serial] = $this->scenario(target: 210.0);

        $this->assertSame(
            'resend',
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint)
        );
    }

    public function test_rpi_reconnect_rounding_and_missing_cached_temperature_confirm_executed_travel(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(
            positionX: 43.43,
            temperature: 230.0,
            positionY: 50.77,
            positionZ: 1.2,
            positionE: 79.50,
            target: 230.0,
            bedTemperature: 70.01,
            bedTarget: 70.0
        );
        $checkpoint['state']['position'] = [
            'x' => 43.304,
            'y' => 50.372,
            'z' => 1.2,
            'e' => 79.4985,
        ];
        $checkpoint['state']['thermal'] = [
            'extruders' => [0 => ['temperature' => null, 'target' => 230.0]],
            'bed' => ['temperature' => 70.0, 'target' => 70.0],
        ];
        $checkpoint['pending'] = [
            'command' => 'G0 F9000 X43.431 Y50.772',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 43.431, 'y' => 50.772],
            ]),
            'classification' => 'observable',
        ];

        $this->assertSame(
            'executed',
            (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint)
        );
    }

    public function test_hybrid_multi_axis_outcome_still_requires_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(positionX: 11.0, positionY: 20.0);
        $checkpoint['pending'] = [
            'command' => 'G0 X11 Y21',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0, 'y' => 21.0],
            ]),
            'classification' => 'observable',
        ];

        $this->expectException(PrintRecoveryRequiredException::class);
        $this->expectExceptionMessage('ambiguous physical outcome');

        (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
    }

    public function test_executed_position_with_cooled_heater_requires_explicit_thermal_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(positionX: 11.0, temperature: 194.9);
        $checkpoint['pending'] = [
            'command' => 'G1 X11',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0],
            ]),
            'classification' => 'observable',
        ];

        $this->expectException(PrintRecoveryRequiredException::class);
        $this->expectExceptionMessage('extruder 0 cooled below');

        (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
    }

    public function test_lost_heater_target_requires_explicit_thermal_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(positionX: 11.0, target: 0.0);
        $checkpoint['pending'] = [
            'command' => 'G1 X11',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0],
            ]),
            'classification' => 'observable',
        ];

        $this->expectException(PrintRecoveryRequiredException::class);
        $this->expectExceptionMessage('target changed unexpectedly');

        (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
    }

    public function test_unaffected_axis_drift_requires_explicit_position_recovery(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(positionX: 11.0, positionY: 21.0);
        $checkpoint['pending'] = [
            'command' => 'G1 X11',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0],
            ]),
            'classification' => 'observable',
        ];

        $this->expectException(PrintRecoveryRequiredException::class);
        $this->expectExceptionMessage('unaffected printer y-axis moved');

        (new PrintConnectionReconciler)->reconcile($printer, $serial, $checkpoint);
    }

    public function test_recovery_log_preserves_per_axis_diagnostics_before_checkpoint_cleanup(): void
    {
        [$printer, $serial, $checkpoint] = $this->scenario(positionX: 10.5);
        $checkpoint['pending'] = [
            'command' => 'G1 X11',
            'sourceCommand' => true,
            'beforeState' => $checkpoint['state'],
            'afterState' => array_replace_recursive($checkpoint['state'], [
                'position' => ['x' => 11.0],
            ]),
            'classification' => 'observable',
        ];
        $logger = \Mockery::mock(Logger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Print connection reconciliation requires recovery.',
                \Mockery::on(function (array $context): bool {
                    return $context['command'] === 'G1 X11'
                        && $context['matchesBefore'] === false
                        && $context['matchesAfter'] === false
                        && $context['position']['x']['affected'] === true
                        && $context['position']['x']['matchesBefore'] === false
                        && $context['position']['x']['matchesAfter'] === false
                        && $context['position']['x']['beforeDelta'] === 0.5
                        && $context['position']['x']['afterDelta'] === 0.5
                        && isset($context['thermal']['extruders'][0]['observed']);
                })
            );

        try {
            (new PrintConnectionReconciler($logger))->reconcile($printer, $serial, $checkpoint);
            $this->fail('An ambiguous movement must require recovery.');
        } catch (PrintRecoveryRequiredException) {
            $this->addToAssertionCount(1);
        }
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

    private function scenario(
        float $positionX = 10.0,
        float $temperature = 205.0,
        float $positionY = 20.0,
        float $positionZ = 0.2,
        float $positionE = 4.0,
        float $target = 210.0,
        float $bedTemperature = 60.0,
        float $bedTarget = 60.0
    ): array {
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
        $serial = new class($positionX, $temperature, $positionY, $positionZ, $positionE, $target, $bedTemperature, $bedTarget) extends Serial
        {
            public array $queries = [];

            public function __construct(
                private readonly float $positionX,
                private readonly float $temperature,
                private readonly float $positionY,
                private readonly float $positionZ,
                private readonly float $positionE,
                private readonly float $target,
                private readonly float $bedTemperature,
                private readonly float $bedTarget
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
                    'M114' => sprintf(
                        'X:%.2f Y:%.2f Z:%.2f E:%.2f ok',
                        $this->positionX,
                        $this->positionY,
                        $this->positionZ,
                        $this->positionE
                    ),
                    'M105' => sprintf(
                        'ok T:%.2f /%.2f B:%.2f /%.2f',
                        $this->temperature,
                        $this->target,
                        $this->bedTemperature,
                        $this->bedTarget
                    ),
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
