<?php

namespace App\Livewire;

use App\Jobs\SendLinesToClientPreview;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class PreviewTab extends Component
{
    protected $listeners = [
        'initialize'              => 'initialize',
        'refreshActiveFile'       => 'reportFileChange'
    ];

    public string $uid;

    public array $layerMap    = [];
    public int   $currentLine = 0;

    public bool $showExtrusion;
    public bool $showTravel;

    private ?Printer $printer = null;

    public function reportFileChange() {
        $this->dispatch('selectedFileChanged');
    }

    #[On('reloadPreviewFromServer')]
    public function refreshGcode(string $uid, $selectedLine = null, bool $mapLayers = true) {
        $this->currentLine = 0;

        if ($this->printer) {
            $this->printer->refresh();

            if ($this->printer && $this->printer->activeFile) {
                $this->currentLine =
                    $selectedLine !== null && is_numeric($selectedLine)
                        ? (int) $selectedLine
                        : $this->printer->getCurrentLine();

                SendLinesToClientPreview::dispatch(
                    $uid,                // previewUID
                    $this->printer->_id, // printerId
                    $this->currentLine,  // currentLine
                    $mapLayers           // mapLayers
                );

                return;
            }
        }

        $this->dispatch('previewNoFileLoaded');
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

        $this->dispatch('refreshSettings',
            showExtrusion: $this->showExtrusion,
            showTravel:    $this->showTravel
        );
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

    public function initialize() {
        $this->dispatch('initializePreviewTab');
    }

    public function render()
    {
        return view('livewire.preview-tab');
    }
}
