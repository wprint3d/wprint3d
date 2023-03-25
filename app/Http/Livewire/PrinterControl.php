<?php

namespace App\Http\Livewire;

use App\Models\Configuration;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Livewire\Component;

class PrinterControl extends Component
{
    protected $listeners = [ 'selectPrinter' => 'handlePrinterSelected' ];

    private ?Printer $printer = null;

    private ?int $extrusionMinTemp  = null;
    private ?int $extrusionFeedrate = null;

    public ?int $distance;
    public ?int $extrusionLength;
    public ?int $feedrate;

    public ?int $hotendTemperature  = 0;
    public ?int $bedTemperature     = 0;

    const NO_PRINTER_ERROR = 'A printer must be selected.';

    public function handlePrinterSelected() {
        $printerId = Auth::user()->activePrinter;

        $this->printer = Printer::select('_id')->find( $printerId );
    }

    private function queueMovement(string $direction) : bool {
        Log::debug( __METHOD__ . ": {$direction}: distance: {$this->distance}, feedrate: {$this->feedrate}" );

        if (!$this->printer) {
            Log::warning("unable to queue movement. => direction: {$direction}, distance: {$this->distance}, feedrate: {$this->feedrate}");

            return false;
        }

        $command = match ($direction) {
            'up'        => "G0  Z{$this->distance} F{$this->feedrate}",
            'left'      => "G0 X-{$this->distance} F{$this->feedrate}",
            'right'     => "G0  X{$this->distance} F{$this->feedrate}",
            'down'      => "G0 Z-{$this->distance} F{$this->feedrate}",
            'yForward'  => "G0  Y{$this->distance} F{$this->feedrate}",
            'yBackward' => "G0 Y-{$this->distance} F{$this->feedrate}",
            'home'      => 'G28',
            default     => null
        };

        if (!$command) {
            Log::warning("invalid direction \"{$direction}\", failed to generate command.");

            return false;
        }

        $this->printer->queueCommand( 'G91' );

        return $this->printer->queueCommand( $command );
    }

    private function queueExtrusion(string $direction) : bool {
        if ($this->extrusionLength <= 0) {
            $this->extrusionLength = 0;

            return true;
        }

        $currentTemperature = 0;

        $statistics = $this->printer->getStatistics();

        if (
            isset( $statistics['extruders'][0] )
            &&
            isset( $statistics['extruders'][0]['temperature'] )
        ) {
            $currentTemperature = $statistics['extruders'][0]['temperature'];
        }

        if ($this->hotendTemperature < $this->extrusionMinTemp) {
            $this->dispatchBrowserEvent('configApplyError', "Cold extrude prevented, the target temperature is {$this->hotendTemperature}°C and the minimum required to extrude material is {$this->extrusionMinTemp}°C, increase the temperature and try again.");

            return false;
        }

        if ($currentTemperature < $this->extrusionMinTemp) {
            $this->dispatchBrowserEvent('configApplyError', "Cold extrude prevented, the current temperature is {$currentTemperature}°C and the minimum required to extrude material is {$this->extrusionMinTemp}°C, wait for the hotend to get to {$this->hotendTemperature}°C or, at least, {$this->extrusionMinTemp}°C and try again.");

            return false;
        }

        Log::debug( __METHOD__ . ": {$direction}: length: {$this->extrusionLength}, feedrate: {$this->extrusionFeedrate}" );

        if (!$this->printer) {
            Log::warning("unable to queue movement. => direction: {$direction}, length: {$this->extrusionLength}, feedrate: {$this->extrusionFeedrate}");

            return false;
        }

        $command = match ($direction) {
            'back'      => "G0 E-{$this->extrusionLength} F{$this->extrusionFeedrate}",
            'forward'   => "G0  E{$this->extrusionLength} F{$this->extrusionFeedrate}",
            default     => null
        };

        if (!$command) {
            Log::warning("invalid direction \"{$direction}\", failed to generate command.");

            return false;
        }

        $this->printer->queueCommand( 'G91' );

        return $this->printer->queueCommand( $command );
    }

    public function up() {
        $this->queueMovement('up');
    }

    public function left() {
        $this->queueMovement('left');
    }

    public function right() {
        $this->queueMovement('right');
    }

    public function down() {
        $this->queueMovement('down');
    }

    public function yForward() {
        $this->queueMovement('yForward');
    }

    public function yBackward() {
        $this->queueMovement('yBackward');
    }

    public function home() {
        $this->queueMovement('home');
    }

    public function extrudeBack() {
        $this->queueExtrusion('back');
    }

    public function extrudeForward() {
        $this->queueExtrusion('forward');
    }

    public function setHotendTemperature() {
        if (!$this->printer) {
            $this->dispatchBrowserEvent('configApplyError', self::NO_PRINTER_ERROR);

            return;
        }

        $statistics = $this->printer->getStatistics();

        foreach ($statistics['extruders'] as $index => $extruder) {
            $this->printer->queueCommand( "M104 I{$index} S{$this->hotendTemperature}" );
        }

        $this->printer->queueCommand( "M104 S{$this->hotendTemperature}" );
    }

    public function setBedTemperature() {
        if (!$this->printer) {
            $this->dispatchBrowserEvent('configApplyError', self::NO_PRINTER_ERROR);

            return;
        }

        $this->printer->queueCommand( "M140 S{$this->bedTemperature}" );
    }

    public function updatingExtrusionLength(&$value) {
        $value = (int) $value;
    }

    public function updatingHotendTemperature(&$value) {
        $value = (int) $value;
    }

    public function updatingBedTemperature(&$value) {
        $value = (int) $value;
    }

    public function boot() {
        $this->distance = Configuration::get('controlDistanceDefault', env('PRINTER_CONTROL_DISTANCE_DEFAULT'));
        $this->feedrate = Configuration::get('controlFeedrateDefault', env('PRINTER_CONTROL_FEEDRATE_DEFAULT'));

        $this->extrusionLength   = 0;
        $this->extrusionFeedrate = Configuration::get('controlExtrusionFeedrate', env('PRINTER_CONTROL_EXTRUSION_FEEDRATE'));
        $this->extrusionMinTemp  = Configuration::get('controlExtrusionMinTemp',  env('PRINTER_CONTROL_EXTRUSION_MIN_TEMP'));

        $this->handlePrinterSelected();

        if ($this->printer) {
            $statistics = $this->printer->getStatistics();

            if (isset( $statistics['extruders'] )) {
                foreach ($statistics['extruders'] as $index => $extruder) {
                    if (isset( $extruder['target'] )) {
                        $this->hotendTemperature = $extruder['target'];

                        break;
                    }
                }
            }

            if (isset( $statistics['bed'] ) && isset( $statistics['bed']['target'] )) {
                $this->bedTemperature = $statistics['bed']['target'];
            }
        }
    }

    public function render()
    {
        return view('livewire.printer-control');
    }
}
