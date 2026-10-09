<?php

use App\Http\Controllers\PublicCancellationController;
use App\Http\Middleware\CancellationPageHeaders;
use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Customer cancellation through the signed link sent by e-mail
// (docs/planejamento-barbearia-mvp.md, seção 6). The proxy forwards
// /cancelar/ to this backend; everything else under / goes to the SPA.
Route::middleware([
    CancellationPageHeaders::class,
    SetLocaleFromAcceptLanguage::class,
    'throttle:public-cancellation',
    'signed:relative',
])->group(function () {
    Route::get('/cancelar/{publicId}', [PublicCancellationController::class, 'show'])
        ->name('appointments.cancel.show');
    Route::post('/cancelar/{publicId}', [PublicCancellationController::class, 'store'])
        ->name('appointments.cancel.store');
});
