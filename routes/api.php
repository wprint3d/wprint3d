<?php

use App\Libraries\Serial;

use App\Models\DeviceVariant;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;

use Illuminate\Support\Str;

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

Route::prefix('serial')->group(function () {
    Route::get('negotiate/{device}', function ($device) {
        $serial = new Serial($device, 115200);

        $response = $serial->query('M105');

        if (!Str::startsWith($response, 'ok')) {
            return response([ 'message' => $response ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return '';
    });
});

Route::prefix('device')->group(function () {
    Route::get('variant/{model}', function ($model) {
        $deviceVariant = DeviceVariant::where('model', $model)->project([ '_id' => false ])->first();

        if ($deviceVariant) {
            return response( $deviceVariant );
        }

        return response('', Response::HTTP_NOT_FOUND);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
