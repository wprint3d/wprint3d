<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Log;
use Livewire\Component;

class PrinterManagerModalConnection extends Component
{
    public $printer;

    public $node;
    public $baudRate;

    public function mount() {
        Log::info( __METHOD__ . ': ' . json_encode($this->printer) );

        if ($this->printer) {
            $this->node     = $this->printer->node;
            $this->baudRate = $this->printer->baudRate;
        }
    }

    public function updated($field, $newValue) {
        Log::info( __METHOD__ . ': ' . $field . ' => ' . $newValue );

        switch ($field) {
            case 'baudRate':
                if (
                    is_numeric($newValue)
                    &&
                    in_array($newValue, config('app.common_baud_rates'))
                ) {
                    $this->printer->baudRate = (int) $newValue;
                    $this->printer->save();
                }

                break;
        }

        $this->dispatchBrowserEvent('settingsChangeSaved');
    }

    public function render()
    {
        return view('livewire.printer-manager-modal-connection');
    }
}
