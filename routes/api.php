<?php

use App\Models\DeviceVariant;

use Illuminate\Http\Request;

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
