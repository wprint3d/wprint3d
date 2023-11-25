<?php

namespace App\Http\Livewire;

use App\Models\Printer;
use App\Models\User;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

use MongoDB\BSON\Regex;

use Livewire\Component;

class Login extends Component
{
    public $identifier, $password, $rememberMe, $logoutReason;

    protected $rules = [
        'identifier'    => 'required|string',
        'password'      => 'required',
        'rememberMe'    => 'nullable|bool'
    ];

    public function boot() {
        $this->logoutReason =
            request()->has('logoutReason')
                ? request()->get('logoutReason')
                : '';
    }

    public function submit() {
        if (!$this->rememberMe) $this->rememberMe = false;

        $this->validate();

        $user = User::whereRaw([
            '$or'   => [
                [ 'name'    => new Regex('^' . $this->identifier . '$',  'i')  ],
                [ 'email'   => new Regex('^' . $this->identifier . '$',  'i')  ]
            ]
        ])->first();

        if (!$user || !Hash::check($this->password, $user->password)) {
            $this->addError('identifier', 'That combination of username or email address and password doesn\'t match our records.');

            return;
        }

        Auth::login($user);

        $printers = Printer::select('_id')->get();

        $user->getSessionHash(); // get/refresh hash in the session store

        if ($printers->count() > 0) {
            $user->setActivePrinter( $printers->first()->_id );
        }

        $this->dispatchBrowserEvent('forceRedirect', '/');
    }

    public function render()
    {
        return view('livewire.login');
    }
}
