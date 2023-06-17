<?php

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Josantonius\Url\Url;
use Symfony\Component\HttpFoundation\Response;

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
    Route::post('base', function (Request $request) {
        if (! $request->has('url')) {
            return response('Missing parameter "url".', Response::HTTP_BAD_REQUEST);
        }

        $url = new Url( $request->get('url') );

        if ($url->host != $request->header('host')) {
            return response('Cannot set a new app URL with a different hostname.', Response::HTTP_BAD_REQUEST);
        }

        session()->put('app_url', $url->base);

        return response('');
    });

    Route::middleware('set_base')->group(function () {
        Route::get('/test', function () {
            return response( request()->header() );
        });

        Route::middleware('auth')->get('/', function () {
            return view('index');
        });

        Route::get('login', function () {
            if (Auth::user()) {
                return view('force_redirect', [ 'path' => '/' ]);
            }

            return view('login');
        })->name('login');
    });
});