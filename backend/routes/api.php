<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AgencyUserController;
use App\Http\Controllers\EmployeeAssignmentController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WorkItemController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('auth')->group(function () {
    Route::get('/csrf-cookie', fn () => response()->noContent());
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/forgot-password', [SessionController::class, 'forgotPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/reset-password', [SessionController::class, 'resetPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth');
    Route::get('/me', [SessionController::class, 'currentUser'])->middleware('auth');
    Route::patch('/profile', [SessionController::class, 'updateProfile'])->middleware('auth');

    Route::post('/users', [AdminUserController::class, 'createUser'])
        ->middleware(['auth', 'role:admin', 'throttle:auth-admin-signup']);
});

Route::middleware('web')->prefix('agency')->group(function () {
    Route::get('/team', [AgencyUserController::class, 'agencyTeam'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/directory', [AgencyUserController::class, 'agencyDirectory'])
        ->middleware(['auth', 'role:agency_owner,agency_member,agency_client']);
    Route::patch('/profile', [AgencyUserController::class, 'updateAgencyProfile'])
        ->middleware(['auth', 'role:agency_member,agency_client']);
    Route::get('/users', [AgencyUserController::class, 'agencyUsers'])
        ->middleware(['auth', 'role:agency_owner']);
    Route::post('/users', [AgencyUserController::class, 'createAgencyUser'])
        ->middleware(['auth', 'role:agency_owner', 'throttle:auth-admin-signup']);
    Route::patch('/users/{agencyUser}', [AgencyUserController::class, 'updateAgencyUser'])
        ->middleware(['auth', 'role:agency_owner']);
    Route::delete('/users/{agencyUser}', [AgencyUserController::class, 'deleteAgencyUser'])
        ->middleware(['auth', 'role:agency_owner']);
    Route::get('/employees/available', [EmployeeAssignmentController::class, 'available'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::post('/assignments', [EmployeeAssignmentController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/assignments', [EmployeeAssignmentController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/assignments/{assignment}/usage', [EmployeeAssignmentController::class, 'usage'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::delete('/assignments/{assignment}', [EmployeeAssignmentController::class, 'destroy'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/projects', [WorkItemController::class, 'agencyIndex'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::post('/projects', [WorkItemController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'throttle:project-create']);
    Route::patch('/projects/{workItem}/cancel', [WorkItemController::class, 'cancel'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::post('/projects/{workItem}/tickets', [TicketController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/projects/{workItem}/tickets', [TicketController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::patch('/projects/{workItem}/tickets/reorder', [TicketController::class, 'reorder'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::delete('/tickets/{ticket}', [TicketController::class, 'cancel'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
});

Route::middleware(['web', 'auth', 'role:admin'])->group(function () {
    Route::get('/admin/users', [AdminUserController::class, 'adminUsers']);
    Route::patch('/admin/users/{adminUser}', [AdminUserController::class, 'updateAdminUser']);
    Route::delete('/admin/users/{adminUser}', [AdminUserController::class, 'deleteAdminUser']);
    Route::patch('/admin/agencies/{agency}', [AdminUserController::class, 'updateAdminAgency']);
    Route::get('/admin/projects', [WorkItemController::class, 'adminIndex']);
    Route::get('/admin/assignments', [EmployeeAssignmentController::class, 'adminIndex']);
    Route::delete('/admin/assignments/{assignment}', [EmployeeAssignmentController::class, 'adminDestroy']);
    Route::get('/admin/tickets', [TicketController::class, 'adminIndex']);
});

Route::middleware('web')->group(function () {
    Route::post('/tickets/{ticket}/comments', [TicketController::class, 'comment'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus'])
        ->middleware(['auth', 'role:employee']);
});

Route::middleware(['web', 'auth', 'role:employee'])->group(function () {
    Route::get('/work-items/mine', [WorkItemController::class, 'mine']);
    Route::patch('/work-items/{workItem}/status', [WorkItemController::class, 'updateStatus']);
    Route::get('/work-items/mine/tickets', [TicketController::class, 'mine']);
});
