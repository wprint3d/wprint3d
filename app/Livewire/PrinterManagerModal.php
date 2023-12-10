<?php

namespace App\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Log;

use Livewire\Attributes\On;

use Livewire\Component;

class PrinterManagerModal extends Component
{
    protected $listeners = [ 'loadPrinterManagement' => 'loadPrinter' ];

    public $availablePanes = [ 'connection', 'specifications', 'cameras' ];

    public $printer;

    public $role;
    public $writeable = false;

    #[On('loadPrinterManagement')]
    public function loadPrinter(string $printerId) {
        Log::info( __METHOD__ . ': ' . $printerId );

        $this->printer = Printer::find( $printerId );

        $this->dispatch('printerLoaded', id: $this->printer);
    }

    public function render()
    {
        return view('livewire.printer-manager-modal');
    }
}
