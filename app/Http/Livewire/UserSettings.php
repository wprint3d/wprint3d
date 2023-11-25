<?php

namespace App\Http\Livewire;

use App\Enums\ToastMessageType;
use App\Enums\UserRole;

use App\Events\ToastMessage;

use App\Models\User;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

use Livewire\Component;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;

class UserSettings extends Component
{
    public $user, $selfUserId;

    public $name, $email, $role, $isChangingPassword, $isChangingRole, $newPassword, $isDeleting, $hasChanges, $hasPassword;

    const IGNORED_FIELDS = [ 'id', 'newPassword', 'hasChanges', 'hasPassword' ];

    public function mount() {
        $this->name  = $this->user->name;
        $this->email = $this->user->email;
        $this->role  = $this->user->role;

        $this->newPassword        = '';
        $this->hasPassword        = !!$this->user->password;
        $this->isChangingPassword = false;
        $this->isChangingRole     = false;
        $this->isDeleting         = false;
    }

    public function enablePasswordChange() {
        $this->isChangingPassword = true;
    }

    public function enableDelete() {
        $this->isDeleting = true;
    }

    private function detectChanges() {
        $this->hasChanges = false;

        foreach (get_object_vars( $this ) as $iKey => $iValue) {
            if (
                !is_scalar( $iValue )
                ||
                in_array( $iKey, self::IGNORED_FIELDS )
            ) { continue; }

            if ($iValue != $this->user->{$iKey}) {
                $this->hasChanges = true;
            }

            if ($iKey == 'role') {
                $this->isChangingRole = $iValue != $this->user->role;
            }
        }
    }

    public function updated($name, $value) {
        if (in_array( $name, self::IGNORED_FIELDS )) { return; }

        $this->detectChanges();
    }

    public function revert($field) {
        $this->{$field} = $this->user->{$field};

        $this->dispatchBrowserEvent('hideTooltips');

        $this->detectChanges();
    }

    public function changePassword() {
        if (!$this->isChangingPassword) {
            ToastMessage::dispatch(
                Auth::id(),                                                              // userId
                ToastMessageType::ERROR,                                                 // type
                'The password change flag must be enabled before calling this function.' // message
            );

            return;
        }

        if (empty( trim( $this->newPassword ) )) {
            ToastMessage::dispatch(
                Auth::id(),                           // userId
                ToastMessageType::ERROR,              // type
                'An empty password cannot be passed.' // message
            );

            return;
        }

        $this->user->password = Hash::make( $this->newPassword);
        $this->user->save();

        $this->newPassword        = '';
        $this->hasPassword        = true;
        $this->isChangingPassword = false;

        ToastMessage::dispatch(
            Auth::id(),                 // userId
            ToastMessageType::SUCCESS,  // type
            'Saved!'                    // message
        );
    }

    public function save() {
        if (empty( $this->name )) {
            ToastMessage::dispatch(
                Auth::id(),                      // userId
                ToastMessageType::ERROR,         // type
                'The user name cannot be empty.' // message
            );

            return;
        }

        if (empty( $this->email )) {
            ToastMessage::dispatch(
                Auth::id(),                             // userId
                ToastMessageType::ERROR,                // type
                'The e-mail address cannot be empty.'   // message
            );

            return;
        }

        $matchedUser = User::select('name', 'email')->whereRaw([
            '_id'   => [ '$ne' => new ObjectId($this->user->_id) ],
            '$or'   => [
                [ 'name'    => new Regex('^' . $this->name  . '$',   'i')  ],
                [ 'email'   => new Regex('^' . $this->email . '$',  'i')   ]
            ]
        ])->first();

        if ($matchedUser) {
            if (strtolower($matchedUser->name) == strtolower($this->name)) {
                ToastMessage::dispatch(
                    Auth::id(),                                                 // userId
                    ToastMessageType::ERROR,                                    // type
                    'The specified user name is being used by another account.' // message
                );
            } else if (strtolower($matchedUser->email) == strtolower($this->email)) {
                ToastMessage::dispatch(
                    Auth::id(),                                                         // userId
                    ToastMessageType::ERROR,                                            // type
                    'The specified e-mail address is being used by another account.'    // message
                );
            }

            return;
        }

        if (!is_numeric( $this->role )) {
            ToastMessage::dispatch(
                Auth::id(),                         // userId
                ToastMessageType::ERROR,            // type
                'The specified role is invalid.'    // message
            );

            return;
        }

        if (!UserRole::hasValue( (int) $this->role )) { 
            ToastMessage::dispatch(
                Auth::id(),                             // userId
                ToastMessageType::ERROR,                // type
                'The specified role doesn\'t exist.'    // message
            );

            return;
        }

        $this->user->name   = $this->name;
        $this->user->email  = $this->email;
        $this->user->role   = (int) $this->role;
        $this->user->save();
        $this->user->refreshHash();

        $this->hasChanges       = false;
        $this->isChangingRole   = false;

        ToastMessage::dispatch(
            Auth::id(),                 // userId
            ToastMessageType::SUCCESS,  // type
            'Saved!'                    // message
        );
    }

    public function delete() {
        $this->user->delete();

        $this->emit('refreshUsers');
    }

    public function render()
    {
        return view('livewire.user-settings');
    }
}
