<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class IndexTabs extends Component
{
    public $activeTab;
    public $tabs             = [ 'terminal', 'preview', 'control', 'recordings' ];
    public $enableControlTab = false;

    protected $listeners = [ 'refreshActiveFile' => 'refreshControlToggle' ];

    public function refreshControlToggle() {
        $user = Auth::user();

        if (!$user) return;

        $printer = Printer::select('activeFile')->find( $user->activePrinter );

        if (!$printer) return;

        $this->enableControlTab = !$printer->activeFile;

        if (!$this->enableControlTab) {
            $this->activeTab = $this->tabs[0];
        }
    }

    public function select($index) {
        if (!isset( $this->tabs[ $index ] )) {
            $this->activeTab = $this->tabs[0];

            return;
        }

        $this->activeTab = $this->tabs[ $index ];
    }

    public function boot() {
        $this->activeTab = $this->tabs[0];

        $this->refreshControlToggle();
    }

    public function render()
    {
        return view('livewire.index-tabs');
    }
}
