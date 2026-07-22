<?php

use App\Http\Controllers\Api\ArmController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CameraController;
use App\Http\Controllers\Api\DetectionController;
use App\Http\Controllers\Api\MlCallbackController;
use App\Http\Controllers\Api\StatusController;
use Illuminate\Support\Facades\Route;

/*
| Mobile app REST API (Opsi A). Authenticated with Sanctum personal access
| tokens — separate from the Livewire/Breeze web session. See API_CONTRACT.md.
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('status', [StatusController::class, 'show']);
    Route::get('detections', [DetectionController::class, 'index']);
    Route::get('arm', [ArmController::class, 'show']);
});

/*
| Internal callbacks from the FastAPI ML service. Not browser-facing —
| authenticated by an HMAC signature (see verify.ml middleware), not sessions.
*/
Route::middleware('verify.ml')->prefix('ml')->group(function () {
    Route::post('training/{run}/progress', [MlCallbackController::class, 'progress']);
    Route::post('training/{run}/complete', [MlCallbackController::class, 'complete']);
    Route::post('training/{run}/fail', [MlCallbackController::class, 'fail']);
});

/*
| ICAM-300 stream detections ingested from the ml-service (same HMAC auth).
*/
Route::middleware('verify.ml')->prefix('camera')->group(function () {
    Route::post('detection', [CameraController::class, 'ingest']);
});
