<?php

use App\Livewire\Actions\Logout;
use App\Livewire\Annotation\Index as AnnotationIndex;
use App\Livewire\Dashboard;
use App\Livewire\LiveCamera\Index as LiveCameraIndex;
use App\Livewire\Logs\Index as LogsIndex;
use App\Livewire\Products\Index as ProductsIndex;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Training\Index as TrainingIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::get('users', UsersIndex::class)->name('users');
    Route::get('products', ProductsIndex::class)->name('products');
    Route::get('roles', RolesIndex::class)->name('roles');
    Route::get('live-camera', LiveCameraIndex::class)->name('live-camera');
    Route::get('training', TrainingIndex::class)->name('training');
    Route::get('annotation', AnnotationIndex::class)->name('annotation');
    Route::get('settings', SettingsIndex::class)->name('settings');
    Route::get('logs', LogsIndex::class)->name('logs');

    // Breeze profile page (kept)
    Route::view('profile', 'profile')->name('profile');

    Route::post('logout', function (Logout $logout) {
        $logout();

        return redirect('/');
    })->name('logout');
});

require __DIR__.'/auth.php';
