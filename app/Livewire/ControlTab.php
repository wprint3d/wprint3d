<?php

namespace App\Livewire;

use App\Enums\ToastMessageType;

use App\Events\ToastMessage;

use App\Models\Configuration;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Livewire\Component;

class ControlTab extends Component
{
    protected $listeners = [
        'selectPrinter'             => 'handlePrinterSelected',
        'targetTemperatureChanged'  => 'handleTargetTemperatureChange'
    ];

    private ?Printer $printer = null;

    private ?int $extrusionMinTemp  = null;
    private ?int $extrusionFeedrate = null;

    public ?int $distance;
    public ?int $extrusionLength;
    public ?int $feedrate;
    public ?int $extruderCount = 0;

    public ?int $hotendTemperature      = 0;
    public ?int $bedTemperature         = 0;
    public ?int $targetMovementExtruder = 0;

    const NO_PRINTER_ERROR = 'A printer must be selected.';

    public function handlePrinterSelected() {
        $activePrinter = Auth::user()->getActivePrinter();

        if ($activePrinter) {
            $this->printer = Printer::select('_id')->find( $activePrinter );
        }

        if ($this->printer) {
            $this->extruderCount = count(
                $this->printer->getStatistics()['extruders'] ?? []
            );
        }
    }

    public function handleTargetTemperatureChange() {
        if (!$this->printer) {
            Log::warning('unable to handle temperature change: no printer selected.');

            return;
        }

        $statistics = $this->printer->getStatistics();

        $mainExtruder = null;
        $heatedBed    = null;

        if ($statistics) {
            if ($statistics['extruders']) {
                $mainExtruder = $statistics['extruders'][ array_key_first($statistics['extruders']) ];
            }

            if ($statistics['bed']) {
                $heatedBed    = $statistics['bed'];
            }

            $this->hotendTemperature = $mainExtruder['target'];
            $this->bedTemperature    = $heatedBed['target'];
        }
    }

    private function waitForStatisticsRefresh() {
        sleep( Configuration::get('autoSerialIntervalSecs') + 6 ); // base sleep + queue resting time + 1s offset
    }

    private function queueMovement(string $direction) : bool {
        Log::debug( __METHOD__ . ": {$direction}: distance: {$this->distance}, feedrate: {$this->feedrate}" );

        if (!$this->printer) {
            Log::warning("unable to queue movement. => direction: {$direction}, distance: {$this->distance}, feedrate: {$this->feedrate}");

            return false;
        }

        $this->printer->refresh();

        if (!$this->printer->connected) {
            ToastMessage::dispatch(
                Auth::id(),                                                 // userId
                ToastMessageType::ERROR,                                    // type
                'Couldn\'t queue movement: this printer is not connected.'  // message
            );

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

            ToastMessage::dispatch(
                Auth::id(),             // userId
                ToastMessageType::INFO, // type
                'Nothing to do.'        // message
            );

            return true;
        }

        if (!$this->printer) {
            Log::warning("unable to queue movement. => direction: {$direction}, distance: {$this->distance}, feedrate: {$this->feedrate}");

            return false;
        }

        $this->printer->refresh();

        if (!$this->printer->connected) {
            ToastMessage::dispatch(
                Auth::id(),                                                 // userId
                ToastMessageType::ERROR,                                    // type
                'Couldn\'t queue extrusion: this printer is not connected.' // message
            );

            return false;
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
            $this->dispatch('configApplyError', message: "Cold extrude prevented, the target temperature is {$this->hotendTemperature}°C and the minimum required to extrude material is {$this->extrusionMinTemp}°C, increase the temperature and try again.");

            return false;
        }

        if ($currentTemperature < $this->extrusionMinTemp) {
            $this->dispatch('configApplyError', message: "Cold extrude prevented, the current temperature is {$currentTemperature}°C and the minimum required to extrude material is {$this->extrusionMinTemp}°C, wait for the hotend to get to {$this->hotendTemperature}°C or, at least, {$this->extrusionMinTemp}°C and try again.");

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

        $this->printer->queueCommand( "T{$this->targetMovementExtruder}" );
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
            $this->dispatch('configApplyError', message: self::NO_PRINTER_ERROR);

            return;
        }

        $statistics = $this->printer->getStatistics();

        if (isset( $statistics['extruders'] )) {
            foreach (array_keys( $statistics['extruders'] ) as $index) {
                $this->printer->queueCommand( "M104 I{$index} S{$this->hotendTemperature}" );
            }
        }

        $this->printer->queueCommand( "M104 S{$this->hotendTemperature}" );

        $this->waitForStatisticsRefresh();
    }

    public function setBedTemperature() {
        if (!$this->printer) {
            $this->dispatch('configApplyError', message: self::NO_PRINTER_ERROR);

            return;
        }

        $this->printer->queueCommand( "M140 S{$this->bedTemperature}" );

        $this->waitForStatisticsRefresh();
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

    public function updatingTargetMovementExtruder(&$value) {
        $value = (int) $value;
    }

    public function boot() {
        $this->distance = Configuration::get('controlDistanceDefault');
        $this->feedrate = Configuration::get('controlFeedrateDefault');

        $this->extrusionLength   = 0;
        $this->extrusionFeedrate = Configuration::get('controlExtrusionFeedrate');
        $this->extrusionMinTemp  = Configuration::get('controlExtrusionMinTemp');

        $this->handlePrinterSelected();
        $this->handleTargetTemperatureChange();
    }

    public function render()
    {
        return view('livewire.control-tab');
    }
}
