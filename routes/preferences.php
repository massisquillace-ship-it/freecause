<?php

use App\Http\Controllers\PreferencesController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/preferences', [PreferencesController::class, 'show'])
    ->middleware('profile.complete')
    ->name('preferences.show');

Route::post('/preferences', [PreferencesController::class, 'update'])
    ->middleware(['throttle:10,1', 'profile.complete'])
    ->name('preferences.update');

Route::match(['get', 'post'], '/unsubscribe/{token}', [PreferencesController::class, 'unsubscribe'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('preferences.unsubscribe');
