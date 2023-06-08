<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Livewire\Component;

class SettingsModalRecording extends Component
{
    public $enabled;

    public $resolution;
    public $framerate;
    public $captureInterval;

    public $resolutions;
    public $framerates;

    private $user;

    public function boot() {
        $this->user = Auth::user();
    }

    public function updated($field, $newValue) {
        Log::info( __METHOD__ . ': ' . $field . ' => ' . $newValue );

        if ($field == 'enabled') {
            $this->emit('recorderToggled');
        } else if ($field == 'captureInterval' && (!filled( $newValue ) || !is_numeric( $newValue ) || $newValue < .25)) {
            $newValue = .25;
        }

        if (is_numeric( $newValue )) {
            $newValue = (double) $newValue;
        }

        $settings = $this->user->settings;

        $settings['recording'][ $field ] = $newValue;

        $this->user->settings = $settings;
        $this->user->save();
    }

    public function render()
    {
        $this->enabled          = $this->user->settings['recording']['enabled'];
        $this->resolution       = $this->user->settings['recording']['resolution'];
        $this->framerate        = $this->user->settings['recording']['framerate'];
        $this->captureInterval  = $this->user->settings['recording']['captureInterval'];

        $this->resolutions  = config('app.recorder_output_resolutions');
        $this->framerates   = config('app.recorder_output_framerates');

        return view('livewire.settings-modal-recording');
    }
}
