<?php

namespace App\Livewire;

use Livewire\Component;

class SettingsModalDevelopment extends Component
{
    public $writeable;

    public function render()
    {
        return view('livewire.settings-modal-development');
    }
}
