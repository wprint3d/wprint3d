<?php

namespace App\Http\Livewire;

use App\Jobs\RenderVideo;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Livewire\Component;

use CodeInc\HumanReadableFileSize\HumanReadableFileSize;

use Exception;

class RecordingsTab extends Component
{
    public $recordings;

    public $error;
    public $selected = null;

    protected $listeners = [
        'selectPrinter'     => '$refresh',
        'refreshActiveFile' => '$refresh',
        'recorderToggled'   => '$refresh',
        'refreshRecordings' => '$refresh'
    ];

    private function refreshRecordings() {
        $fileSize = new HumanReadableFileSize();
        $fileSize->setSpaceBeforeUnit();

        $files = Storage::files( RenderVideo::RECORDINGS_DIRECTORY );

        // Force date descending sort
        usort($files, function ($fileA, $fileB) {
            return
                Storage::lastModified( $fileA )
                <
                Storage::lastModified( $fileB );
        });

        $this->recordings = array_values(
            Arr::map(
                array: Arr::where(
                    array:      $files,
                    callback:   function ($file) {
                        return str_ends_with($file, '.webm');
                    }
                ),
                callback: function ($file) use ($fileSize) {
                    $basename = preg_replace('/.webm$/', '', basename($file));

                    return [
                        'url'       => env('APP_URL') . '/' . RenderVideo::RECORDINGS_DIRECTORY . '/' . $basename . '.webm',
                        'thumb'     => '/' . RenderVideo::RECORDINGS_DIRECTORY . '/' . $basename . '.jpg',
                        'name'      => preg_replace('/.webm$/', '', $basename),
                        'size'      => $fileSize->compute( Storage::size( $file ) ),
                        'modified'  => Carbon::createFromTimestamp(
                            Storage::lastModified( $file )
                        )->diffForHumans()
                    ];
                }
            )
        );
    }

    public function play($index) {
        Log::info( __METHOD__ . ': ' . json_encode($this->recordings) );

        $this->emit('showVideoPlayer', $this->recordings[ $index ]['url']);
    }

    public function prepareDelete($index) {
        $this->error    = null; 
        $this->selected = $index;

        $this->dispatchBrowserEvent('showRecordingDeleteModal');
    }

    public function delete() {
        try {
            Storage::delete(
                RenderVideo::RECORDINGS_DIRECTORY . '/' . $this->recordings[ $this->selected ]['name'] . '.webm'
            );

            Storage::delete(
                RenderVideo::RECORDINGS_DIRECTORY . '/' . $this->recordings[ $this->selected ]['name'] . '.jpg'
            );

            $this->refreshRecordings();

            $this->dispatchBrowserEvent('recordingDeleted');
        } catch (Exception $exception) {
            Log::error(
                $exception->getMessage() . PHP_EOL .
                $exception->getTraceAsString()
            );

            $this->error = $exception->getMessage();
        }
    }

    public function render()
    {
        $this->refreshRecordings();

        return view('livewire.recordings-tab');
    }
}
