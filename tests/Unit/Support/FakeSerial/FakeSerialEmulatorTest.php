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
}
