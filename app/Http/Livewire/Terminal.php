<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Str;

use Livewire\Component;

class Terminal extends Component
{
    public ?array  $terminal = [];

    public string  $command  = '';

    public bool   $autoScroll;
    public bool   $showSensors;
    public bool   $showInputCommands;

    public ?Printer $printer = null;

    protected $listeners = [ 'selectPrinter' ];

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
        Log::info( __METHOD__ . ': ' . $this->command );

        $this->printer->queueCommand( $this->command );

        $this->command = '';
    }

    public function selectPrinter() {
        Log::debug( __METHOD__ . ': ' . (Auth::user()->activePrinter ?? 'none') );

        $this->printer = Printer::select('node', 'baudRate', 'settings')->find( Auth::user()->activePrinter );
    }

    public function updateTerminalLog() {
        if (!$this->printer) {
            $this->terminal = [ 'No printer selected.' ];

            return;
        }

        $lines = Str::of(
            $this->printer->getConsole( $this->printer->_id )
        )->explode(PHP_EOL);

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

    public function boot() {
        $this->printer = Printer::select('node', 'baudRate', 'settings')->find( Auth::user()->activePrinter );

        $this->autoScroll        = $this->printer->settings['autoScroll']        ?? true;
        $this->showSensors       = $this->printer->settings['showSensors']       ?? true;
        $this->showInputCommands = $this->printer->settings['showInputCommands'] ?? true;

        $this->updateTerminalLog();
    }

    public function hydrate() {
        $this->updateTerminalLog();
    }

    public function render()
    {
        return view('livewire.terminal');
    }
}
