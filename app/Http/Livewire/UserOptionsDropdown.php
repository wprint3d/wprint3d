<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class UserOptionsDropdown extends Component
{
    public function logout() {
        Auth::logout();

        return redirect('/');
    }

    public function render()
    {
        return view('livewire.user-options-dropdown');
    }
}
