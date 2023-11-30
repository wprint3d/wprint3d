<?php

namespace App\Http\Livewire;

use App\Enums\ToastMessageType;

use App\Events\ToastMessage;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Str;

use Livewire\Component;

class TerminalTab extends Component
{
    public ?array  $terminal = [];

    public string  $command  = '';

    public bool   $autoScroll;
    public bool   $showSensors;
    public bool   $showInputCommands;

    public $writeable = false;

    public bool   $sentEmptyCommand = false;

    public ?Printer $printer = null;

    protected $listeners = [
        'selectPrinter'     => 'selectPrinter',
        'refreshActiveFile' => 'refreshActivePrinter'
    ];

    public function updated() {
        if (!$this->printer->settings) {
            $this->printer->settings = [];
        }

        $settings = $this->printer->settings;

        $settings['autoScroll']          = $this->autoScroll;
        $settings['showSensors']         = $this->showSensors;
        $settings['showInputCommands']   = $this->showInputCommands;

        $this->printer->settings = $settings;
        $this->printer->save();

        $this->updateTerminalLog();

        $this->dispatchBrowserEvent('toggleAutoScroll', [ 'enabled' => $this->autoScroll ]);
    }

    public function queueCommand() {
        if (empty( trim($this->command) )) {
            $this->sentEmptyCommand = true;

            return;
        }

        $this->sentEmptyCommand = false;

        if (!$this->printer->connected) {
            ToastMessage::dispatch(
                Auth::id(),                                                 // userId
                ToastMessageType::ERROR,                                    // type
                'Couldn\'t queue command: this printer is not connected.'   // message
            );

            $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'start' ]);

            return;
        }

        Log::info( __METHOD__ . ': ' . $this->command );

        $this->printer->queueCommand( $this->command );

        $this->command = '';
    }

    public function selectPrinter() {
        $activePrinter = Auth::user()->getActivePrinter();

        Log::debug( __METHOD__ . ': ' . ($activePrinter ?? 'none') );

        $this->printer = Printer::select('activeFile', 'node', 'baudRate', 'settings')->find( $activePrinter );
    }

    public function updateTerminalLog() {
        if (!$this->printer) {
            $this->terminal = [ 'No printer selected.' ];

            return;
        }

        // Remove characters that aren't part of the UTF-8 encoding.
        $lines = iconv(
            from_encoding: 'UTF-8',
            to_encoding:   'UTF-8//IGNORE',
            string:        $this->printer->getConsole()
        );

        $lines = Str::of( $lines )->explode(PHP_EOL);

        if (!$lines) {
            $this->terminal = [ 'Nothing to display.' ];

            return;
        }

        if (!$this->showSensors) {
            foreach ($lines as $index => $line) {
                $timestampLinePair = Str::of($line)->explode(': ', 2);

                if (
                    isset($timestampLinePair[1])
                    &&
                    Str::startsWith( trim($timestampLinePair[1]), '> M105' )
                ) {
                    unset($lines[ $index ]);
                    unset($lines[ $index + 1 ]);
                }
            }
        }

        if (!$this->showInputCommands) {
            $lines = $lines->filter(function ($line) {
                $timestampLinePair = Str::of($line)->explode(': ', 2);

                return !isset($timestampLinePair[1]) || !Str::startsWith( trim($timestampLinePair[1]), '>' );
            });
        }

        $lines = $lines->filter(function ($line) { return !!$line; });

        $this->terminal = $lines->toArray();
    }

    public function refreshActivePrinter() {
        $activePrinter = Auth::user()->getActivePrinter();

        if ($activePrinter) {
            $this->printer = Printer::select('activeFile', 'node', 'baudRate', 'settings')->find( $activePrinter );
        }
    }

    public function boot() {
        $this->autoScroll        = $this->printer->settings['autoScroll']        ?? true;
        $this->showSensors       = $this->printer->settings['showSensors']       ?? true;
        $this->showInputCommands = $this->printer->settings['showInputCommands'] ?? true;

        $this->refreshActivePrinter();
        $this->updateTerminalLog();
    }

    public function hydrate() {
        $this->updateTerminalLog();
    }

    public function render()
    {
        return view('livewire.terminal-tab');
    }
}
