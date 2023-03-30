<?php

namespace App\Http\Livewire;

use Livewire\Component;

use Livewire\WithFileUploads;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileUploader extends Component
{
    use WithFileUploads;

    protected $baseFilesDir;

    public $gcode;

    public function boot() {
        $this->baseFilesDir = env('BASE_FILES_DIR');
    }

    public function updatedGcode() {
        $this->validate([
            'gcode' => 'min:0' // max:1048576', // 1GB Max TODO: Implement this!
        ]);

        $uid = ''; $storedFileName = (
            pathinfo($this->gcode->getClientOriginalName(), PATHINFO_FILENAME)
        );

        while (Storage::exists($this->baseFilesDir . '/' . $storedFileName . $uid)) {
            $uid = '-' . uniqid();
        }

        $fullName = $storedFileName . $uid;

        $this->gcode->storeAs($this->baseFilesDir, $fullName);

        Log::debug( __METHOD__ . ': ' . $fullName );

        $this->emit('refreshUploadedFiles');
        $this->emit('selectUploadedFile', $fullName);
        $this->dispatchBrowserEvent('fileUploadFinished');
    }

    public function render()
    {
        return view('livewire.file-uploader');
    }
}
