<?php

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
                'email' => [ 'That combination of username or email address and password doesn\'t match our records.' ]
            ]);
        }

        Auth::login($user);

        $printers = Printer::select('_id')->get();

        $user->getSessionHash(); // get/refresh hash in the session store

        if ($printers->count() > 0) {
            $user->setActivePrinterId( $printers->first()->_id );
        }
    })->name('login');

});