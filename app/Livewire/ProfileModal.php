<?php

namespace App\Livewire;

use App\Enums\ThemeOption;
use App\Enums\ToastMessageType;

use App\Events\ToastMessage;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

use Exception;

class ProfileModal extends Component
{
    public $user;

    public $theme;

    public function boot() {
        $this->user  = Auth::user();

        $this->theme = $this->user->theme;
    }

    public function updating($property, $value) {
        switch ($property) {
            case 'theme':
                if (trim($value) === '') {
                    $value = null;
                } else if (is_numeric($value)) {
                    $value = (int) $value;
                } else {
                    ToastMessage::dispatch(
                        Auth::id(),                                             // userId
                        ToastMessageType::ERROR,                                // type
                        'The "theme" field must be either empty or numeric.'    // message
                    );

                    return;
                }

                try {
                    $theme = new ThemeOption($value);

                    $this->user->theme = $theme->value;
                    $this->user->save();

                    ToastMessage::dispatch(
                        Auth::id(),             // userId
                        ToastMessageType::INFO, // type
                        'Reloading theme...'    // message
                    );

                    $this->dispatch('reloadTheme');
                } catch (Exception $exception) {
                    ToastMessage::dispatch(
                        Auth::id(),                             // userId
                        ToastMessageType::ERROR,                // type
                        'An invalid theme has been selected.'   // message
                    );
                }
            break;
            default:
                ToastMessage::dispatch(
                    Auth::id(),                             // userId
                    ToastMessageType::ERROR,                // type
                    'An invalid field has been specified.'  // message
                );

                return;
        }
    }

    public function render()
    {
        return view('livewire.profile-modal');
    }
}
