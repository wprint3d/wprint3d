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

    public function boot() {
        $user = Auth::user();

        $this->printerId = $user->activePrinter;
        $this->printers  = Printer::select('_id', 'node', 'machine.machineType', 'machine.uuid')->get();

        if ($this->printerId && !Printer::find( $this->printerId )) {
            $this->printerId = null;
        }

        if (!$this->printerId && filled( $this->printers )) {
            $this->printerId = $this->printers[0]->_id;

            $user->activePrinter = $this->printerId;
            $user->save();
        }
    }

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
        return view('livewire.select-printer');
    }
}
