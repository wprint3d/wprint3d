<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class TemperaturePresets extends Component
{
    public $show;

    public $materials;

    public $materialIndex = 0;

    const COMMAND_QUEUED_ANIMATION_SECS = 2;

    protected $listeners = [
        'selectPrinter'     => '$refresh',
        'refreshActiveFile' => '$refresh',
        'materialsChanged'  => '$refresh'
    ];

    private ?Printer $printer = null;

    public function prepare() {
        $user = Auth::user();

        $printerId = $user->getActivePrinter();

        if ($printerId) {
            $this->printer = Printer::select('activeFile')->find( $printerId );

            if ($this->printer) {
                $this->show = !$this->printer->activeFile;

                if ($this->show) {
                    $this->materials = $user->materials()->get();
                }
            } else {
                $this->show = false;
            }
        }
    }

    public function load() {
        if (!$this->printer) return;

        $material = $this->materials->get( $this->materialIndex );

        $statistics = $this->printer->getStatistics();

        if (isset( $statistics['extruders'] )) {
            foreach (array_keys( $statistics['extruders'] ) as $index) {
                $this->printer->queueCommand( "M104 I{$index} S{$material->temperatures['hotend']}" );
            }
        }

        $this->printer->queueCommand( "M104 S{$material->temperatures['hotend']}" );
        $this->printer->queueCommand( "M140 S{$material->temperatures['bed']}" );

        sleep( self::COMMAND_QUEUED_ANIMATION_SECS );
    }

    public function boot() {
        $this->show = false;

        $this->prepare();
    }

    public function render()
    {
        $this->prepare();

        return view('livewire.temperature-presets');
    }
}
