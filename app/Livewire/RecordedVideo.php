<?php

namespace App\Livewire;

use Livewire\Component;

class RecordedVideo extends Component
{
    public $index;
    public $recording;

    public $writeable = false;

    public function play() {
        $this->dispatch('showVideoPlayer', src: $this->recording['url']);
    }

    public function prepareDeleteRecording() {
        $this->dispatch('prepareDeleteRecording',
            index:  $this->index,
            name:   $this->recording['name']
        );
    }

    public function render()
    {
        return view('livewire.recorded-video');
    }
}
