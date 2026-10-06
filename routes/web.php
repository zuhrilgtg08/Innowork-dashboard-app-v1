<?php

use App\Http\Controllers\CameraStreamController;
use App\Livewire\Actions\Logout;
use App\Livewire\Annotation\Index as AnnotationIndex;
use App\Livewire\Categories\Index as CategoriesIndex;
use App\Livewire\Dashboard;
use App\Livewire\Detection\Index as DetectionIndex;
use App\Livewire\LiveCamera\Index as LiveCameraIndex;
use App\Livewire\Logs\Index as LogsIndex;
use App\Livewire\ModelEvaluation\Index as ModelEvaluationIndex;
use App\Livewire\Products\Index as ProductsIndex;
use App\Livewire\PublicProduct;
use App\Livewire\Returns\Index as ReturnsIndex;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Sorting\DeviceStatus;
use App\Livewire\Sorting\Events;
use App\Livewire\Training\Index as TrainingIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return config('services.sorting.competition_mode')
            ? redirect()->route('sorting.dashboard')
            : redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

// Public QC verdict page reached by scanning a product QR code (no auth).
Route::get('/p/{token}', PublicProduct::class)->name('public.product');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::get('users', UsersIndex::class)->name('users');
    Route::get('products', ProductsIndex::class)->name('products');
    Route::get('categories', CategoriesIndex::class)->name('categories');
    Route::get('roles', RolesIndex::class)->name('roles');
    Route::get('live-camera', LiveCameraIndex::class)->name('live-camera');
    Route::get('detection', DetectionIndex::class)->name('detection');
    Route::get('returns', ReturnsIndex::class)->name('returns');
    Route::get('training', TrainingIndex::class)->name('training');
    Route::get('annotation', AnnotationIndex::class)->name('annotation');
    Route::get('settings', SettingsIndex::class)->name('settings');
    Route::get('logs', LogsIndex::class)->name('logs');

    // Vision Sorting routes (operational board, events, device health)
    Route::get('sorting', App\Livewire\Sorting\Dashboard::class)->name('sorting.dashboard');
    Route::get('sorting-events', Events::class)->name('sorting.events');
    Route::get('device-status', DeviceStatus::class)->name('sorting.device-status');

    // Model Evaluation (read-only YOLO inspection; repurposed Training entry)
    Route::get('model-evaluation', ModelEvaluationIndex::class)->name('model-evaluation');

    // Same-origin relay for the iCAM-300 camera streams (see
    // App\Http\Controllers\CameraStreamController). The browser must never
    // talk to the ML service's internal address directly — a remote viewer
    // would resolve 127.0.0.1 to its own machine, and the IoT Suite iframe
    // must stay on the Laravel origin.
    Route::get('ml/camera/stream', [CameraStreamController::class, 'stream'])->name('ml.camera.stream');
    Route::get('ml/camera/preview', [CameraStreamController::class, 'preview'])->name('ml.camera.preview');
    Route::get('ml/camera/frame', [CameraStreamController::class, 'frame'])->name('ml.camera.frame');

    // Breeze profile page (kept)
    Route::view('profile', 'profile')->name('profile');

    Route::post('logout', function (Logout $logout) {
        $logout();

        return redirect('/');
    })->name('logout');
});

require __DIR__.'/auth.php';
