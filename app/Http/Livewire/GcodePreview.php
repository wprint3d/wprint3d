<?php

namespace App\Http\Livewire;

use App\Models\Printer;
use App\Models\User;

use Illuminate\Support\Str;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

use Livewire\Component;

class GcodePreview extends Component
{
    protected $listeners = [ 'refreshActiveFile' => 'refreshGcode' ];

    public array $gcode       = [];
    public int   $currentLine = 0;

    public bool $showExtrusion;
    public bool $showTravel;

    private User     $user;
    private ?Printer $printer;
    private string   $baseFilesDir;

    // TODO: Implement streaming instead of halting based off this constant.
    const FILE_SIZE_LIMIT_BYTES = 4194304;

    public function refreshGcode() {
        $this->gcode        = [];
        $this->currentLine  = 0;

        if ($this->user && $this->user->activePrinter) {
            $printer = Printer::select('activeFile')->find( $this->user->activePrinter );

            if ($printer && $printer->activeFile) {
                $filePath = $this->baseFilesDir . '/' . $printer->activeFile;

                if (Storage::size( $filePath ) > self::FILE_SIZE_LIMIT_BYTES) {
                    $this->dispatchBrowserEvent('gcodePreviewFailedTooLarge');
                } else {
                    $gcode = Storage::get( $filePath );

                    if ($gcode) {
                        $this->gcode        = Str::of( $gcode )->explode(PHP_EOL)->toArray();
                        $this->currentLine  = $printer->getCurrentLine();
                    }
                }
            }
        }

        $this->dispatchBrowserEvent('gcodeChanged', [
            'gcode'         => $this->gcode,
            'currentLine'   => $this->currentLine
        ]);
    }

    public function updated() {
        if ($this->printer) {
            if (!$this->printer->settings) {
                $this->printer->settings = [];
            }

            $settings = $this->printer->settings;

            $settings['showExtrusion']  = $this->showExtrusion;
            $settings['showTravel']     = $this->showTravel;

            $this->printer->settings = $settings;
            $this->printer->save();
        }

        $this->dispatchBrowserEvent('refreshSettings', [
            'showExtrusion' => $this->showExtrusion,
            'showTravel'    => $this->showTravel
        ]);
    }

    public function boot() {
        $this->user         = Auth::user();
        $this->printer      = Printer::select('settings')->find( $this->user->activePrinter );

        $this->baseFilesDir = env('BASE_FILES_DIR');

        $this->showExtrusion    = $this->printer->settings['showExtrusion']     ?? true;
        $this->showTravel       = $this->printer->settings['showTravel']        ?? true;

        $this->refreshGcode();
    }

    public function render()
    {
        return view('livewire.gcode-preview');
    }
}
