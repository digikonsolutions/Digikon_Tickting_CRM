<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', function () {
    return response()->json([
        'message' => 'Hello from Laravel!',
    ]);
});

Route::middleware('web')->prefix('auth')->group(function () {
    Route::get('/csrf-cookie', fn () => response()->noContent());
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
    Route::get('/me', [AuthController::class, 'currentUser'])->middleware('auth');

    Route::post('/users', [AuthController::class, 'createUser'])
        ->middleware(['auth', 'role:admin', 'throttle:auth-admin-signup']);
});

Route::middleware('web')->prefix('agency')->group(function () {
    Route::post('/users', [AuthController::class, 'createAgencyUser'])
        ->middleware(['auth', 'role:agency_owner', 'throttle:auth-admin-signup']);
});

Route::middleware(['web', 'auth', 'role:admin'])->get('/admin/agencies', [AuthController::class, 'agencies']);
