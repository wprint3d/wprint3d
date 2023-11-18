<?php

namespace App\Http\Livewire;

use App\Models\Printer;

use Illuminate\Support\Facades\Auth;

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

        if (
            Auth::attempt([
                'email'     => $this->identifier,
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

        if (
            Auth::attempt([
                'name'      => $this->identifier,
                'password'  => $this->password
            ])
        ) {
            $printers = Printer::select('_id')->get();

            $user = Auth::user();
            $user->getSessionHash(); // get/refresh hash in the session store

            if ($printers->count() > 0) {
                $user->setActivePrinter( $printers->first()->_id );
            }

            $this->dispatchBrowserEvent('forceRedirect', '/');

            return;
        }

        $this->addError('identifier', 'That combination of username or email address and password doesn\'t match our records.');
    }

    public function render()
    {
        return view('livewire.login');
    }
}
