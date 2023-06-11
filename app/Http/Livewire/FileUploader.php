<?php

namespace App\Http\Livewire;

use App\Events\SystemMessage;

use Livewire\Component;

use Livewire\WithFileUploads;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileUploader extends Component
{
    use WithFileUploads;

    public $gcode;

    public function updatedGcode() {
        $baseFilesDir = Auth::user()->getCurrentFolder();

        $this->validate([
            'gcode' => 'min:0' // max:1048576', // 1GB Max TODO: Implement this!
        ]);

        $uid = ''; $storedFileName = (
            pathinfo($this->gcode->getClientOriginalName(), PATHINFO_FILENAME)
        );

        while (Storage::exists($baseFilesDir . '/' . $storedFileName . $uid)) {
            $uid = '-' . uniqid();
        }

        $fullName = $storedFileName . $uid;

        $this->gcode->storeAs($baseFilesDir, $fullName);

        Log::debug( __METHOD__ . ': ' . $fullName );

        SystemMessage::send('refreshUploadedFiles');

        $this->emit('selectUploadedFile', $fullName);

        $this->dispatchBrowserEvent('fileUploadFinished');
    }

    public function render()
    {
        return view('livewire.file-uploader');
    }
}
