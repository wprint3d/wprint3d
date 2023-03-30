<?php

namespace App\Http\Livewire;

use App\Jobs\PrintGcode;
use App\Models\Printer;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileControls extends Component
{

    public $selected    = null;
    public $newFilename = null;
    public $error       = null;

    protected $baseFilesDir;

    public ?Printer $printer    = null;
    public ?string  $printerId  = null;
    public ?string  $activeFile = null;

    protected $listeners = [ 'prepareFile', 'selectPrinter', 'refreshActiveFile' ];

    const NO_FILE_SELECTED_ERROR = 'A file must be selected before submitting this form.';

    private function refreshPrinter() {
        $this->printer = Printer::select('activeFile')->find( Auth::user()->activePrinter );
    }

    public function refreshActiveFile() {
        $this->refreshPrinter();

        if ($this->printer) {
            $newActiveFile = $this->printer->activeFile ?? null;

            if ($this->activeFile && !$newActiveFile) {
                $this->dispatchBrowserEvent('targetTemperatureReset');
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

        $this->refreshActiveFile();

        $this->selected     = $fileName;
        $this->newFilename  = $fileName;
    }

    public function selectPrinter() {
        Log::debug( __METHOD__ . ': ' . (Auth::user()->activePrinter ?? 'none') );

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

        PrintGcode::dispatch(
            fileName: $this->selected,
            gcode:    Storage::get( $this->baseFilesDir . '/' . $this->selected )
        );

        $this->printer->hasActiveJob = true;
        $this->printer->activeFile   = $this->selected;
        $this->printer->save();

        // Reset the printer's paused state in case it was left paused.
        $this->printer->resume();

        $this->refreshActiveFile();

        $this->emit('refreshActiveFile');

        $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'start' ]);
    }

    public function stop() {
        $this->printer->hasActiveJob = false;
        $this->printer->activeFile   = null;
        $this->printer->save();

        $this->refreshActiveFile();

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

        $selectedFullPath = $this->baseFilesDir . '/' . $this->selected;

        Storage::delete($selectedFullPath);

        Log::debug( __METHOD__ . ': ' . $selectedFullPath);

        $this->dispatchBrowserEvent('changeSaved', [ 'action' => 'delete' ]);

        $this->emit('refreshUploadedFiles');
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

        $selectedFullPath   = $this->baseFilesDir . '/' . $this->selected;
        $targetFullPath     = $this->baseFilesDir . '/' . $this->newFilename;

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

        $this->emit('refreshUploadedFiles');
        $this->emit('selectUploadedFile', $this->newFilename);
    }

    public function render()
    {
        return view('livewire.file-controls');
    }

}
