<?php

namespace App\Http\Livewire;

use App\Libraries\Convert;
// use App\Libraries\HardwareCamera;

use Livewire\Component;

class WebcamFeed extends Component
{
    public $url;
    public $snapshot;

    public $camera;
    public $isRecording;

    protected $listeners = [ 'hardwareChangeDetected' => '$refresh' ];

    public function render()
    {
        // TODO: Implement snapshot-based feed.
        // $hardwareCamera = new HardwareCamera();

        // $this->snapshot = Convert::toDataURI( $hardwareCamera->takeSnapshot() );

        if ($this->camera) {
            $this->url = $this->camera->url;
        }

        return view('livewire.webcam-feed');
    }
}
