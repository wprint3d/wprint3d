<?php

namespace Tests\Unit\Gcode;

use App\Gcode\GcodeDurationEstimator;
use Tests\TestCase;

class GcodeDurationEstimatorTest extends TestCase
{
    public function test_it_streams_linear_moves_arcs_in_all_planes_dwell_and_firmware_retract(): void
    {
        $estimate = (new GcodeDurationEstimator)->estimate(base_path('tests/Fixtures/Gcode/modes-and-arcs.gcode'));

        $this->assertGreaterThan(1, $estimate->seconds);
        $this->assertSame('analysis', $estimate->origin);
        $this->assertGreaterThan(15, $estimate->lineCount);
        $this->assertFalse($estimate->hasUnboundedWait);
    }

    public function test_it_accepts_consistent_cura_and_prusaslicer_metadata(): void
    {
        $cura = $this->estimate(";TIME:12\nG1 X120 F600\n");
        $prusa = $this->estimate("; estimated printing time (normal mode) = 1m 2s\nG1 X600 F600\n");

        $this->assertSame(12, $cura->seconds);
        $this->assertSame('metadata', $cura->origin);
        $this->assertSame(62, $prusa->seconds);
        $this->assertSame('metadata', $prusa->origin);
    }

    public function test_it_rejects_invalid_impossible_and_clearly_inconsistent_metadata(): void
    {
        $estimate = $this->estimate(";TIME:999999999999999999999\n;TIME:-2\n;TIME:1\nG1 X600 F600\n");

        $this->assertSame('analysis', $estimate->origin);
        $this->assertGreaterThan(50, $estimate->seconds);
        $this->assertLessThan(100, $estimate->seconds);
    }

    public function test_units_modes_offsets_speed_factor_and_limits_change_timing_without_crashing(): void
    {
        $estimate = $this->estimate(<<<'GCODE'
N1 G20*0
G91
M83
G1 X1 E.1 F60
G90
M82
G92 X0 E0
M220 S50
M203 X10 E5
M201 X100 E100
M205 X5
G1 X25.4 E1 F600
malformed nonsense
G1 Xnan
GCODE);

        $this->assertGreaterThan(3, $estimate->seconds);
        $this->assertLessThan(31_536_001, $estimate->seconds);
    }

    public function test_extrusion_only_retraction_travel_and_unbounded_wait_are_distinguished(): void
    {
        $estimate = $this->estimate("M83\nG1 E5 F300\nG1 E-2 F1200\nG1 X20 F6000\nTEMPERATURE_WAIT SENSOR=heater_bed MINIMUM=60\nM0\n");

        $this->assertGreaterThan(1, $estimate->seconds);
        $this->assertTrue($estimate->hasUnboundedWait);
    }

    public function test_outputs_are_finite_non_negative_and_large_files_are_processed_line_by_line(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wprint3d-streaming-');
        $stream = fopen($path, 'wb');
        for ($i = 0; $i < 20_000; $i++) {
            fwrite($stream, "G91\nG1 X0.01 F6000\n");
        }
        fclose($stream);
        $estimate = (new GcodeDurationEstimator)->estimate($path);
        unlink($path);

        $this->assertGreaterThanOrEqual(40_000, $estimate->lineCount);
        $this->assertGreaterThanOrEqual(0, $estimate->seconds);
        $this->assertLessThanOrEqual(31_536_000, $estimate->seconds);
    }

    private function estimate(string $gcode): object
    {
        $path = tempnam(sys_get_temp_dir(), 'wprint3d-gcode-');
        file_put_contents($path, $gcode);

        try {
            return (new GcodeDurationEstimator)->estimate($path);
        } finally {
            unlink($path);
        }
    }
}
