<?php

namespace App\Http\Livewire;

use App\Enums\SortingMode;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Livewire\Component;

class UploadedFiles extends Component
{
    public $selected    = null;
    public $files       = [];
    public $subPath     = null;
    public $sortingMode = SortingMode::NAME_ASCENDING;

    protected $baseFilesDir;

    protected $listeners = [
        'refreshUploadedFiles'  => '$refresh',
        'selectUploadedFile'    => 'selectByName',
        'recoveryCompleted'     => 'resetSubPath'
    ];

    private function refreshFileList() {
        Auth::user()->setCurrentFolder( $this->subPath );

        $basePath = $this->baseFilesDir;

        if ($this->subPath) {
            $basePath .= $this->subPath;
        }

        $this->files    = [];
        $this->selected = null;

        $this->emit('prepareFile', null);

        $files = array_map(
            function ($item) use ($basePath) {
                return Str::replace($basePath . '/', '', $item);
            },
            Storage::files( $basePath )
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
            usort($files, function ($fileA, $fileB) use ($basePath) {
                return
                    Storage::lastModified( $basePath . '/' . $fileA )
                    <
                    Storage::lastModified( $basePath . '/' . $fileB );
            });
        } else if ($this->sortingMode == SortingMode::DATE_DESCENDING) {
            usort($files, function ($fileA, $fileB) use ($basePath) {
                return
                    Storage::lastModified( $basePath . '/' . $fileA )
                    >
                    Storage::lastModified( $basePath . '/' . $fileB );
            });
        }

        $directories = array_map(
            function ($item) use ($basePath) {
                return Str::replace($basePath . '/', '', $item);
            },
            Storage::directories( $basePath )
        );

        foreach ($directories as $directory) {
            $this->files[] = [
                'name'      => $directory,
                'directory' => true
            ];
        }

        $this->files = array_merge(
            $this->files,
            array_map(function ($file) {
                return [
                    'name'      => $file,
                    'directory' => false
                ];
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

        $this->refreshFileList();
    }

    public function render()
    {
        return view('livewire.uploaded-files');
    }
}
