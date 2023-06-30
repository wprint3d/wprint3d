<?php

namespace App\Http\Livewire;

use Livewire\Component;

class SettingsModalAbout extends Component
{
    public string $licenses;

    public function boot() {
        $this->licenses = file_get_contents( base_path() . '/THIRD_PARTY_LICENSES.txt' );
    }

    public function render()
    {
        return view('livewire.settings-modal-about');
    }
}
