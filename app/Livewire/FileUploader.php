<?php

namespace App\Livewire;

use App\Enums\ToastMessageType;

use App\Events\ToastMessage;

use Livewire\Component;

use Livewire\WithFileUploads;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Bayfront\MimeTypes\MimeType;

class FileUploader extends Component
{
    use WithFileUploads;

    public $gcode;

    public function updatedGcode() {
        $baseFilesDir = Auth::user()->getCurrentFolder();

        $this->validate([
            'gcode' => 'min:0' // max:1048576', // 1GB Max TODO: Implement this!
        ]);

        $fileMimeType = $this->gcode->getMimeType();

        if ($fileMimeType !== MimeType::fromExtension('txt')) {
            ToastMessage::dispatch(
                Auth::id(),                                                                     // userId
                ToastMessageType::ERROR,                                                        // type
                "Couldn't upload file: the type \"<b>{$fileMimeType}</b>\" is not compatible."  // message
            );

            return false;
        }

        $uid = ''; $storedFileName = (
            pathinfo($this->gcode->getClientOriginalName(), PATHINFO_FILENAME)
        );

        while (Storage::exists($baseFilesDir . '/' . $storedFileName . $uid)) {
            $uid = '-' . uniqid();
        }

        $fullName = $storedFileName . $uid;

        $this->gcode->storeAs($baseFilesDir, $fullName);

        Log::debug( __METHOD__ . ': ' . $fullName );

        $this->dispatch('selectUploadedFile', name: $fullName);

        $this->dispatch('fileUploadFinished');
    }

    public function render()
    {
        return view('livewire.file-uploader');
    }
}
