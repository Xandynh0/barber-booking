<?php

use App\Http\Controllers\Admin\AuthController;
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
    });
});
