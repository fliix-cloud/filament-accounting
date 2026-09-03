<?php

use FilamentAccounting\Banking\FinTs\Http\Controllers\ScaChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('filament-accounting/fints')
    ->name('filament-accounting.fints.')
    ->group(function (): void {
        Route::get('sca/{session}/challenge', ScaChallengeController::class)
            ->name('sca.challenge');
    });
