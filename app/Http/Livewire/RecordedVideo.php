<?php

namespace App\Http\Livewire;

use Livewire\Component;

class RecordedVideo extends Component
{
    public $index;
    public $recording;

    public $writeable = false;

    public function play() {
        $this->emit('showVideoPlayer', $this->recording['url']);
    }

    public function prepareDeleteRecording() {
        $this->emit('prepareDeleteRecording', $this->index, $this->recording['name']);
    }

    public function render()
    {
        return view('livewire.recorded-video');
    }
}
