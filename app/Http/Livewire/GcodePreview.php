<?php

namespace App\Http\Livewire;

use App\Jobs\SendLinesToClientPreview;

use App\Models\Printer;
use App\Models\User;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class GcodePreview extends Component
{
    protected $listeners = [
        'refreshActiveFile'       => 'reportFileChange',
        'reloadPreviewFromServer' => 'refreshGcode'
    ];

    public string $uid;

    public array $layerMap    = [];
    public int   $currentLine = 0;

    public bool $showExtrusion;
    public bool $showTravel;

    private User     $user;
    private ?Printer $printer;

    public function reportFileChange() {
        $this->dispatchBrowserEvent('selectedFileChanged');
    }

    public function refreshGcode($selectedLine = null, bool $mapLayers = true) {
        $this->currentLine = 0;

        if ($this->user && $this->user->activePrinter) {
            $printer = Printer::select('activeFile')->find( $this->user->activePrinter );

            if ($printer && $printer->activeFile) {
                $this->currentLine =
                    $selectedLine !== null && is_numeric($selectedLine)
                        ? (int) $selectedLine
                        : $printer->getCurrentLine();

                SendLinesToClientPreview::dispatch(
                    $this->uid,                 // previewUID
                    $this->user->activePrinter, // printerId
                    $this->currentLine,         // currentLine
                    $mapLayers                  // mapLayers
                );

                return;
            }
        }

        $this->dispatchBrowserEvent('previewNoFileLoaded');
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
        $this->uid = uniqid();

        $this->user         = Auth::user();
        $this->printer      = Printer::select('settings')->find( $this->user->activePrinter );

        $this->showExtrusion    = $this->printer->settings['showExtrusion']     ?? true;
        $this->showTravel       = $this->printer->settings['showTravel']        ?? true;
    }

    public function render()
    {
        return view('livewire.gcode-preview');
    }
}
