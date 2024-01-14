<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;

use Livewire\Attributes\On;

use Livewire\Component;

class ThemeManager extends Component
{
    public $theme;

    #[On('reloadTheme')]
    public function reloadTheme() {
        $user = Auth::user();

        if ($user) {
            $this->theme = $user->theme;

            $this->dispatch('themeReloaded');
        }
    }

    public function mount() {
        $this->reloadTheme();
    }

    public function render()
    {
        return view('livewire.theme-manager');
    }
}
