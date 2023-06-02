<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class SettingsModalMaterials extends Component
{
    public $user;
    public $materials;

    protected $listeners = [ 'materialsChanged' => '$refresh' ];

    public function add() {
        if ($this->user) {
            $this->user->materials()->create([
                'name'          => null,
                'temperatures'  => [
                    'hotend' => 0,
                    'bed'    => 0
                ]
            ]);
        }
    }

    public function render()
    {
        $this->user = Auth::user();

        if ($this->user) {
            $this->materials = $this->user->materials()->get();
        }

        return view('livewire.settings-modal-materials');
    }
}
