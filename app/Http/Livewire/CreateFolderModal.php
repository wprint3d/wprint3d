<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Livewire\Component;

use Illuminate\Support\Facades\Auth;

use Exception;

class CreateFolderModal extends Component
{
    public $name;

    const BLOCKED_SYMBOLS = [ '/', '\\', '|', '&', '@', ':', '"', '\'', '>', '<', '?' ];
    const BLOCKED_NAMES   = [ '..', '.', 'CON', 'AUX', 'PRX' ];

    public function createFolder() {
        $this->name = trim($this->name);

        if (!$this->name) {
            $this->dispatchBrowserEvent('folderCreationError', 'the folder name cannot be empty.');

            return;
        }

        if (Str::contains($this->name, self::BLOCKED_SYMBOLS)) {
            $this->dispatchBrowserEvent('folderCreationError', 'the folder name cannot contain any of the following symbols: <b>' . implode('</b>, <b>', self::BLOCKED_SYMBOLS) . '.');

            return;
        }

        if (in_array( $this->name, self::BLOCKED_NAMES )) {
            $this->dispatchBrowserEvent('folderCreationError', 'the folder name must not be exactly any of these strings: <b>' . implode('</b>, <b>', self::BLOCKED_NAMES) . '.');

            return;
        }

        try {
            $newPath = Auth::user()->getCurrentFolder() . '/' . $this->name;

            if (Storage::makeDirectory( $newPath )) {
                $this->dispatchBrowserEvent('folderCreationCompleted', $this->name);
                $this->emit('selectedPathChanged', $newPath);
            }
        } catch (Exception $exception) {
            $this->dispatchBrowserEvent('folderCreationError', $exception->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.create-folder-modal');
    }
}
