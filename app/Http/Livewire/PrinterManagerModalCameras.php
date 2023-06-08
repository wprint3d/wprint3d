<?php

namespace App\Http\Livewire;

use App\Models\Camera;

use Illuminate\Support\Arr;

use Illuminate\Support\Facades\Log;

use Livewire\Component;

class PrinterManagerModalCameras extends Component
{
    public $printer;

    public $availableCameras  = [];
    public $assignedCameras   = [];
    public $recordableCameras = [];

    public $lastError;

    protected $listeners = [ 'hardwareChangeDetected' => '$refresh' ];

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

        $this->emit('linkedCamerasChanged');
    }

    public function remove($cameraId) {
        Log::debug( __METHOD__ . ': ' . $cameraId );

        $cameras = $this->printer->cameras;
        $cameras = Arr::where($cameras, function ($camera) use ($cameraId) {
            return $camera != $cameraId;
        });

        $this->printer->cameras = $cameras;
        $this->printer->save();

        $this->emit('linkedCamerasChanged');
    }

    public function toggleRecordable($cameraId) {
        Log::debug( __METHOD__ . ': ' . $cameraId );

        $camera = Camera::select('_id')->find( $cameraId );

        if (!$camera) {
            $this->lastError = self::NO_SUCH_CAMERA_ERROR;

            return;
        }

        // WTF??? I mean... just in case, y'know.
        if (!in_array( $cameraId, $this->printer->cameras )) return;

        $recordableCameras = $this->printer->recordableCameras;

        if (in_array( $cameraId, $this->printer->recordableCameras )) {
            $recordableCameras = Arr::where($recordableCameras, function ($camera) use ($cameraId) {
                return $camera != $cameraId;
            });
        } else {
            $recordableCameras[] = $cameraId;
        }

        $this->printer->recordableCameras = $recordableCameras;
        $this->printer->save();

        $this->emit('linkedCamerasChanged');
    }

    public function render()
    {
        $cameras = Camera::all();

        if ($this->printer) {
            $this->availableCameras  = $cameras->filter(function ($camera) {
                if (!$camera->enabled) return false;

                return !in_array( (string) $camera->_id, $this->printer->cameras );
            });

            $this->assignedCameras   = $cameras->filter(function ($camera) {
                if (!$camera->enabled) return false;

                return  in_array( (string) $camera->_id, $this->printer->cameras );
            });

            if (isset( $this->printer->recordableCameras )) {
                foreach ($this->assignedCameras as $index => $assignedCamera) {
                    $this->assignedCameras[ $index ]->recordable = in_array( (string) $assignedCamera->_id, $this->printer->recordableCameras );
                }
            }
        }

        return view('livewire.printer-manager-modal-cameras');
    }
}
