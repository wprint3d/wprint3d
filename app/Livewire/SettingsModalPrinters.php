<?php

namespace App\Livewire;

use App\Models\Printer;

use Livewire\Component;

class SettingsModalPrinters extends Component
{
    public $printers;

    public $writeable = false;

    protected $listeners = [ 'hardwareChangeDetected' => 'refreshPrinters' ];

    public function boot() {
        $this->refreshPrinters();
    }

    public function refreshPrinters() {
        $this->printers = Printer::all();

        $this->dispatch('printersRefreshed');
    }

    public function render()
    {
        return view('livewire.settings-modal-printers');
    }
}
