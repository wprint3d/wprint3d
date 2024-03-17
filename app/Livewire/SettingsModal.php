<?php

namespace App\Livewire;

use App\Enums\UserRole;

use App\Models\Configuration;
use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class SettingsModal extends Component
{
    public array $availablePanes;

    public $isPrinting;

    public $role;
    public $writeable = false;

    protected $listeners = [ 'refreshActiveFile' => 'checkForActivePrinters' ];

    public function boot() {
        $user = Auth::user();

        if (!$user) { return; }

        $this->role      = $user->role;
        $this->writeable = $user->role == UserRole::ADMINISTRATOR;

        $this->availablePanes = [ 'printers', 'cameras', 'recording', 'system', 'about' ];

        if ($this->writeable) {
            $this->availablePanes = [ 'printers', 'materials', 'cameras', 'recording', 'system', 'users' ];

            if (Configuration::get('developerMode')) {
                $this->availablePanes[] = 'development';
            }

            $this->availablePanes[] = 'about';
        }
    }

    public function checkForActivePrinters() {
        $this->isPrinting = Printer::whereNotNull('activeFile')->count() > 0;
    }

    public function render()
    {
        return view('livewire.settings-modal');
    }
}
