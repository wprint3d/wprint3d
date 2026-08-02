<?php

namespace Tests\Unit;

use App\Http\Controllers\PrinterController;
use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrinterControllerTest extends TestCase
{
    public function test_manual_movement_restores_absolute_positioning(): void
    {
        $printer = new class extends Printer
        {
            public bool $connected = true;

            public array $commands = [];

            public function __construct() {}

            public function queueCommand(string $command): bool
            {
                $this->commands[] = $command;

                return true;
            }
        };

        $request = Request::create('/printer/control', 'POST', [
            'direction' => 'UP',
            'distance' => '5',
            'feedrate' => '100',
        ]);
        $request->printer = $printer;

        (new PrinterController)->handleControlCommand($request);

        $this->assertSame([
            'G91',
            'G0  Z5 F100',
            'G90',
        ], $printer->commands);
    }

    public function test_relative_movement_without_z_can_be_streamed(): void
    {
        Storage::fake('gcode');
        Storage::disk('gcode')->put('relative-movement.gcode', implode(PHP_EOL, [
            'G91',
            'G1 X10',
            'G1 Z0.2',
        ]));

        $printer = new class extends Printer
        {
            public bool $connected = true;

            public ?string $activeFile = 'relative-movement.gcode';

            public function __construct() {}
        };

        $request = Request::create('/printer/print/lines/stream', 'GET', [
            'targetLayer' => 1,
        ]);
        $request->printer = $printer;
        $request->streamMaxLengthBytes = 1024;

        ob_start();

        try {
            (new PrinterController)->getLinesFromActiveFile($request);
            $output = ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();

            throw $throwable;
        }

        $this->assertSame(implode(PHP_EOL, [
            'G91',
            'G1 X10',
            'G1 Z0.2',
            ';P=100',
            '',
        ]), $output);
    }
}
