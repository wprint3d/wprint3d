<?php

namespace App\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Livewire\Component;

class ConnectionStatus extends Component
{
    public $status;

    public ?Printer $printer = null;

    protected $listeners = [ 'selectPrinter' ];

    private function refreshPrinter() {
        $printerId = Auth::user()->getActivePrinter();

        if ($printerId) {
            $this->printer = Printer::select('lastSeen')->find( $printerId );

            if (!$this->printer) {
                $this->printer = null;
            }
        }
    }

    public function boot() {
        $this->refreshPrinter();
    }

    public function selectPrinter($printer) {
        Log::debug( __METHOD__ . ': ' . ($printer->_id ?? 'none') );

        $this->refreshPrinter();
    }

    public function render()
    {
        return view('livewire.connection-status');
    }
}
