<?php

namespace Tests\Unit\Support\FakeSerial;

use App\Support\FakeSerial\FakeSerialEmulator;
use PHPUnit\Framework\TestCase;

class FakeSerialEmulatorTest extends TestCase
{
    public function test_it_reports_temperatures_like_marlin(): void
    {
        $emulator = new FakeSerialEmulator;

        $result = $emulator->transact('M105');

        $this->assertStringContainsString('ok T:', $result['response']);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('lines', $result);
    }

    public function test_it_reports_firmware_information_and_capabilities(): void
    {
        $emulator = new FakeSerialEmulator;

        $result = $emulator->transact('M115');

        $this->assertStringContainsString('FIRMWARE_NAME:', $result['response']);
        $this->assertStringContainsString('MACHINE_TYPE:', $result['response']);
        $this->assertStringContainsString('Cap:AUTOREPORT_TEMP:1', $result['response']);
    }

    public function test_slow_commands_emit_busy_updates_before_ok(): void
    {
        $emulator = new FakeSerialEmulator;

        $result = $emulator->transact('M109 S210');

        $this->assertGreaterThan(1, count($result['lines']));
        $this->assertTrue(
            collect($result['lines'])->contains(fn (array $line) => str_contains($line['text'], 'busy: processing'))
        );
        $this->assertStringContainsString('ok', $result['response']);
        $this->assertSame(210.0, $result['state']['hotend']['target']);
    }

    public function test_invalid_commands_emit_an_error_line(): void
    {
        $emulator = new FakeSerialEmulator;

        $result = $emulator->transact('M9999');

        $this->assertStringContainsString('Error:', $result['response']);
        $this->assertStringContainsString('Unknown command', $result['response']);
    }

    public function test_unavailable_tools_fail_coherently(): void
    {
        $emulator = new FakeSerialEmulator;

        $result = $emulator->transact('T7');

        $this->assertStringContainsString('Tool 7 unavailable', $result['response']);
    }

    public function test_motion_commands_update_the_tracked_position(): void
    {
        $emulator = new FakeSerialEmulator;

        $result = $emulator->transact(
            'G1 X15.5 Y30.25 Z0.4 E2.1 F1800',
            [
                'position' => [
                    'x' => 0.0,
                    'y' => 0.0,
                    'z' => 0.0,
                    'e' => 0.0,
                ],
            ]
        );

        $this->assertSame(15.5, $result['state']['position']['x']);
        $this->assertSame(30.25, $result['state']['position']['y']);
        $this->assertSame(0.4, $result['state']['position']['z']);
        $this->assertSame(2.1, $result['state']['position']['e']);
        $this->assertStringContainsString('ok', $result['response']);
    }

    public function test_it_supports_units_switching_and_g92_position_updates(): void
    {
        $emulator = new FakeSerialEmulator;

        $inchMode = $emulator->transact('G20');
        $move = $emulator->transact('G1 X1 Y2 E0.5 F60', $inchMode['state']);
        $setPosition = $emulator->transact('G92 X10 E2', $move['state']);
        $position = $emulator->transact('M114', $setPosition['state']);

        $this->assertSame(25.4, $move['state']['position']['x']);
        $this->assertSame(50.8, $move['state']['position']['y']);
        $this->assertSame(12.7, $move['state']['position']['e']);
        $this->assertSame(1524.0, $move['state']['feedrate']);
        $this->assertSame(254.0, $setPosition['state']['position']['x']);
        $this->assertSame(50.8, $setPosition['state']['position']['e']);
        $this->assertStringContainsString('X:254.00', $position['response']);
        $this->assertStringContainsString('E:50.80', $position['response']);
    }

    public function test_it_supports_common_host_feedback_commands(): void
    {
        $emulator = new FakeSerialEmulator;

        $fanOn = $emulator->transact('M106 S200');
        $fanOff = $emulator->transact('M107', $fanOn['state']);
        $lcd = $emulator->transact('M117 Ready to print', $fanOff['state']);
        $serial = $emulator->transact('M118 E1 action:pause', $lcd['state']);
        $endstops = $emulator->transact('M119', $serial['state']);

        $this->assertSame(200, $fanOn['state']['fans'][0]['speed']);
        $this->assertSame(0, $fanOff['state']['fans'][0]['speed']);
        $this->assertSame('Ready to print', $lcd['state']['lcdMessage']);
        $this->assertStringContainsString('echo:action:pause', $serial['response']);
        $this->assertStringContainsString('Reporting endstop status', $endstops['response']);
        $this->assertStringContainsString('x_min: open', $endstops['response']);
    }

    public function test_it_supports_recovery_and_runtime_commands_used_by_the_app(): void
    {
        $emulator = new FakeSerialEmulator;

        $heated = $emulator->transact('M109 R120');
        $positioned = $emulator->transact('G92 X12 Y18 Z6 E4', $heated['state']);
        $homed = $emulator->transact('G28 X Y R0', $positioned['state']);
        $breakWait = $emulator->transact('M108', $homed['state']);
        $settings = $emulator->transact('M503', $breakWait['state']);

        $this->assertSame(120.0, $heated['state']['hotend']['target']);
        $this->assertSame(12.0, $positioned['state']['position']['x']);
        $this->assertSame(18.0, $positioned['state']['position']['y']);
        $this->assertSame(6.0, $positioned['state']['position']['z']);
        $this->assertSame(4.0, $positioned['state']['position']['e']);
        $this->assertSame(0.0, $homed['state']['position']['x']);
        $this->assertSame(0.0, $homed['state']['position']['y']);
        $this->assertSame(6.0, $homed['state']['position']['z']);
        $this->assertSame(4.0, $homed['state']['position']['e']);
        $this->assertStringContainsString('ok', $breakWait['response']);
        $this->assertStringContainsString('M503', $settings['response']);
        $this->assertStringContainsString('G21', $settings['response']);
    }

    public function test_it_can_schedule_auto_reports_for_temperature_and_position(): void
    {
        $emulator = new FakeSerialEmulator;

        $temperatureAutoReport = $emulator->transact('M155 S2');
        $positionAutoReport = $emulator->transact('M154 S3', $temperatureAutoReport['state']);
        $idleRead = $emulator->transact('', array_replace_recursive($positionAutoReport['state'], [
            'lastAutoReportAt' => [
                'temperature' => microtime(true) - 5,
                'position' => microtime(true) - 5,
            ],
        ]));

        $this->assertSame(2, $temperatureAutoReport['state']['temperatureAutoReportIntervalSecs']);
        $this->assertSame(3, $positionAutoReport['state']['positionAutoReportIntervalSecs']);
        $this->assertGreaterThanOrEqual(3, count($idleRead['lines']));
        $this->assertStringContainsString('T:', $idleRead['response']);
        $this->assertStringContainsString('X:', $idleRead['response']);
    }
}
