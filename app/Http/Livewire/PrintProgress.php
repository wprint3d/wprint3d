<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use Livewire\Component;

class PrintProgress extends Component
{
    public $printing;
    public $progress;
    public $lastCommand;

    public ?Printer $printer    = null;
    public ?string  $printerId  = null;

    protected $listeners = [ 'selectPrinter' ];

    public function selectPrinter() {
        $activePrinter = Auth::user()->getActivePrinter();

        Log::debug( __METHOD__ . ': ' . ($activePrinter ?? 'none') );

        if ($activePrinter) {
            $this->printer = Printer::select('_id')->find( $activePrinter );
        }
    }

    /*
     * TODO: Simplify this logic to get a snapshot of what the printer's seen
     *       the last time we were online instead of re-calculating everything.
     */
    public function hydrate() {
        if ($this->printer) {
            $currentLine = $this->printer->getCurrentLine();

            $this->printing = !!Cache::get( env('CACHE_MAX_LINE_KEY'), null );
            $this->progress =
                (($currentLine > 0 ? $currentLine : 1) * 100)
                /
                Cache::get( env('CACHE_MAX_LINE_KEY'), 1);

            $this->lastCommand = $this->printer->getLastCommand();
        }
    }

    public function render()
    {
        return view('livewire.print-progress');
    }
}
