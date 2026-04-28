<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth — public endpoints
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Public key (給各 App fetch，無敏感性)
    Route::get('auth/public-key', [AuthController::class, 'publicKey']);

    // Authenticated examples (#4 OAuth + #2 user endpoints 之後在此擴充)
    Route::middleware(['pandora.jwt:fp'])->group(function () {
        Route::get('users/me/scope-test', [AuthController::class, 'scopeTest']);
    });
});
