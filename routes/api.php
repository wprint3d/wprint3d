<?php

use App\Enums\ControlDirection;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\CameraController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\PrinterController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UsersController;
use App\Models\Camera;
use App\Models\Configuration;
use App\Models\DeviceVariant;
use App\Models\Material;
use App\Models\Printer;
use App\Rules\IsValidObjectID;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('device')->group(function () {
    Route::get('variant/{model}', function ($model) {
        $deviceVariant = DeviceVariant::where('model', $model)->project([ '_id' => false ])->first();

        if ($deviceVariant) {
            return response( $deviceVariant );
        }

        return response('', Response::HTTP_NOT_FOUND);
    });
});

Route::prefix('/app')->group(function () {
    Route::get('/name',     [ ApplicationController::class, 'getName'     ]);
    Route::get('/revision', [ ApplicationController::class, 'getRevision' ]);
    Route::get('/licenses', [ ApplicationController::class, 'getLicenses' ]);
});

Route::middleware([ 'auth:sanctum', 'password.ensure_changed' ])->group(function () {
    Route::get('/ws/config',    [ ConfigurationController::class, 'wsConfig' ]);

    Route::get('/data/types',   [ ConfigurationController::class, 'getDataTypes' ]);

    Route::prefix('/users')->group(function () {
        Route::post('/',        [ UsersController::class, 'create'  ]);
        Route::get('/',         [ UsersController::class, 'index'   ]);
        Route::get('/{id}',     [ UsersController::class, 'get'     ]);
        Route::put('/{id}',     [ UsersController::class, 'update'  ]);
        Route::delete('/{id}',  [ UsersController::class, 'delete'  ]);
        Route::post('/{id}/reset-password', [ UsersController::class, 'resetPassword' ]);
    });

    Route::prefix('/enum')->group(function () {
        Route::post('/batch',           [ ConfigurationController::class, 'listEnums'        ]);
        Route::get('/{enum}',           [ ConfigurationController::class, 'getEnum'          ]);
        Route::get('/{enum}/constants', [ ConfigurationController::class, 'getEnumConstants' ]);
    });

    Route::prefix('/config')->group(function () {
        Route::get('/',             [ ConfigurationController::class, 'index'   ]);
        Route::get('/{key}',        [ ConfigurationController::class, 'get'     ]);
        Route::put('/{key}',        [ ConfigurationController::class, 'update'  ]);
    });

    Route::get('/recorder/options', [ ConfigurationController::class, 'recorderOptions' ]);

    Route::get('/printers',         [ PrinterController::class, 'index'  ]);
    Route::get('/printer/{id}',     [ PrinterController::class, 'get'    ]);
    Route::delete('/printer/{id}',  [ PrinterController::class, 'delete' ]);

    Route::get('/cameras',          [ CameraController::class, 'index'  ]);

    Route::prefix('/camera/{id}')->group(function () {
        Route::get('/',     [ CameraController::class, 'get'    ]);
        Route::delete('/',  [ CameraController::class, 'delete' ]);
        Route::put('/',     [ CameraController::class, 'update' ]);

        Route::post('/enable',  [ CameraController::class, 'enable' ]);
        Route::post('/disable', [ CameraController::class, 'disable' ]);
    });

    Route::get('/camera/{id}',      [ CameraController::class, 'get'    ]);

    Route::get('/files',                [ FilesController::class,   'index'         ]);
    Route::get('/files/sortingModes',   [ FilesController::class,   'sortingModes'  ]);

    Route::get('/checkLogin', [ SessionController::class, 'id' ]);

    Route::prefix('/user')->group(function () {

        Route::get('/',             [ UserController::class,    'get'            ]);
        Route::put('/settings',     [ UserController::class,    'updateSettings' ]);
        Route::post('/logout',      [ UserController::class,    'logout'         ]);
        Route::put('/password',     [ UserController::class,    'updatePassword' ])->withoutMiddleware('password.ensure_changed');

        Route::get('/materials',    [ UserController::class,    'materials' ]);

        Route::post('/material',        [ UserController::class, 'addMaterial'    ]);
        Route::put('/material/{id}',    [ UserController::class, 'updateMaterial' ]);
        Route::delete('/material/{id}', [ UserController::class, 'deleteMaterial' ]);

        Route::prefix('/directory')->group(function () {
            Route::post('/',    [ UserController::class, 'createDirectory' ]);
            Route::delete('/',  [ UserController::class, 'deleteDirectory' ]);
        });

        Route::prefix('/file')->group(function () {
            Route::delete('/',       [ UserController::class, 'deleteFile' ]);
            Route::put('/rename',    [ UserController::class, 'renameFile' ]);
            Route::post('/upload',   [ UserController::class, 'uploadFile' ]);
        });
        
        Route::prefix('/printer/selected')->group(function () {
            Route::post('/', [ UserController::class, 'setActivePrinterId' ]);

            Route::middleware('printer.load')->group(function () {
                Route::get('/',  [ UserController::class, 'getActivePrinterId' ]);

                Route::get('/recordings',                 [ PrinterController::class, 'getRecordings'   ]);
                Route::delete('/recording/{recordingId}', [ PrinterController::class, 'deleteRecording' ]);

                Route::prefix('/control')->group(function () {
                    Route::post('/movement', [ PrinterController::class, 'handleControlCommand' ]);

                    Route::prefix('/extrusion')->group(function () {
                        Route::post('/extrude',    [ PrinterController::class, 'handleExtrusion'    ]);
                        Route::post('/retract',    [ PrinterController::class, 'handleRetraction'   ]);
                    });

                    Route::prefix('/temperature')->group(function () {
                        Route::post('/hotend',  [ PrinterController::class, 'handleHotendTemperature' ]);
                        Route::post('/bed',     [ PrinterController::class, 'handleBedTemperature'    ]);
                    });
                });

                Route::get('/status',   [ UserController::class, 'getActivePrinterStatus'   ]);
                Route::get('/console',  [ UserController::class, 'getActivePrinterConsole'  ]);
                Route::get('/cameras',  [ UserController::class, 'getActivePrinterCameras'  ]);

                Route::prefix('/camera/{cameraId}')->group(function () {
                    Route::post('/link',    [ PrinterController::class, 'linkCamera'   ]);
                    Route::post('/unlink',  [ PrinterController::class, 'unlinkCamera' ]);

                    Route::prefix('/recording')->group(function () {
                        Route::post('/enable',     [ PrinterController::class, 'enableRecording'  ]);
                        Route::post('/disable',    [ PrinterController::class, 'disableRecording' ]);
                    });
                });

                Route::post('/preheat/{materialId}',    [ PrinterController::class, 'preheatUsingPreset' ]);
                Route::post('/terminal/queue/command',  [ PrinterController::class, 'queueCommand'      ]);

                Route::prefix('/print')->group(function () {
                    Route::get('/',           [ PrinterController::class, 'getPrintStatus' ]);
                    Route::post('/',          [ PrinterController::class, 'startPrint'     ]);
                    Route::post('/pause',     [ PrinterController::class, 'pausePrint'     ]);
                    Route::post('/resume',    [ PrinterController::class, 'resumePrint'    ]);
                    Route::post('/cancel',    [ PrinterController::class, 'cancelPrint'    ]);
                    Route::post('/recover',   [ PrinterController::class, 'recoverPrint'   ]);
                    Route::delete('/recover', [ PrinterController::class, 'skipRecovery'   ]);

                    Route::prefix('/gcode')->group(function () {
                        Route::get('/lines/stream', [ PrinterController::class, 'getLinesFromActiveFile'     ]);
                        Route::get('/lines/count',  [ PrinterController::class, 'getLineCountFromActiveFile' ]);
                    });
                });
            });
        });

    });
});

// TODO: Properly support third-party authentication
// Route::post('/sanctum/token', function (Request $request) {
//     $request->validate([
//         'email'     => 'required',
//         'password'  => 'required'
//     ]);

//     $user = User::whereRaw([
//         '$or'   => [
//             [ 'name'    => new Regex('^' . $request->get('email') . '$',  'i')  ],
//             [ 'email'   => new Regex('^' . $request->get('email') . '$',  'i')  ]
//         ]
//     ])->first();

//     if (!$user || !Hash::check($request->get('password'), $user->password)) {
//         throw ValidationException::withMessages([
//             'email' => [ 'That combination of username or email address and password doesn\'t match our records.' ]
//         ]);
//     }

//     $printers = Printer::select('_id')->get();

//     $user->getSessionHash(); // get/refresh hash in the session store

//     if ($printers->count() > 0) {
//         $user->setActivePrinterId( $printers->first()->_id );
//     }

//     return $user->createToken(
//         name:       'user',
//         expiresAt:  now()->addWeek()
//     )->plainTextToken;
// });