<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

use Livewire\Component;

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

            if ($printers->count() > 0) {
                Auth::user()->setActivePrinter( $printers->first()->_id );
            }

            $this->dispatchBrowserEvent('forceRedirect', '/');

            return;
        }

        $this->addError('mailAddress', 'That combination of email address and password doesn\'t match our records.');
    }

    public function render()
    {
        return view('livewire.login');
    }
}
