<?php

namespace App\Http\Livewire;

use App\Models\Camera;

use Livewire\Component;

class SettingsModalCameras extends Component
{
    public $cameras;

    public function boot() {
        $this->cameras = Camera::all();
    }

    public function render()
    {
        return view('livewire.settings-modal-cameras');
    }
}
