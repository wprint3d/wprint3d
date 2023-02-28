<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Log;
use Livewire\Component;

class UploadedFiles extends Component
{
    public $selected = null;

    protected $listeners = [
        'refreshUploadedFiles'  => '$refresh',
        'selectUploadedFile'    => 'select'
    ];

    public function select($fileName) {
        Log::debug( __METHOD__ . ': ' . $fileName );

        $this->selected = $fileName;

        $this->emit('prepareFile', $fileName);
    }

    public function render()
    {
        return view('livewire.uploaded-files');
    }
}
