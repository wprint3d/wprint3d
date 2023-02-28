<?php

namespace App\Http\Livewire;

use App\Libraries\Convert;
use App\Libraries\HardwareCamera;

use Livewire\Component;

class WebcamFeed extends Component
{
    public $url;
    public $snapshot;

    public $camera;

    public function render()
    {
        $hardwareCamera = new HardwareCamera();

        $this->snapshot = Convert::toDataURI( $hardwareCamera->takeSnapshot() );

        if ($this->camera) {
            $this->url = env('WEBCAM_BASE_URL') . '/' . $this->camera->node;
        }

        return view('livewire.webcam-feed');
    }
}
