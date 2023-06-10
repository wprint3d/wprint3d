<?php

namespace App\Http\Livewire;

use App\Jobs\SendLinesToClientPreview;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class PreviewTab extends Component
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

    private ?Printer $printer;

    public function reportFileChange() {
        $this->dispatchBrowserEvent('selectedFileChanged');
    }

    public function refreshGcode($selectedLine = null, bool $mapLayers = true) {
        $this->currentLine = 0;

        if ($this->printer) {
            $this->printer->refresh();

            if ($this->printer && $this->printer->activeFile) {
                $this->currentLine =
                    $selectedLine !== null && is_numeric($selectedLine)
                        ? (int) $selectedLine
                        : $this->printer->getCurrentLine();

                SendLinesToClientPreview::dispatch(
                    $this->uid,          // previewUID
                    $this->printer->_id, // printerId
                    $this->currentLine,  // currentLine
                    $mapLayers           // mapLayers
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
        $this->uid      = uniqid();
        $this->printer  = null;

        $activePrinter = Auth::user()->getActivePrinter();

        if ($activePrinter) {
            $this->printer = Printer::select('activeFile', 'settings')->find( $activePrinter );
        }

        $this->showExtrusion    = $this->printer->settings['showExtrusion']     ?? true;
        $this->showTravel       = $this->printer->settings['showTravel']        ?? true;
    }

    public function render()
    {
        return view('livewire.preview-tab');
    }
}
