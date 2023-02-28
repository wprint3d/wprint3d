<?php

namespace App\Http\Livewire;

use App\Models\Printer;
use Illuminate\Support\Facades\Log;

use Livewire\Component;

class PrinterManagerModal extends Component
{
    protected $listeners = [
        'loadPrinterManagement' => 'loadPrinter',
        'refreshComponent'      => '$refresh'
    ];

    public $availablePanes = [ 'connection', 'specifications', 'cameras' ];

    public $printer;

    public function loadPrinter(string $printerId) {
        Log::info( __METHOD__ . ': ' . $printerId );

        $this->printer = Printer::find( $printerId );

        $this->emit('refreshComponent');

        $this->dispatchBrowserEvent('printerLoaded', $this->printer);
    }

    public function render()
    {
        return view('livewire.printer-manager-modal');
    }
}
