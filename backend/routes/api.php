<?php

use App\Http\Controllers\Admin\AgendaController;
use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusinessSettingsController;
use App\Http\Controllers\Admin\ProfessionalController;
use App\Http\Controllers\Admin\ScheduleBlockController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\WorkingHourController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicAppointmentController;
use App\Http\Controllers\PublicCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1/public')->name('public.')->group(function () {
    Route::middleware('throttle:public-catalog')->group(function () {
        Route::get('/business', [PublicCatalogController::class, 'business'])->name('business');
        Route::get('/services', [PublicCatalogController::class, 'services'])->name('services');
        Route::get('/services/{service}/professionals', [PublicCatalogController::class, 'professionals'])
            ->whereNumber('service')
            ->name('services.professionals');
    });
    Route::get('/availability', [AvailabilityController::class, 'forPublic'])
        ->middleware('throttle:public-availability')
        ->name('availability');
    Route::post('/appointments', [PublicAppointmentController::class, 'store'])
        ->middleware('throttle:public-appointments')
        ->name('appointments.store');
});

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

        Route::get('/professionals/{professional}/working-hours', [WorkingHourController::class, 'show'])
            ->name('professionals.working-hours.show');
        Route::put('/professionals/{professional}/working-hours', [WorkingHourController::class, 'update'])
            ->name('professionals.working-hours.update');

        Route::get('/schedule-blocks', [ScheduleBlockController::class, 'index'])->name('schedule-blocks.index');
        Route::post('/schedule-blocks', [ScheduleBlockController::class, 'store'])->name('schedule-blocks.store');
        Route::delete('/schedule-blocks/groups/{groupId}', [ScheduleBlockController::class, 'destroyGroup'])
            ->name('schedule-blocks.destroy-group');
        Route::delete('/schedule-blocks/{scheduleBlock}', [ScheduleBlockController::class, 'destroy'])
            ->name('schedule-blocks.destroy');

        Route::get('/business-settings', [BusinessSettingsController::class, 'show'])->name('business-settings.show');

        Route::get('/availability', [AvailabilityController::class, 'forAdmin'])->name('availability');

        Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
        Route::get('/appointments/{appointment:public_id}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::middleware('throttle:admin-agenda-writes')->group(function () {
            Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
            Route::post('/appointments/{appointment:public_id}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
            Route::post('/appointments/{appointment:public_id}/complete', [AppointmentController::class, 'complete'])->name('appointments.complete');
            Route::post('/appointments/{appointment:public_id}/no-show', [AppointmentController::class, 'noShow'])->name('appointments.no-show');
        });
        Route::post('/appointments/{appointment:public_id}/resend-confirmation', [AppointmentController::class, 'resendConfirmation'])
            ->middleware('throttle:admin-resend-confirmation')
            ->name('appointments.resend-confirmation');
    });
});
