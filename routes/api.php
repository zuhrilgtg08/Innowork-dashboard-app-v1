<?php

use App\Http\Controllers\Api\ArmController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CameraController;
use App\Http\Controllers\Api\CameraFeedController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ConveyorController;
use App\Http\Controllers\Api\DetectionController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MlCallbackController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReturnBatchController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TrainingRunController;
use App\Http\Controllers\Api\UserController;
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
| CRUD resources for the mobile app (Fase 1).
|
| Every route is additionally gated by the role × module matrix via the
| `module:<Module>,<read|write>` middleware. This is stricter than the web
| dashboard, where the matrix is editable but not enforced — see
| App\Http\Middleware\EnsureModuleAccess for why.
|
| Module names must match RolePermission::MODULES exactly.
*/
Route::middleware('auth:sanctum')->group(function () {
    // Products — module "Product" (singular, as defined in RolePermission::MODULES).
    Route::get('products', [ProductController::class, 'index'])->middleware('module:Product,read');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('module:Product,read');
    Route::post('products', [ProductController::class, 'store'])->middleware('module:Product,write');
    Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update'])->middleware('module:Product,write');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('module:Product,write');

    // Categories
    Route::get('categories', [CategoryController::class, 'index'])->middleware('module:Categories,read');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->middleware('module:Categories,read');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('module:Categories,write');
    Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])->middleware('module:Categories,write');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('module:Categories,write');

    // Users + the permission matrix itself.
    Route::get('users', [UserController::class, 'index'])->middleware('module:Users,read');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('module:Users,read');
    Route::post('users', [UserController::class, 'store'])->middleware('module:Users,write');
    Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->middleware('module:Users,write');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('module:Users,write');
    Route::get('roles', [RoleController::class, 'index'])->middleware('module:Users,read');
    Route::put('roles', [RoleController::class, 'update'])->middleware('module:Users,write');

    // Training runs
    Route::get('training-runs', [TrainingRunController::class, 'index'])->middleware('module:Training,read');
    Route::get('training-runs/dataset', [TrainingRunController::class, 'dataset'])->middleware('module:Training,read');
    Route::get('training-runs/{trainingRun}', [TrainingRunController::class, 'show'])->middleware('module:Training,read');
    Route::post('training-runs', [TrainingRunController::class, 'store'])->middleware('module:Training,write');

    // System logs (read-only)
    Route::get('logs', [LogController::class, 'index'])->middleware('module:Logs,read');
    Route::get('logs/filters', [LogController::class, 'filters'])->middleware('module:Logs,read');

    // Settings singleton
    Route::get('settings', [SettingController::class, 'show'])->middleware('module:Settings,read');
    Route::match(['put', 'patch'], 'settings', [SettingController::class, 'update'])->middleware('module:Settings,write');

    // Arm movement command. Gated on write access to "Live Camera" (the
    // line-control surface: viewers hold read-only there) and rate limited,
    // because each call moves physical hardware.
    Route::post('arm/command', [ArmController::class, 'command'])
        ->middleware(['module:Live Camera,write', 'throttle:30,1']);

    // Cameras + live feed. Gated on "Live Camera" so a role that cannot open
    // the camera module cannot pull frames off the line either.
    Route::get('cameras', [CameraFeedController::class, 'index'])->middleware('module:Live Camera,read');
    Route::get('cameras/status', [CameraFeedController::class, 'status'])->middleware('module:Live Camera,read');
    Route::get('cameras/frame', [CameraFeedController::class, 'frame'])->middleware('module:Live Camera,read');

    // QC return batches
    Route::get('returns', [ReturnBatchController::class, 'index'])->middleware('module:Returns,read');
    Route::get('returns/{returnBatch}', [ReturnBatchController::class, 'show'])->middleware('module:Returns,read');
    Route::post('returns/{returnBatch}/resolve', [ReturnBatchController::class, 'resolve'])->middleware('module:Returns,write');
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

/*
| Conveyor off-flow anomalies (jam / off_flow) from the ml-service flow analyser.
*/
Route::middleware('verify.ml')->prefix('conveyor')->group(function () {
    Route::post('event', [ConveyorController::class, 'event']);
});
