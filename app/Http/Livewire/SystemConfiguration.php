<?php

namespace App\Http\Livewire;

use App\Enums\DataType;

use App\Models\Configuration;

use Illuminate\Support\Facades\Log;

use Livewire\Component;

class SystemConfiguration extends Component
{
    public $key;
    public $type;
    public $label;
    public $hint;
    public $value;
    public $default = null;
    public $enum;

    public $error;

    public $writeable = false;

    public function mount() {
        $this->value = Configuration::get($this->key, $this->default);

        switch ($this->type) {
            case DataType::BOOLEAN:
                $this->value =
                    $this->value === false
                        ? '0'
                        : '1';

                break;
            case DataType::INTEGER:
                if (!$this->value || $this->value < 1) {
                    // Back to defaults
                    $this->value = $this->default;

                    // If the default value is null or empty, fallback to 1 instead
                    if (!$this->value) {
                        $this->value = 1;
                    }
                }

                break;
        }
    }

    public function updatingValue($newValue) {
        switch ($this->type) {
            case DataType::BOOLEAN:
                if ($newValue != 1 && $newValue != 0) {
                    $this->error = 'A boolean value was expected.';

                    return false;
                } else {
                    $this->value = (bool) $newValue;
                }

                break;
            case DataType::INTEGER:
                if (!$newValue) { $newValue = 1; }

                if (fmod($newValue, 1) !== 0.0) {
                    $this->error = 'An integer was expected.';

                    return false;
                } else {
                    $this->value = (int) $newValue;
                }

                break;
            case DataType::FLOAT:
                if (!is_numeric($newValue)) {
                    $this->error = 'A numeric value was expected.';

                    return false;
                } else {
                    $this->value = (float) $newValue;
                }

                break;
            case DataType::ENUM:
                if (is_numeric($newValue)) {
                    $this->value = (int) $newValue;
                } else {
                    $this->value = $newValue;
                }

                break;
            default:
                $this->value = $newValue;
        }

        $configuration = Configuration::where('key', $this->key)->first();

        if (!$configuration) {
            $configuration = new Configuration();
        }

        $configuration->key     = $this->key;
        $configuration->value   = $this->value;
        $configuration->save();

        $this->error = null;

        Log::debug( __METHOD__ . ': ' . $this->value );

        return true;
    }

    public function render()
    {
        return view('livewire.system-configuration');
    }
}
