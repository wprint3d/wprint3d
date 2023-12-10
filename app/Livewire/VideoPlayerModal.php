<?php

namespace App\Livewire;

use Livewire\Attributes\On;

use Livewire\Component;

class VideoPlayerModal extends Component
{
    #[On('showVideoPlayer')]
    public function openURL($src) {
        $this->dispatch('openVideoURL', src: $src);
    }

    public function render()
    {
        return view('livewire.video-player-modal');
    }
}
