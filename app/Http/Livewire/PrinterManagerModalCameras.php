<?php

namespace App\Http\Livewire;

use App\Models\Camera;

use Illuminate\Support\Arr;

use Illuminate\Support\Facades\Log;

use Livewire\Component;

class PrinterManagerModalCameras extends Component
{
    public $printer;

    public $availableCameras = [];
    public $assignedCameras  = [];

    public $lastError;

    protected $listeners = [ 'refreshPrinterCameras' => '$refresh' ];

    const NO_SUCH_CAMERA_ERROR = 'No such camera.';

    public function add($cameraId) {
        Log::debug( __METHOD__ . ': ' . $cameraId );

        $camera = Camera::select('_id')->find( $cameraId );

        if (!$camera) {
            $this->lastError = self::NO_SUCH_CAMERA_ERROR;

            return;
        }

        if (!in_array( $cameraId, $this->printer->cameras )) {
            $cameras = $this->printer->cameras;
            $cameras[] = $cameraId;

            $this->printer->cameras = $cameras;
            $this->printer->save();
        }

        $this->emit('refreshPrinterCameras');
    }

    public function remove($cameraId) {
        Log::debug( __METHOD__ . ': ' . $cameraId );

        $cameras = $this->printer->cameras;
        $cameras = Arr::where($cameras, function ($camera) use ($cameraId) {
            return $camera != $cameraId;
        });

        $this->printer->cameras = $cameras;
        $this->printer->save();

        $this->emit('refreshPrinterCameras');
    }

    public function render()
    {
        $cameras = Camera::all();

        if ($this->printer) {
            $this->availableCameras = $cameras->filter(function ($camera) {
                if (!$camera->enabled) return false;

                return !in_array( (string) $camera->_id, $this->printer->cameras );
            });

            $this->assignedCameras  = $cameras->filter(function ($camera) {
                if (!$camera->enabled) return false;

                return  in_array( (string) $camera->_id, $this->printer->cameras );
            });
        }

        return view('livewire.printer-manager-modal-cameras');
    }
}
