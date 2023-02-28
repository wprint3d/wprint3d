<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Livewire\Component;

class SettingsModal extends Component
{
    public array $availablePanes = [ 'printers', 'cameras', 'recording', 'system' ];

    public $isPrinting;

    public function render()
    {
        $this->isPrinting = Printer::whereNotNull('activeFile')->count() > 0;

        return view('livewire.settings-modal');
    }
}
