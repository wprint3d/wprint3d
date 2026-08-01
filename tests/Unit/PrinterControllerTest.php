<?php

namespace Tests\Unit;

use App\Http\Controllers\PrinterController;
use App\Models\Printer;
use Illuminate\Http\Request;
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
}
