<?php

use App\Http\Controllers\OctoPrint\AuthController;
use App\Http\Controllers\OctoPrint\FilesController;
use App\Http\Controllers\OctoPrint\PrinterController;
use App\Http\Controllers\OctoPrint\SystemController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('octoprint.auth')->group(function () {
    Route::middleware('octoprint.ability:read')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/currentuser', [AuthController::class, 'currentUser']);
        Route::get('/version', [SystemController::class, 'version']);
        Route::get('/wprint3d/printers', [SystemController::class, 'printers']);
        Route::get('/wprint3d/cameras', [SystemController::class, 'cameras']);
        Route::get('/files', [FilesController::class, 'index']);
        Route::get('/files/local', [FilesController::class, 'index']);
        Route::get('/files/local/{path}', [FilesController::class, 'show'])->where('path', '.*');
        Route::get('/job', [PrinterController::class, 'job']);
        Route::get('/printer', [PrinterController::class, 'printer']);
        Route::get('/printer/tool', [PrinterController::class, 'tool']);
        Route::get('/printer/bed', [PrinterController::class, 'bed']);
        Route::get('/connection', [PrinterController::class, 'connection']);
        Route::post('/wprint3d/printer', [SystemController::class, 'selectPrinter']);
        Route::post('/access/users/{username}/apikey', [AuthController::class, 'createApiKey']);
        Route::delete('/access/users/{username}/apikey', [AuthController::class, 'deleteApiKey']);
    });

    Route::middleware('octoprint.ability:files')->group(function () {
        Route::post('/files/local', [FilesController::class, 'upload']);
        Route::delete('/files/local/{path}', [FilesController::class, 'destroy'])->where('path', '.*');
        Route::post('/files/local/{path}', [FilesController::class, 'command'])->where('path', '.*');
    });

    Route::middleware('octoprint.ability:control')->group(function () {
        Route::post('/job', [PrinterController::class, 'command']);
        Route::post('/printer/printhead', [PrinterController::class, 'printheadCommand']);
        Route::post('/printer/tool', [PrinterController::class, 'toolCommand']);
        Route::post('/printer/bed', [PrinterController::class, 'bedCommand']);
    });
});
