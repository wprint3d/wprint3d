<?php

namespace App\Http\Livewire;

use App\Events\SystemMessage;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

class MaterialSettings extends Component
{
    public $material;

    public $name;
    public $hotendTemperature;
    public $bedTemperature;

    public function delete() {
        $this->material->delete();

        SystemMessage::send('materialsChanged');
    }

    public function updated($name, $value) {
        if ($name == 'hotendTemperature' || $name == 'bedTemperature') {
            if (!is_numeric( $value ) || $value < 0) {
                $value = 0;
            }

            $value = (int) $value;

            switch ($name) {
                case 'hotendTemperature': $name = 'temperatures.hotend'; break;
                case 'bedTemperature':    $name = 'temperatures.bed';    break;
            }
        } else if ($name == 'name' && !filled( trim($value) )) {
            $value = null;
        }

        $this->material->update([ $name => $value ]);

        SystemMessage::send('materialsChanged');
    }

    public function render()
    {
        $this->name              = $this->material->name;
        $this->hotendTemperature = $this->material->temperatures['hotend'];
        $this->bedTemperature    = $this->material->temperatures['bed'];

        if (!$this->name) {
            $this->name = 'Unknown';
        }

        return view('livewire.material-settings');
    }
}
