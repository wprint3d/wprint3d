<?php

namespace App\Http\Livewire;

use Livewire\Component;

class SettingsModalSystem extends Component
{

    public $writeable = false;

    public function render()
    {
        return view('livewire.settings-modal-system');
    }

}
