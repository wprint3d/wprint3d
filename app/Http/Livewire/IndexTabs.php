<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class IndexTabs extends Component
{
    public $enableControlTab = false;

    protected $listeners = [ 'refreshActiveFile' => 'refreshControlToggle' ];

    public function refreshControlToggle() {
        $user = Auth::user();

        if (!$user) return;

        $printer = Printer::select('activeFile')->find( $user->activePrinter );

        if (!$printer) return;

        $this->enableControlTab = !$printer->activeFile;

        if (!$this->enableControlTab) {
            $this->dispatchBrowserEvent('resetDefaultTab');
        }
    }

    public function boot() {
        $this->refreshControlToggle();
    }

    public function render()
    {
        return view('livewire.index-tabs');
    }
}
