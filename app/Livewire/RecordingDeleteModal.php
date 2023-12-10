<?php

namespace App\Livewire;

use Livewire\Attributes\On;

use Livewire\Component;

class RecordingDeleteModal extends Component
{
    public $error;
    public $name     = null;
    public $selected = null;

    #[On('prepareDeleteRecording')]
    public function prepareDelete($index, $name) {
        $this->error    = null; 
        $this->name     = $name;
        $this->selected = $index;

        $this->dispatch('showRecordingDeleteModal');
    }

    #[On('recordingDeleteModalError')]
    public function setError($message) {
        $this->error = $message;
    }

    public function delete() {
        $this->dispatch('deleteRecordingByName', name: $this->name);
    }

    public function render()
    {
        return view('livewire.recording-delete-modal');
    }
}
