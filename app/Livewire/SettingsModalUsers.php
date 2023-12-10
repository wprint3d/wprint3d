<?php

namespace App\Livewire;

use App\Enums\UserRole;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SettingsModalUsers extends Component
{
    public $users;
    public $selfUserId;

    protected $listeners = [ 'refreshUsers' ];

    public function refreshUsers() {
        $this->users = User::select('_id', 'name', 'email', 'password', 'role')->get();

        $this->selfUserId = Auth::user()->id;
    }

    public function boot() {
        $this->refreshUsers();
    }

    public function add() {
        User::create([
            'name'      => null,
            'email'     => null,
            'password'  => null,
            'role'      => UserRole::SPECTATOR,
            'settings'  => [
                'recording' => [
                    'enabled'           => true,
                    'resolution'        => '1280x720',
                    'framerate'         => 30,
                    'captureInterval'   => 0.25
                ]
            ]
        ]);

        $this->refreshUsers();
    }

    public function render()
    {
        return view('livewire.settings-modal-users');
    }
}
