<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;

use Livewire\Component;

class UpdatingHardwareOverlay extends Component
{
    public $show;

    protected $listeners = [
        'hardwareChangeDetected' => '$refresh',
        'refreshMapperStatus'    => '$refresh'
    ];

    public function render()
    {
        $this->show = Cache::get( config('cache.mapper_busy_key') );

        return view('livewire.updating-hardware-overlay');
    }
}
