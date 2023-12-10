<?php

namespace App\Livewire;

use Livewire\Component;

class SettingsModalPlugins extends Component
{
    public $writeable;

    public function boot() {}

    public function render()
    {
        return view('livewire.settings-modal-plugins');
    }
}
