<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkItemController;
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
    Route::patch('/profile', [AuthController::class, 'updateProfile'])->middleware('auth');

    Route::post('/users', [AuthController::class, 'createUser'])
        ->middleware(['auth', 'role:admin', 'throttle:auth-admin-signup']);
});

Route::middleware('web')->prefix('agency')->group(function () {
    Route::get('/team', [AuthController::class, 'agencyEmployeeDirectory'])
        ->middleware(['auth', 'role:agency_client']);
    Route::get('/directory', [AuthController::class, 'agencyDirectory'])
        ->middleware(['auth', 'role:agency_owner,agency_member,agency_client']);
    Route::patch('/profile', [AuthController::class, 'updateAgencyProfile'])
        ->middleware(['auth', 'role:agency_member,agency_employee,agency_client']);
    Route::get('/users', [AuthController::class, 'agencyUsers'])
        ->middleware(['auth', 'role:agency_owner']);
    Route::post('/users', [AuthController::class, 'createAgencyUser'])
        ->middleware(['auth', 'role:agency_owner', 'throttle:auth-admin-signup']);
    Route::patch('/users/{agencyUser}', [AuthController::class, 'updateAgencyUser'])
        ->middleware(['auth', 'role:agency_owner']);
    Route::delete('/users/{agencyUser}', [AuthController::class, 'deleteAgencyUser'])
        ->middleware(['auth', 'role:agency_owner']);
    Route::get('/employees', [WorkItemController::class, 'agencyEmployees'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/projects', [WorkItemController::class, 'agencyIndex'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::post('/projects', [WorkItemController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'throttle:auth-admin-signup']);
});

Route::middleware(['web', 'auth', 'role:admin'])->group(function () {
    Route::get('/admin/agencies', [AuthController::class, 'agencies']);
    Route::get('/admin/users', [AuthController::class, 'adminUsers']);
    Route::patch('/admin/users/{adminUser}', [AuthController::class, 'updateAdminUser']);
    Route::delete('/admin/users/{adminUser}', [AuthController::class, 'deleteAdminUser']);
    Route::get('/admin/projects', [WorkItemController::class, 'adminIndex']);
});

Route::middleware(['web', 'auth', 'role:employee,agency_employee'])->group(function () {
    Route::get('/work-items/mine', [WorkItemController::class, 'mine']);
    Route::patch('/work-items/{workItem}/status', [WorkItemController::class, 'updateStatus']);
});
