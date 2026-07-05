<?php

use App\Http\Controllers\Api\CameraController;
use App\Http\Controllers\Api\MlCallbackController;
use Illuminate\Support\Facades\Route;

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
