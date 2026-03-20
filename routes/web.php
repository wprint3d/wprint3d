<?php

use App\Console\Commands\CreateSampleUser;

use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

use MongoDB\BSON\Regex;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware('web')->group(function () {

    Route::post('login', function (Request $request) {
        $request->validate([
            'email'     => 'required',
            'password'  => 'required'
        ]);

        $user = User::whereRaw([
            '$or'   => [
                [ 'name'    => new Regex('^' . $request->get('email') . '$',  'i')  ],
                [ 'email'   => new Regex('^' . $request->get('email') . '$',  'i')  ]
            ]
        ])->first();

        if (!$user || !Hash::check($request->get('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [ __('server.auth.invalid_credentials') ]
            ]);
        }

        Auth::login($user);

        $printers = Printer::select('_id')->get();

        $user->getSessionHash(); // get/refresh hash in the session store

        if ($printers->count() > 0) {
            $user->setActivePrinterId( $printers->first()->_id );
        }

        if ($user->name === CreateSampleUser::SAMPLE_USER_NAME) {
            Configuration::set(
                key:    'showFirstLoginHints',
                value:  false,
                overrideWriteable: true
            );
        }
    })->name('login');

});
