<?php

namespace App\Http\Livewire;

use App\Models\Printer;
use App\Models\User;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

use MongoDB\BSON\ObjectId;

class Login extends Component
{

    public $mailAddress, $password, $rememberMe;

    protected $rules = [
        'mailAddress'   => 'required|email',
        'password'      => 'required',
        'rememberMe'    => 'nullable|bool'
    ];

    public function submit() {
        if (!$this->rememberMe) $this->rememberMe = false;

        $this->validate();

        if (
            Auth::attempt([
                'email'     => $this->mailAddress,
                'password'  => $this->password
            ])
        ) {
            $printers = Printer::select('_id')->get();

            $user = Auth::user();

            $user->activePrinter =
                $printers->count() > 0
                    ? (string) $printers->first()->_id
                    : null;

            $user->save();

            return redirect()->intended('/');
        }

        $this->addError('mailAddress', 'That combination of email address and password doesn\'t match our records.');
    }

    public function render()
    {
        return view('livewire.login');
    }
}
