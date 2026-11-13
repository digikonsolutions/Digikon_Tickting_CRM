<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AgencyUserController;
use App\Http\Controllers\EmployeeAssignmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TimeController;
use App\Http\Controllers\TimeSummaryController;
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
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/directory', [AgencyUserController::class, 'agencyDirectory'])
        ->middleware(['auth', 'role:agency_owner,agency_member,agency_client', 'agency.active']);
    Route::patch('/profile', [AgencyUserController::class, 'updateAgencyProfile'])
        ->middleware(['auth', 'role:agency_member,agency_client', 'agency.active']);
    Route::get('/users', [AgencyUserController::class, 'agencyUsers'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::post('/users', [AgencyUserController::class, 'createAgencyUser'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active', 'throttle:auth-admin-signup']);
    Route::patch('/users/{agencyUser}', [AgencyUserController::class, 'updateAgencyUser'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::delete('/users/{agencyUser}', [AgencyUserController::class, 'deleteAgencyUser'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::get('/employees/available', [EmployeeAssignmentController::class, 'available'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::post('/assignments', [EmployeeAssignmentController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/assignments', [EmployeeAssignmentController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/assignments/{assignment}/usage', [EmployeeAssignmentController::class, 'usage'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::delete('/assignments/{assignment}', [EmployeeAssignmentController::class, 'destroy'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/attention', [TicketController::class, 'attention'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/time-summary', [TimeSummaryController::class, 'agency'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::get('/capacity', [WorkItemController::class, 'capacity'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/projects', [WorkItemController::class, 'agencyIndex'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/projects/{workItem}', [WorkItemController::class, 'agencyShow'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::post('/projects', [WorkItemController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active', 'throttle:project-create']);
    Route::patch('/projects/{workItem}/cancel', [WorkItemController::class, 'cancel'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
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
    Route::get('/admin/time-summary', [TimeSummaryController::class, 'admin']);
    Route::get('/admin/dashboard', [WorkItemController::class, 'adminDashboard']);
    Route::get('/admin/projects', [WorkItemController::class, 'adminIndex']);
    Route::get('/admin/projects/{workItem}', [WorkItemController::class, 'adminShow']);
    Route::get('/admin/assignments', [EmployeeAssignmentController::class, 'adminIndex']);
    Route::delete('/admin/assignments/{assignment}', [EmployeeAssignmentController::class, 'adminDestroy']);
    Route::get('/admin/tickets', [TicketController::class, 'adminIndex']);
});

Route::middleware('web')->group(function () {
    Route::get('/tickets/key/{key}', [TicketController::class, 'showByKey'])
        ->middleware(['auth']);
    Route::get('/tickets/{ticket}/timeline', [TicketController::class, 'timeline'])
        ->middleware(['auth']);
    Route::post('/tickets/{ticket}/transitions', [TicketController::class, 'transition'])
        ->middleware(['auth']);
    Route::get('/tickets/{ticket}/attachments', [AttachmentController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::post('/tickets/{ticket}/attachments', [AttachmentController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin', 'throttle:attachments']);
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::get('/tickets/{ticket}/comments', [TicketController::class, 'comments'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::post('/tickets/{ticket}/comments', [TicketController::class, 'comment'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus'])
        ->middleware(['auth', 'role:employee']);
});

Route::middleware(['web', 'auth', 'role:employee'])->group(function () {
    Route::get('/work-items/mine', [WorkItemController::class, 'mine']);
    Route::get('/work-items/mine/tickets', [TicketController::class, 'mine']);
});

// Notifications for the signed-in user (any role). Everything is scoped to the caller.
Route::middleware(['web', 'auth', 'throttle:notifications'])->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/preferences', [NotificationController::class, 'preferences']);
    Route::put('/preferences', [NotificationController::class, 'updatePreferences']);
    Route::post('/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
});

// Time tracking. Logging is for the assigned employee; admins may only correct entries (with a reason).
Route::middleware('web')->group(function () {
    Route::get('/tickets/{ticket}/time', [TimeController::class, 'ticket'])
        ->middleware(['auth', 'role:agency_owner,agency_client,employee,admin']);
    Route::post('/tickets/{ticket}/time/start', [TimeController::class, 'start'])
        ->middleware(['auth', 'role:employee']);
    Route::post('/tickets/{ticket}/time', [TimeController::class, 'store'])
        ->middleware(['auth', 'role:employee']);
    Route::post('/time/stop', [TimeController::class, 'stop'])->middleware(['auth', 'role:employee']);
    Route::get('/time/active', [TimeController::class, 'active'])->middleware(['auth', 'role:employee']);
    Route::get('/time/week', [TimeController::class, 'week'])->middleware(['auth', 'role:employee']);
    Route::patch('/time/{entry}', [TimeController::class, 'update'])->middleware(['auth', 'role:employee,admin']);
    Route::delete('/time/{entry}', [TimeController::class, 'destroy'])->middleware(['auth', 'role:employee,admin']);
});
