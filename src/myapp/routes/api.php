<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ParkingLotController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('parking-lots/{parkingLotId}')->group(function () {
    Route::post('sync-capacity-cache', [ParkingLotController::class, 'syncCapacityCache']);
    Route::post('initialize', [ParkingLotController::class, 'initializeNewParkingLot']);
    Route::post('park', [ParkingLotController::class, 'park']);
    Route::post('unpark', [ParkingLotController::class, 'unpark']);
    Route::get('status', [ParkingLotController::class, 'status']);
});

Route::post('parking-lots', [ParkingLotController::class, 'create']);
Route::put('parking-lots/{parkingLotId}/reinitialize', [ParkingLotController::class, 'reinitialize']);
