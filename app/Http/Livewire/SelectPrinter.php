<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Database\Eloquent\Collection;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class SelectPrinter extends Component
{
    public ?string $printerId = null;

    public ?Collection  $printers;
    public ?Printer     $printer;

    protected $listeners = [ 'hardwareChangeDetected' => '$refresh' ];

    public function change() {
        $this->printer = Printer::find( $this->printerId );

        if ($this->printer) {
            $user = Auth::user();
            $user->activePrinter = $this->printer->_id;
            $user->save();
        }

        return redirect('/');
    }

    public function render()
    {
        $user = Auth::user();

        $this->printerId = $user->activePrinter;
        $this->printers  = Printer::select('_id', 'node', 'machine.machineType', 'machine.uuid')->get();

        if ($this->printerId && !Printer::find( $this->printerId )) {
            $this->printerId = null;
        }

        if (!$this->printerId && filled( $this->printers )) {
            $user->activePrinter = $this->printers[0]->_id;
            $user->save();
        }

        return view('livewire.select-printer');
    }
}
