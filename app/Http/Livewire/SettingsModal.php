<?php

namespace App\Http\Livewire;

use App\Enums\UserRole;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class SettingsModal extends Component
{
    public array $availablePanes;

    public $isPrinting;

    public $role;
    public $writeable = false;

    public function boot() {
        $this->availablePanes = [ 'printers', 'materials', 'cameras', 'recording', 'system', 'users', 'about' ];

        $user = Auth::user();

        $this->role      = $user->role;
        $this->writeable = $user->role == UserRole::ADMINISTRATOR;

        if (!$this->writeable) {
            $this->availablePanes = [ 'printers', 'cameras', 'recording', 'system', 'about' ];
        }
    }

    public function render()
    {
        $this->isPrinting = Printer::whereNotNull('activeFile')->count() > 0;

        return view('livewire.settings-modal');
    }
}
