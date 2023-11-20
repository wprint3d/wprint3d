<?php

namespace App\Http\Livewire;

use App\Models\Camera;

use Livewire\Component;

class SettingsModalCameras extends Component
{
    public $cameras;

    public $writeable = false;

    protected $listeners = [ 'hardwareChangeDetected' => 'refreshCameras' ];

    public function refreshCameras() {
        $this->cameras = Camera::all();

        $this->emit('cameraSettingsChanged');
    }

    public function boot() {
        $this->refreshCameras();
    }

    public function render()
    {
        return view('livewire.settings-modal-cameras');
    }
}
