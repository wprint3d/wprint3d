<?php

namespace App\Http\Livewire;

use App\Models\Camera;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class WebcamFeeds extends Component
{
    public $printer = null;
    public $cameras = [];

    protected $listeners = [
        'linkedCamerasChanged'   => '$refresh',
        'hardwareChangeDetected' => '$refresh'
    ];

    public function render()
    {
        $printerId = Auth::user()->getActivePrinter();

        if ($printerId) {
            $this->printer = Printer::select('cameras')->find( $printerId );

            if ($this->printer) {
                $this->cameras = Camera::where('enabled', true)->get()->filter(function ($camera) {
                    return in_array( (string) $camera->_id, $this->printer->cameras );
                });

                $this->dispatchBrowserEvent('webcamFeedsChanged');
            }
        }

        return view('livewire.webcam-feeds');
    }
}
