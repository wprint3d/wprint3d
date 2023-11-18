<?php

namespace App\Http\Livewire;

use App\Events\SystemMessage;

use App\Jobs\PrintGcode;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Livewire\Component;

class FileControls extends Component
{

    public $selected    = null;
    public $newFilename = null;
    public $error       = null;

    public $writeable   = false;

    protected $baseFilesDir;

    public ?Printer $printer    = null;
    public ?string  $printerId  = null;
    public ?string  $activeFile = null;

    protected $listeners = [ 'prepareFile', 'selectPrinter', 'refreshActiveFile' ];

    const NO_FILE_SELECTED_ERROR = 'A file must be selected before submitting this form.';

    private function refreshPrinter() {
        $activePrinter = Auth::user()->getActivePrinter();

        Log::debug( __METHOD__ . ': ' . ($activePrinter ?? 'none') );

        if ($activePrinter) {
            $this->printer = Printer::select('activeFile')->find( $activePrinter );
        }
    }

    public function refreshActiveFile() {
        $this->refreshPrinter();

        if ($this->printer) {
            $newActiveFile = $this->printer->activeFile ?? null;

            if ($this->activeFile && !$newActiveFile) {
                SystemMessage::send('targetTemperatureReset');
            }

            $this->activeFile = $newActiveFile;

            if ($this->activeFile && !$this->printer->hasActiveJob) {
                $this->printer->hasActiveJob = true;
                $this->printer->save();
            }
        }
    }

    public function prepareFile($fileName) {
        Log::info('prepare: ' . $fileName);

        $this->selected     = $fileName;
        $this->newFilename  = basename($fileName);
    }

    public function selectPrinter() {
        $this->refreshPrinter();
    }

    public function boot() {
        $this->baseFilesDir = env('BASE_FILES_DIR');

        $this->refreshActiveFile();
    }

    public function hydrate() {
        $this->refreshActiveFile();
    }

    public function start() {
        if (!$this->selected) {
            $this->error = self::NO_FILE_SELECTED_ERROR;

            return;
        }

        // Reset the printer's paused state in case it was left paused.
        $this->printer->resume();

        PrintGcode::dispatch(
            $this->selected,    // filePath
            Auth::user(),       // owner
            $this->printer->_id // printerId
        );

        $this->printer->hasActiveJob = true;
        $this->printer->activeFile   = $this->selected;
        $this->printer->save();

        SystemMessage::send('refreshActiveFile');

        $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'start' ]);
    }

    public function stop() {
        $this->printer->hasActiveJob = false;
        $this->printer->activeFile   = null;
        $this->printer->save();

        SystemMessage::send('refreshActiveFile');

        $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'stop' ]);
    }

    public function delete() {
        if (!$this->selected) {
            $this->error = self::NO_FILE_SELECTED_ERROR;

            return;
        }

        $this->printer->refresh();

        if ($this->printer->activeFile == $this->selected) {
            $this->error = 'The active file can\'t be deleted.';

            return;
        }

        $selectedFullPath = $this->selected;

        Storage::delete($selectedFullPath);

        Log::debug( __METHOD__ . ': ' . $selectedFullPath);

        $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'delete' ]);

        SystemMessage::send('refreshUploadedFiles');
    }

    public function pause() {
        $this->printer->pause();
    }

    public function resume() {
        $this->printer->resume();
    }

    public function rename() {
        if (!$this->selected) {
            $this->error = self::NO_FILE_SELECTED_ERROR;
        }

        if (!$this->newFilename) {
            $this->error = 'A new filename must be provided before submitting this form.';

            return;
        }

        $this->printer->refresh();

        if ($this->printer->activeFile == $this->selected) {
            $this->error = 'The active file can\'t be renamed.';

            return;
        }

        $selectedFullPath   = $this->selected;
        $targetFullPath     = dirname($this->selected) . '/' . $this->newFilename;

        if (!Storage::exists( $selectedFullPath )) {
            $this->error = 'No such file or directory.';

            return;
        }

        if (Storage::exists( $targetFullPath ) && $selectedFullPath != $targetFullPath) {
            $this->error = 'There\'s another file with that name.';

            return;
        }

        Storage::move($selectedFullPath, $targetFullPath);

        Log::debug( __METHOD__ . ': ' . $selectedFullPath . ' => ' . $targetFullPath);

        $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'rename' ]);

        SystemMessage::send('refreshUploadedFiles');

        $this->emit('selectUploadedFile', $this->newFilename);
    }

    public function render()
    {
        return view('livewire.file-controls');
    }

}
