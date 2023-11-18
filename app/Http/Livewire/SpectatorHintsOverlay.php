<?php

namespace App\Http\Livewire;

use App\Enums\UserRole;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class SpectatorHintsOverlay extends Component
{

    public $isSpectator;

    public function boot() {
        $user = Auth::user();

        $this->isSpectator = $user && $user->role == UserRole::SPECTATOR;
    }

    public function render()
    {
        return view('livewire.spectator-hints-overlay');
    }

}
