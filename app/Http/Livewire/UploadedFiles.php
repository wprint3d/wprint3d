<?php

namespace App\Http\Livewire;

use App\Enums\SortingMode;
use App\Enums\UserRole;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Livewire\Component;

class UploadedFiles extends Component
{
    public $selected    = null;
    public $files       = [];
    public $basePath    = null;
    public $subPath     = null;
    public $sortingMode = SortingMode::NAME_ASCENDING;

    public $writeable   = false;

    public ?string $activeFile = null;

    protected $baseFilesDir;

    protected $listeners = [
        'refreshUploadedFiles'  => '$refresh',
        'selectUploadedFile'    => 'selectByName',
        'recoveryCompleted'     => 'resetSubPath',
        'refreshActiveFile'     => 'refreshActiveFile'
    ];

    public function refreshActiveFile() {
        $activePrinter = Auth::user()->getActivePrinter();

        if ($activePrinter) {
            $printer = Printer::select('activeFile')->find( $activePrinter );

            if ($printer) {
                $this->activeFile = $printer->activeFile;
            }
        }
    }

    private function refreshFileList() {
        Auth::user()->setCurrentFolder( $this->subPath );

        $this->basePath = $this->baseFilesDir;

        if ($this->subPath) {
            $this->basePath .= $this->subPath;
        }

        $this->files    = [];
        $this->selected = null;

        $this->emit('prepareFile', null);

        $files = array_map(
            function ($item) {
                return Str::replace($this->basePath . '/', '', $item);
            },
            Storage::files( $this->basePath )
        );

        if (
            $this->sortingMode == SortingMode::NAME_ASCENDING
            ||
            $this->sortingMode == SortingMode::NAME_DESCENDING
        ) {
            natsort($files);

            if ($this->sortingMode == SortingMode::NAME_DESCENDING) {
                $files = array_reverse($files);
            }
        } else if ($this->sortingMode == SortingMode::DATE_ASCENDING) {
            usort($files, function ($fileA, $fileB) {
                return
                    Storage::lastModified( $this->basePath . '/' . $fileA )
                    >
                    Storage::lastModified( $this->basePath . '/' . $fileB );
            });
        } else if ($this->sortingMode == SortingMode::DATE_DESCENDING) {
            usort($files, function ($fileA, $fileB) {
                return
                    Storage::lastModified( $this->basePath . '/' . $fileA )
                    <
                    Storage::lastModified( $this->basePath . '/' . $fileB );
            });
        }

        $directories = array_map(
            function ($item) {
                return Str::replace($this->basePath . '/', '', $item);
            },
            Storage::directories( $this->basePath )
        );

        foreach ($directories as $directory) {
            $directory = [
                'name'      => $directory,
                'directory' => true
            ];

            if (
                $this->activeFile
                &&
                strpos($this->activeFile, $this->basePath . '/' . $directory['name']) !== false
            ) {
                $directory['active'] = true;
            }

            $this->files[] = $directory;
        }

        $this->files = array_merge(
            $this->files,
            array_map(function ($file) {
                $file = [
                    'name'      => $file,
                    'directory' => false
                ];

                if (
                    $this->activeFile
                    &&
                    $this->basePath . '/' . $file['name'] == $this->activeFile
                ) {
                    $file['active'] = true;
                }

                return $file;
            }, $files)
        );

        $this->emit('selectedPathChanged');
    }

    public function sortBy(string $mode) {
        Log::debug( __METHOD__ . ': ' . $mode );

        $this->sortingMode = SortingMode::getValue($mode);

        if ($this->sortingMode === null) {
            Log::warning( __METHOD__ . ': invalid mode.' );

            return;
        }

        $this->refreshFileList();
    }

    public function goUp() {
        $this->subPath = dirname($this->subPath);

        if ($this->subPath == '/') {
            $this->subPath = null;
        }

        $this->refreshFileList();
    }

    public function goHome() {
        $this->subPath = null;

        $this->refreshFileList();
    }

    public function select($index) {
        if ($this->files[ $index ]['directory']) {
            $this->subPath .= '/' . $this->files[ $index ]['name'];

            $this->refreshFileList();
        } else {
            $fileName = $this->files[ $index ]['name'];

            $this->selected = $fileName;

            $this->emit('prepareFile', Auth::user()->getCurrentFolder() . '/' . $fileName);
        }
    }

    public function selectByName($name) {
        $this->refreshFileList();

        $this->selected = $name;

        $this->emit('prepareFile', Auth::user()->getCurrentFolder() . '/' . $name);
    }

    public function resetSubPath(string $newFileName) {
        $this->subPath = null;

        $this->refreshFileList();

        $this->selectByName( $newFileName );
    }

    public function mount() {
        $this->subPath = Str::replace(
            search:  $this->baseFilesDir,
            replace: '',
            subject: Auth::user()->getCurrentFolder()
        );

        $this->refreshFileList();
    }

    public function hydrate() {
        $this->refreshFileList();
    }

    public function boot() {
        $this->baseFilesDir = env('BASE_FILES_DIR');

        $userRole = Auth::user()->role;

        $this->writeable =
            $userRole == UserRole::ADMINISTRATOR
            ||
            $userRole == UserRole::USER;

        $this->refreshFileList();
        $this->refreshActiveFile();
    }

    public function render()
    {
        return view('livewire.uploaded-files');
    }
}
