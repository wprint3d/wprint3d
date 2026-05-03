<?php

namespace Tests\Unit;

use Tests\TestCase;

class HelperFunctionsTest extends TestCase
{
    public function test_read_stream_line_reads_full_lines(): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "G1 X1 Y2\nG1 X3 Y4\n");
        rewind($stream);

        $this->assertSame("G1 X1 Y2\n", readStreamLine($stream));
        $this->assertSame("G1 X3 Y4\n", readStreamLine($stream));
        $this->assertSame('', readStreamLine($stream));
    }

    public function test_read_stream_line_truncates_but_consumes_long_lines(): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "G1 X123456789\nG2\n");
        rewind($stream);

        $this->assertSame('G1 X1', readStreamLine($stream, 5));
        $this->assertSame("G2\n", readStreamLine($stream, 5));
    }

    public function test_movement_to_xyze_parses_gcode_and_m114_responses(): void
    {
        $this->assertSame(
            [
                'x' => '10.5',
                'y' => '-2.0',
                'e' => '0.33',
            ],
            movementToXYZE('G1 X10.5 Y-2.0 E0.33 ; comment')
        );

        $this->assertSame(
            [
                'x' => '1.00',
                'y' => '2.00',
                'z' => '3.00',
                'e' => '4.00',
            ],
            movementToXYZE('ok X:1.00 Y:2.00 Z:3.00 E:4.00 Count X:10 Y:20 Z:30')
        );
    }
}
