<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\CameraController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\DeveloperFakeSerialController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\LoggingController;
use App\Http\Controllers\PluginController;
use App\Http\Controllers\PluginRuntimeProxyController;
use App\Http\Controllers\PrinterController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UsersController;
use App\Models\DeviceVariant;
use Illuminate\Support\Facades\Route;
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
        $deviceVariant = DeviceVariant::where('model', $model)->project(['_id' => false])->first();

        if ($deviceVariant) {
            return response($deviceVariant);
        }

        return response('', Response::HTTP_NOT_FOUND);
    });
});

Route::prefix('/app')->group(function () {
    Route::get('/name', [ApplicationController::class, 'getName']);
    Route::get('/revision', [ApplicationController::class, 'getRevision']);
    Route::get('/licenses', [ApplicationController::class, 'getLicenses']);

    Route::prefix('/update')->group(function () {
        Route::get('/status', [ApplicationController::class, 'getUpdateStatus']);
        Route::post('/check', [ApplicationController::class, 'checkForUpdates']);
        Route::post('/install', [ApplicationController::class, 'installUpdate']);
    });
});

Route::middleware(['auth:sanctum', 'native.api', 'password.ensure_changed'])->group(function () {
    Route::get('/ws/config', [ConfigurationController::class, 'wsConfig']);

    Route::get('/data/types', [ConfigurationController::class, 'getDataTypes']);

    Route::post('/user/confirm-password', [ApiTokenController::class, 'confirmPassword']);
    Route::get('/users/{userId}/tokens', [ApiTokenController::class, 'index']);
    Route::post('/users/{userId}/tokens', [ApiTokenController::class, 'store']);
    Route::delete('/users/{userId}/tokens/{tokenId}', [ApiTokenController::class, 'destroy']);

    Route::prefix('/enum')->group(function () {
        Route::post('/batch', [ConfigurationController::class, 'listEnums']);
        Route::get('/{enum}', [ConfigurationController::class, 'getEnum']);
        Route::get('/{enum}/constants', [ConfigurationController::class, 'getEnumConstants']);
    });

    Route::prefix('/config')->group(function () {
        Route::get('/', [ConfigurationController::class, 'index'])->withoutMiddleware(['auth:sanctum', 'password.ensure_changed']);
        Route::get('/{key}', [ConfigurationController::class, 'get'])->withoutMiddleware(['auth:sanctum', 'password.ensure_changed']);
        Route::put('/{key}', [ConfigurationController::class, 'update']);
    });

    Route::prefix('/plugins')->group(function () {
        Route::get('/sdk', [PluginController::class, 'sdk']);
        Route::get('/sdk/octoprint-compat.js', [PluginController::class, 'octoPrintCompatScript']);
        Route::get('/ui', [PluginController::class, 'ui']);
        Route::get('/{pluginId}/host-context', [PluginController::class, 'hostContext']);
        Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/{pluginId}/runtime/{runtimePath?}', [PluginRuntimeProxyController::class, 'forward'])
            ->where('runtimePath', '.*')
            ->middleware(['throttle:plugin-runtime', 'plugin-runtime.concurrent'])
            ->withoutMiddleware('throttle:1500,1');
        Route::post('/{pluginId}/runtime-artifacts/{importId}', [PluginRuntimeProxyController::class, 'importArtifact'])
            ->middleware('throttle:plugin-runtime')
            ->withoutMiddleware('throttle:1500,1');
        Route::get('/{pluginId}/assets/{assetPath}', [PluginController::class, 'asset'])->where('assetPath', '.*');
        Route::get('/{pluginId}/settings', [PluginController::class, 'settings']);
        Route::put('/{pluginId}/settings', [PluginController::class, 'updateSettings']);
        Route::get('/{pluginId}/state', [PluginController::class, 'state']);
        Route::get('/{pluginId}/logs', [PluginController::class, 'logs']);
        Route::post('/{pluginId}/actions/{actionId}', [PluginController::class, 'invokeAction']);
    });

    Route::middleware(['auth.ensure_admin'])->group(function () {
        Route::prefix('/users')->group(function () {
            Route::post('/', [UsersController::class, 'create']);
            Route::get('/', [UsersController::class, 'index']);
            Route::get('/{id}', [UsersController::class, 'get']);
            Route::put('/{id}', [UsersController::class, 'update']);
            Route::delete('/{id}', [UsersController::class, 'delete']);
            Route::post('/{id}/reset-password', [UsersController::class, 'resetPassword']);
        });

        Route::prefix('/developer')->group(function () {
            Route::prefix('/fake-serial')->group(function () {
                Route::get('/', [DeveloperFakeSerialController::class, 'show']);
                Route::put('/', [DeveloperFakeSerialController::class, 'update']);
            });

            Route::prefix('/logs')->group(function () {
                Route::get('/zip', [LoggingController::class, 'zip']);

                Route::get('/{id}', [LoggingController::class, 'get']);

                Route::get('/', [LoggingController::class, 'index']);
                Route::delete('/', [LoggingController::class, 'delete']);
            });
        });

        Route::prefix('/plugins')->group(function () {
            Route::get('/registry/sources', [PluginController::class, 'registrySources']);
            Route::put('/registry/sources', [PluginController::class, 'updateRegistrySources']);
            Route::get('/registry', [PluginController::class, 'registry']);
            Route::get('/development', [PluginController::class, 'development']);
            Route::get('/doctor', [PluginController::class, 'doctor']);
            Route::post('/safe-mode', [PluginController::class, 'safeMode']);
            Route::get('/preferences', [PluginController::class, 'preferences']);
            Route::put('/preferences', [PluginController::class, 'updatePreferences']);
            Route::post('/check-updates', [PluginController::class, 'checkUpdates']);
            Route::post('/update-all', [PluginController::class, 'updateAll']);
            Route::post('/disable-all', [PluginController::class, 'disableAll']);
            Route::post('/enable-all', [PluginController::class, 'enableAll']);
            Route::post('/install', [PluginController::class, 'install']);
            Route::delete('/{pluginId}/runtime-storage', [PluginController::class, 'deleteRuntimeStorage']);
            Route::get('/', [PluginController::class, 'index']);
            Route::get('/{pluginId}', [PluginController::class, 'show']);
            Route::put('/{pluginId}/automatic-updates', [PluginController::class, 'setAutomaticUpdates']);
            Route::post('/{pluginId}/enable', [PluginController::class, 'enable']);
            Route::post('/{pluginId}/disable', [PluginController::class, 'disable']);
            Route::post('/{pluginId}/update', [PluginController::class, 'update']);
            Route::delete('/{pluginId}', [PluginController::class, 'delete']);
        });

        Route::put('/printer/{printerId}/slicing', [PrinterController::class, 'updateSlicing']);
    });

    Route::get('/recorder/options', [ConfigurationController::class, 'recorderOptions']);

    Route::get('/printers', [PrinterController::class, 'index']);
    Route::get('/printer/{id}', [PrinterController::class, 'get']);
    Route::get('/printer/{printerId}/slicing', [PrinterController::class, 'getSlicing']);
    Route::get('/printer/{printerId}/slicing/candidates', [PrinterController::class, 'getSlicingCandidates']);
    Route::delete('/printer/{id}', [PrinterController::class, 'delete']);

    Route::get('/cameras', [CameraController::class, 'index']);

    Route::prefix('/camera/{id}')->group(function () {
        Route::get('/', [CameraController::class, 'get']);
        Route::delete('/', [CameraController::class, 'delete']);
        Route::put('/', [CameraController::class, 'update']);

        Route::post('/enable', [CameraController::class, 'enable']);
        Route::post('/disable', [CameraController::class, 'disable']);
        Route::post('/refresh-stream', [CameraController::class, 'refreshStream']);
    });

    Route::get('/files', [FilesController::class,   'index']);
    Route::get('/files/sortingModes', [FilesController::class,   'sortingModes']);

    Route::get('/checkLogin', [SessionController::class, 'id']);

    Route::prefix('/user')->group(function () {

        Route::get('/', [UserController::class, 'get']);

        Route::prefix('/notifications')->group(function () {
            Route::get('/', [UserController::class, 'getNotifications']);
            Route::post('/read', [UserController::class, 'markManyNotificationsAsRead']);

            Route::prefix('/{id}')->group(function () {
                Route::get('/', [UserController::class, 'getNotification']);
                Route::post('/read', [UserController::class, 'markNotificationAsRead']);
                Route::delete('/', [UserController::class, 'deleteNotification']);
            });
        });

        Route::prefix('/settings')->group(function () {
            Route::get('/', [UserController::class,    'getSettings']);
            Route::put('/', [UserController::class,    'updateSettings']);
        });

        Route::post('/logout', [UserController::class,    'logout']);
        Route::put('/password', [UserController::class,    'updatePassword'])->withoutMiddleware('password.ensure_changed');

        Route::get('/materials', [UserController::class,    'materials']);

        Route::post('/material', [UserController::class, 'addMaterial']);
        Route::put('/material/{id}', [UserController::class, 'updateMaterial']);
        Route::delete('/material/{id}', [UserController::class, 'deleteMaterial']);

        Route::prefix('/directory')->group(function () {
            Route::post('/', [UserController::class, 'createDirectory']);
            Route::delete('/', [UserController::class, 'deleteDirectory']);
        });

        Route::prefix('/file')->group(function () {
            Route::delete('/', [UserController::class, 'deleteFile']);
            Route::put('/rename', [UserController::class, 'renameFile']);
            Route::post('/upload', [UserController::class, 'uploadFile']);
        });

        Route::prefix('/printer/selected')->group(function () {
            Route::post('/', [UserController::class, 'setActivePrinterId']);

            Route::middleware('printer.load')->group(function () {
                Route::get('/', [UserController::class, 'getActivePrinterId']);

                Route::get('/recordings', [PrinterController::class, 'getRecordings']);
                Route::delete('/recording/{recordingId}', [PrinterController::class, 'deleteRecording']);

                Route::prefix('/control')->group(function () {
                    Route::post('/movement', [PrinterController::class, 'handleControlCommand']);

                    Route::prefix('/extrusion')->group(function () {
                        Route::post('/extrude', [PrinterController::class, 'handleExtrusion']);
                        Route::post('/retract', [PrinterController::class, 'handleRetraction']);
                    });

                    Route::prefix('/temperature')->group(function () {
                        Route::post('/hotend', [PrinterController::class, 'handleHotendTemperature']);
                        Route::post('/bed', [PrinterController::class, 'handleBedTemperature']);
                    });
                });

                Route::get('/status', [UserController::class, 'getActivePrinterStatus']);
                Route::get('/console', [UserController::class, 'getActivePrinterConsole']);
                Route::get('/cameras', [UserController::class, 'getActivePrinterCameras']);

                Route::prefix('/camera/{cameraId}')->group(function () {
                    Route::post('/link', [PrinterController::class, 'linkCamera']);
                    Route::post('/unlink', [PrinterController::class, 'unlinkCamera']);

                    Route::prefix('/recording')->group(function () {
                        Route::post('/enable', [PrinterController::class, 'enableRecording']);
                        Route::post('/disable', [PrinterController::class, 'disableRecording']);
                    });
                });

                Route::post('/preheat/{materialId}', [PrinterController::class, 'preheatUsingPreset']);
                Route::post('/terminal/queue/command', [PrinterController::class, 'queueCommand']);

                Route::prefix('/print')->group(function () {
                    Route::get('/', [PrinterController::class, 'getPrintStatus']);
                    Route::post('/', [PrinterController::class, 'startPrint']);
                    Route::post('/pause', [PrinterController::class, 'pausePrint']);
                    Route::post('/resume', [PrinterController::class, 'resumePrint']);
                    Route::post('/cancel', [PrinterController::class, 'cancelPrint']);
                    Route::post('/recover', [PrinterController::class, 'recoverPrint']);
                    Route::delete('/recover', [PrinterController::class, 'skipRecovery']);

                    Route::prefix('/gcode')->group(function () {
                        Route::get('/lines/stream', [PrinterController::class, 'getLinesFromActiveFile']);
                        Route::get('/lines/count', [PrinterController::class, 'getLineCountFromActiveFile']);
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
