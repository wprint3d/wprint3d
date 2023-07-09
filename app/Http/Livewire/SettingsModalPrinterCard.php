<?php

namespace App\Http\Livewire;

use Livewire\Component;

class SettingsModalPrinterCard extends Component
{
    public $printer;

    public function render()
    {
        return view('livewire.settings-modal-printer-card');
    }
}
