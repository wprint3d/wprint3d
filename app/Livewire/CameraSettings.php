<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Livewire\Component;

class CameraSettings extends Component
{
    public $camera;

    public $connected;
    public $enabled;
    public $format;
    public $url;

    public $writeable = false;

    protected $listeners = [ 'cameraSettingsChanged' => '$refresh' ];

    public function updated($field, $newValue) {
        switch ($field) {
            case 'enabled':
                $this->camera->enabled  = boolval($newValue);

                break;
            case 'format':
                $this->camera->format   = (string) $newValue;

                break;
        }

        $this->camera->save();

        Storage::disk('internal')->put('.requires_camera_detection', '');

        $this->dispatch('cameraSettingsChanged');
    }

    public function render()
    {
        Log::debug( __METHOD__ . ': ' . json_encode($this->camera) );

        if ($this->camera) {
            $this->connected = $this->camera->connected;
            $this->enabled   = $this->camera->enabled;
            $this->format    = $this->camera->format;
            $this->url       = $this->camera->url . '?action=stream';
        }

        return view('livewire.camera-settings');
    }
}
