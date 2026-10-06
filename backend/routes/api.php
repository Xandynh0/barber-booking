<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ProfessionalController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1/admin')->name('admin.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware(['throttle:admin-login-email', 'throttle:admin-login-ip'])
        ->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
        Route::patch('/services/{service}', [ServiceController::class, 'update'])->name('services.update');

        Route::get('/professionals', [ProfessionalController::class, 'index'])->name('professionals.index');
        Route::post('/professionals', [ProfessionalController::class, 'store'])->name('professionals.store');
        Route::patch('/professionals/{professional}', [ProfessionalController::class, 'update'])->name('professionals.update');
    });
});
