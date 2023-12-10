<?php

namespace App\Livewire;

use App\Events\SystemMessage;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Livewire\Component;

use Exception;

class CreateFolderModal extends Component
{
    public $name;

    const BLOCKED_SYMBOLS = [ '/', '\\', '|', '&', '@', ':', '"', '\'', '>', '<', '?' ];
    const BLOCKED_NAMES   = [ '..', '.', 'CON', 'AUX', 'PRX' ];

    public function createFolder() {
        $this->name = trim($this->name);

        if (!$this->name) {
            $this->dispatch('folderCreationError', message: 'the folder name cannot be empty.');

            return;
        }

        if (Str::contains($this->name, self::BLOCKED_SYMBOLS)) {
            $this->dispatch('folderCreationError', message: 'the folder name cannot contain any of the following symbols: <b>' . implode('</b>, <b>', self::BLOCKED_SYMBOLS) . '.');

            return;
        }

        if (in_array( $this->name, self::BLOCKED_NAMES )) {
            $this->dispatch('folderCreationError', message: 'the folder name must not be exactly any of these strings: <b>' . implode('</b>, <b>', self::BLOCKED_NAMES) . '.');

            return;
        }

        try {
            $newPath = Auth::user()->getCurrentFolder() . '/' . $this->name;

            if (Storage::exists( $newPath )) {
                $this->dispatch('folderCreationError', message: 'there\'s another folder with that name.');

                return;
            }

            if (Storage::makeDirectory( $newPath )) {
                $this->dispatch('folderCreationCompleted', name: $this->name);

                SystemMessage::send('folderCreationCompleted');
            }
        } catch (Exception $exception) {
            $this->dispatch('folderCreationError', message: $exception->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.create-folder-modal');
    }
}
