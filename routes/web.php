<?php

use Illuminate\Support\Str;

use Illuminate\Support\Facades\Route;

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
    Route::middleware('auth')->get('/', function () {
        return view('index');
    });

    Route::get('login', function () {
        return view('login');
    })->name('login');
});