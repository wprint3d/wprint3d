<?php

namespace App\Http\Livewire;

use Livewire\Component;

class VideoPlayerModal extends Component
{
    protected $listeners = [ 'showVideoPlayer' => 'openURL' ];

    public function openURL($src) {
        $this->dispatchBrowserEvent('openVideoURL', $src);
    }

    public function render()
    {
        return view('livewire.video-player-modal');
    }
}
