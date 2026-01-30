<?php

use App\Http\Middleware\DisableCors;
use App\Http\Middleware\TokenAuthMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

//Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//    return $request->user();
//});

Route::middleware(DisableCors::class)->group(function () {
    Route::middleware([
        DisableCors::class,
    ])->get('proxy/image', [\App\Http\Controllers\ProxyController::class, 'image']);

    Route::post('user/register', [\App\Http\Controllers\UserController::class, 'create']);

    Route::middleware([
        DisableCors::class,
        TokenAuthMiddleware::class,
    ])->group(function () {
        Route::get('user/auth', [\App\Http\Controllers\UserController::class, 'auth']);

        Route::prefix('room')->group(function() {
            Route::put('', [\App\Http\Controllers\RoomController::class, 'create']);
            Route::prefix('{roomUuid}')->group(function() {
                Route::get('', [\App\Http\Controllers\RoomController::class, 'get']);
                Route::get('snapshotsChart', [\App\Http\Controllers\RoomController::class, 'snapshotsChart']);
                Route::prefix('snapshots')->group(function() {
                    Route::get('', [\App\Http\Controllers\RoomController::class, 'snapshots']);
                    Route::get('{ingameTime}', [\App\Http\Controllers\RoomController::class, 'snapshot']);
                });
            });
        });
    });
});
