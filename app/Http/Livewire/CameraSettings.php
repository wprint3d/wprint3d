<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Log;

use Livewire\Component;

class CameraSettings extends Component
{
    public $camera;

    public $enabled;
    public $format;
    public $url;

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

        $this->emit('cameraSettingsChanged');
    }

    public function render()
    {
        Log::debug( __METHOD__ . ': ' . json_encode($this->camera) );

        if ($this->camera) {
            $this->enabled  = $this->camera->enabled;
            $this->format   = $this->camera->format;
            $this->url      = env('WEBCAM_BASE_URL') . '/' . $this->camera->node;
        }

        return view('livewire.camera-settings');
    }
}
