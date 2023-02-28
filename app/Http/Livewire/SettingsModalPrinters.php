<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Livewire\Component;

class SettingsModalPrinters extends Component
{
    public $printers;

    public function boot() {
        $this->printers = Printer::all();
    }

    public function render()
    {
        return view('livewire.settings-modal-printers');
    }
}
