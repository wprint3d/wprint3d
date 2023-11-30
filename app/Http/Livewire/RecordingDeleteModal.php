<?php

namespace App\Http\Livewire;

use Livewire\Component;

class RecordingDeleteModal extends Component
{
    public $error;
    public $name     = null;
    public $selected = null;

    protected $listeners = [
        'prepareDeleteRecording'    => 'prepareDelete',
        'recordingDeleteModalError' => 'setError'
    ];

    public function prepareDelete($index, $name) {
        $this->error    = null; 
        $this->name     = $name;
        $this->selected = $index;

        $this->dispatchBrowserEvent('showRecordingDeleteModal');
    }

    public function setError($message) {
        $this->error = $message;
    }

    public function delete() {
        $this->emit('deleteRecordingByName', $this->name);
    }

    public function render()
    {
        return view('livewire.recording-delete-modal');
    }
}
