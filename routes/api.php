<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailAuthController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Internal\MirrorController;
use App\Http\Controllers\Internal\ReconcileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Token mgmt (#3)
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/public-key', [AuthController::class, 'publicKey']);

    // Email + password (#4)
    Route::post('auth/email/register', [EmailAuthController::class, 'register']);
    Route::post('auth/email/login', [EmailAuthController::class, 'login']);
    Route::post('auth/email/verify', [EmailAuthController::class, 'verify']);

    // OAuth providers (#4)
    Route::get('auth/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'line', 'apple']);
    Route::get('auth/oauth/{provider}/callback', [OAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'line', 'apple']);

    // Demo / smoke
    Route::middleware(['pandora.jwt:fp'])->group(function () {
        Route::get('users/me/scope-test', [AuthController::class, 'scopeTest']);
    });
});

// Internal endpoints (#9 shadow-mirror; secured by shared secret)
Route::prefix('internal')->middleware(['pandora.internal'])->group(function () {
    Route::post('mirror/customer-upsert', [MirrorController::class, 'customerUpsert']);

    // ADR-007 §6 risk #4 mitigation (b) — consumer reconcile (delta pull).
    // Periodic safety net for missed identity webhooks; PII-free response.
    Route::get('reconcile/users', [ReconcileController::class, 'users']);
});
