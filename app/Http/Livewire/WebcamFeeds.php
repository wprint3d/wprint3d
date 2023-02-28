<?php

namespace App\Http\Livewire;

use App\Models\Camera;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class WebcamFeeds extends Component
{
    public $printer;
    public $cameras = [];

    public function boot() {
        $printerId = Auth::user()->activePrinter;

        if ($printerId) {
            $this->printer = Printer::select('cameras')->find( $printerId );

            if ($this->printer) {
                $this->cameras = Camera::where('enabled', true)->get()->filter(function ($camera) {
                    return in_array( (string) $camera->_id, $this->printer->cameras );
                });
            }
        }
    }

    public function render()
    {
        return view('livewire.webcam-feeds');
    }
}
