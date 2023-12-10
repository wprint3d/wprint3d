<?php

namespace App\Livewire;

use App\Events\SystemMessage;
use App\Jobs\RenderVideo;

use App\Models\Configuration;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use CodeInc\HumanReadableFileSize\HumanReadableFileSize;

use Livewire\Component;

use Exception;
use Livewire\Attributes\On;

class RecordingsTab extends Component
{

    public $recordings = [];

    public $firstLoad = true;

    public $writeable = false;

    private int $renderFileBlockingSecs;

    protected $listeners = [
        'selectPrinter'         => '$refresh',
        'initialize'            => 'initialize',
        'refreshActiveFile'     => '$refresh',
        'recorderToggled'       => '$refresh',
        'refreshRecordings'     => '$refresh'
    ];

    private function refreshRecordings() {
        $this->firstLoad = false;

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

        // FIXME: move recorded item render logic to RecordedVideo
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

                    $lastModified = Storage::lastModified( $file );

                    $file = [
                        'url'       => request()->schemeAndHttpHost() . '/' . RenderVideo::RECORDINGS_DIRECTORY . '/' . $basename . '.webm',
                        'thumb'     => '/' . RenderVideo::RECORDINGS_DIRECTORY . '/' . $basename . '.jpg',
                        'name'      => preg_replace('/.webm$/', '', $basename),
                        'size'      => $fileSize->compute( Storage::size( $file ) ),
                        'modified'  => Carbon::createFromTimestamp( $lastModified )->diffForHumans()
                    ];

                    if (time() - $lastModified <  $this->renderFileBlockingSecs) {
                        $file['deletable'] = false;
                    }

                    return $file;
                }
            )
        );
    }

    private function validateRecordingName(string $name): bool {
        foreach ($this->recordings as $recording) {
            if ($recording['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    #[On('deleteRecordingByName')]
    public function deleteRecordingByName($name) {
        try {
            if (!$this->validateRecordingName( $name )) {
                throw new Exception('No such recording.');
            }

            Storage::delete(
                RenderVideo::RECORDINGS_DIRECTORY . '/' . $name . '.webm'
            );

            Storage::delete(
                RenderVideo::RECORDINGS_DIRECTORY . '/' . $name . '.jpg'
            );

            $this->refreshRecordings();

            SystemMessage::send('recordingDeleted');
        } catch (Exception $exception) {
            Log::error(
                $exception->getMessage() . PHP_EOL .
                $exception->getTraceAsString()
            );

            $this->dispatch('recordingDeleteModalError', message: $exception->getMessage());
        }
    }

    public function boot() {
        $this->firstLoad              = true;
        $this->renderFileBlockingSecs = Configuration::get('renderFileBlockingSecs');
    }

    public function initialize() {
        $this->refreshRecordings();
    }

    public function render()
    {
        return view('livewire.recordings-tab');
    }
}
