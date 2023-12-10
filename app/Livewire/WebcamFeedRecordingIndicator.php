<?php

namespace App\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class WebcamFeedRecordingIndicator extends Component
{
    public $printerId;
    public $camera;

    public $isRecording;

    private $printer;

    protected $listeners = [
        'linkedCamerasChanged'   => '$refresh',
        'hardwareChangeDetected' => '$refresh',
        'refreshActiveFile'      => '$refresh'
    ];

    public function render()
    {
        $this->printer = Printer::select('recordableCameras', 'activeFile')->find( $this->printerId );

        $this->isRecording =
            in_array( (string) $this->camera->_id, $this->printer->recordableCameras ) // is recordable
            &&
            Auth::user()->settings['recording']['enabled']                             // recording enabled
            &&
            $this->printer->activeFile;                                                // the printer has an active file

        return view('livewire.webcam-feed-recording-indicator');
    }
}
