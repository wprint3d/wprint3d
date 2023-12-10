<?php

namespace App\Livewire;

use App\Enums\ToastMessageType;

use App\Events\SystemMessage;
use App\Events\ToastMessage;

use App\Jobs\PrintGcode;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
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

    protected $listeners = [ 'selectPrinter', 'refreshActiveFile' ];

    const NO_FILE_SELECTED_ERROR = 'A file must be selected before submitting this form.';

    private function refreshPrinter() {
        $activePrinter = Auth::user()->getActivePrinter();

        Log::debug( __METHOD__ . ': ' . ($activePrinter ?? 'none') );

        if ($activePrinter) {
            $this->printer = Printer::select('connected', 'activeFile')->find( $activePrinter );
        }
    }

    public function refreshActiveFile() {
        $this->refreshPrinter();

        if ($this->printer) {
            $this->activeFile = $this->printer->activeFile ?? null;

            if ($this->activeFile && !$this->printer->hasActiveJob) {
                $this->printer->hasActiveJob = true;
                $this->printer->save();
            }
        }
    }

    #[On('prepareFile')]
    public function prepareFile($name) {
        Log::info('prepare: ' . $name);

        $this->selected     = $name;
        $this->newFilename  = basename($name);
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

        if (!$this->printer->connected) {
            ToastMessage::dispatch(
                Auth::id(),                                             // userId
                ToastMessageType::ERROR,                                // type
                'Couldn\'t queue job: this printer is not connected.'   // message
            );

            $this->dispatch('changeSaved', action: 'start');

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

        $this->dispatch('changeSaved', action: 'start');
    }

    public function stop() {
        $this->printer->hasActiveJob = false;
        $this->printer->activeFile   = null;
        $this->printer->save();

        SystemMessage::send('refreshActiveFile');

        $this->dispatch('changeSaved', action: 'stop');
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

        $this->dispatch('changeSaved', action: 'delete');

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

        $this->dispatch('changeSaved', action: 'rename');

        SystemMessage::send('refreshUploadedFiles');

        $this->dispatch('selectUploadedFile', name: $this->newFilename);
    }

    public function render()
    {
        return view('livewire.file-controls');
    }

}
