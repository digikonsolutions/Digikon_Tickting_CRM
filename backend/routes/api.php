<?php

use App\Http\Controllers\AdminAgencyController;
use App\Http\Controllers\AdminAgencyOwnerController;
use App\Http\Controllers\AdminPlanController;
use App\Http\Controllers\ContractDocumentController;
use App\Http\Controllers\AdminEmployeeController;
use App\Http\Controllers\AgencyClientController;
use App\Http\Controllers\PublicBrandingController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\AgencyRequestController;
use App\Http\Controllers\AgencyDashboardController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AgencyEmployeeProfileController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AgencyUserController;
use App\Http\Controllers\AgencyBrandingController;
use App\Http\Controllers\EmployeeAssignmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\AdminEmployeeActivityController;
use App\Http\Controllers\EmployeeHeartbeatController;
use App\Http\Controllers\BrowserHistoryController;
use App\Http\Controllers\MonitoringConsentController;
use App\Http\Controllers\ProjectAttachmentController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TimeController;
use App\Http\Controllers\TimeSummaryController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\AdminRushRequestController;
use App\Http\Controllers\AgencyRushRequestController;
use App\Http\Controllers\HoursRequestController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\WorkItemController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('auth')->group(function () {
    Route::get('/csrf-cookie', fn () => response()->noContent());
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/forgot-password', [SessionController::class, 'forgotPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/reset-password', [SessionController::class, 'resetPassword'])->middleware('throttle:auth-password-reset');
    Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth');
    Route::get('/me', [SessionController::class, 'currentUser']);
    Route::patch('/profile', [SessionController::class, 'updateProfile'])->middleware('auth');
    Route::post('/profile/photo', [SessionController::class, 'uploadProfilePhoto'])->middleware(['auth', 'throttle:attachments']);
    Route::delete('/profile/photo', [SessionController::class, 'deleteProfilePhoto'])->middleware('auth');
    Route::put('/password', [SessionController::class, 'changePassword'])->middleware(['auth', 'throttle:password-change']);

    Route::post('/users', [AdminUserController::class, 'createUser'])
        ->middleware(['auth', 'role:admin', 'throttle:auth-admin-signup']);
});

Route::middleware('web')->prefix('agency')->group(function () {
    // Owner and client dashboards, scoped to the caller's own agency. Requests notify every admin.
    Route::get('/dashboard', [AgencyDashboardController::class, 'show'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/requests', [AgencyRequestController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::post('/requests', [AgencyRequestController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active', 'throttle:agency-requests']);
    // Employees' requests for more hours on a ticket: the owner and clients answer.
    Route::middleware(['auth', 'role:agency_owner,agency_client', 'agency.active'])->prefix('hours-requests')->whereNumber('id')->group(function () {
        Route::get('/', [HoursRequestController::class, 'agencyIndex']);
        Route::post('/{id}/approve', [HoursRequestController::class, 'approve'])->middleware('throttle:hours-request');
        Route::post('/{id}/decline', [HoursRequestController::class, 'decline'])->middleware('throttle:hours-request');
    });
    // The GHL service types and their fields, for the create-project, create-ticket and request forms.
    Route::get('/service-catalog', [AgencyRequestController::class, 'catalog'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/employees/summary', [AgencyRequestController::class, 'employees'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/request-attachments/{attachment}/download', [AgencyRequestController::class, 'download'])
        ->middleware(['auth', 'role:agency_owner,agency_client,admin']);
    Route::get('/contract/document', [AgencyDashboardController::class, 'contractDocument'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::get('/team', [AgencyUserController::class, 'agencyTeam'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/directory', [AgencyUserController::class, 'agencyDirectory'])
        ->middleware(['auth', 'role:agency_owner,agency_member,agency_client', 'agency.active']);
        // The owner's Clients tab (profile fields, search, status filter, invitation, impact of deactivating).
    Route::middleware(['auth', 'role:agency_owner', 'agency.active'])->prefix('clients')->whereNumber('client')->group(function () {
        Route::get('/', [AgencyClientController::class, 'index']);
        Route::post('/', [AgencyClientController::class, 'store'])->middleware('throttle:auth-admin-signup');
        Route::get('/{client}', [AgencyClientController::class, 'show']);
        Route::patch('/{client}', [AgencyClientController::class, 'update']);
        Route::post('/{client}/invite', [AgencyClientController::class, 'invite'])->middleware('throttle:auth-admin-signup');
        Route::delete('/{client}', [AgencyClientController::class, 'destroy']);
    });
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
    // The limited public profile (no contact, CNIC, finance or documents), viewable before holding an employee.
    Route::get('/employees/{id}/profile', [AgencyEmployeeProfileController::class, 'show'])
        ->whereNumber('id')->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::post('/assignments', [EmployeeAssignmentController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/assignments', [EmployeeAssignmentController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::get('/assignments/{assignment}/availability', [EmployeeAssignmentController::class, 'availability'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/assignments/{assignment}/usage', [EmployeeAssignmentController::class, 'usage'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    // The employee's queue of open projects: owners and clients read it, only the owner previews and reorders.
    Route::get('/assignments/{assignment}/queue', [QueueController::class, 'show'])
        ->whereNumber('assignment')->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::post('/assignments/{assignment}/queue/preview', [QueueController::class, 'preview'])
        ->whereNumber('assignment')->middleware(['auth', 'role:agency_owner', 'agency.active', 'throttle:queue-reorder']);
    Route::patch('/assignments/{assignment}/queue/reorder', [QueueController::class, 'reorder'])
        ->whereNumber('assignment')->middleware(['auth', 'role:agency_owner', 'agency.active', 'throttle:queue-reorder']);
    // Urgent requests the agency can pay for. Anyone raises one; only the owner accepts or declines a quote.
    Route::middleware(['auth', 'role:agency_owner,agency_client', 'agency.active'])->prefix('rush-requests')->whereNumber('id')->group(function () {
        Route::get('/', [AgencyRushRequestController::class, 'index']);
        Route::post('/', [AgencyRushRequestController::class, 'store'])->middleware('throttle:rush-create');
        Route::get('/{id}', [AgencyRushRequestController::class, 'show']);
        Route::patch('/{id}/cancel', [AgencyRushRequestController::class, 'cancel']);
        Route::post('/{id}/accept', [AgencyRushRequestController::class, 'accept'])->middleware('role:agency_owner');
        Route::post('/{id}/decline', [AgencyRushRequestController::class, 'decline'])->middleware('role:agency_owner');
    });
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
    Route::patch('/projects/{workItem}', [WorkItemController::class, 'update'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::patch('/projects/{workItem}/cancel', [WorkItemController::class, 'cancel'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    // The project's employee timeline: the same activity data as the admin page, filtered to this project.
    Route::get('/projects/{workItem}/activity', [AdminEmployeeActivityController::class, 'projectActivity'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::post('/projects/{workItem}/tickets', [TicketController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/projects/{workItem}/tickets', [TicketController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::patch('/projects/{workItem}/tickets/reorder', [TicketController::class, 'reorder'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::get('/tickets', [TicketController::class, 'agencyIndex'])
        ->middleware(['auth', 'role:agency_owner,agency_client', 'agency.active']);
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    Route::delete('/tickets/{ticket}', [TicketController::class, 'cancel'])
        ->middleware(['auth', 'role:agency_owner,agency_client']);
    // White-label branding: everyone in the agency reads it; only the owner changes it.
    Route::get('/branding', [AgencyBrandingController::class, 'show'])
        ->middleware(['auth', 'role:agency_owner,agency_client,agency_member', 'agency.active']);
    Route::put('/branding', [AgencyBrandingController::class, 'update'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::delete('/branding', [AgencyBrandingController::class, 'reset'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::post('/branding/{kind}', [AgencyBrandingController::class, 'upload'])
        ->whereIn('kind', ['logo', 'logo-collapsed', 'favicon'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active', 'throttle:attachments']);
    Route::delete('/branding/{kind}', [AgencyBrandingController::class, 'destroyAsset'])
        ->whereIn('kind', ['logo', 'logo-collapsed', 'favicon'])
        ->middleware(['auth', 'role:agency_owner', 'agency.active']);
    // Monitoring: agency owners only, and only for employees currently assigned to their own agency.
    Route::get('/employees/{id}/activity', [AdminEmployeeActivityController::class, 'activity'])
        ->whereNumber('id')->middleware(['auth', 'role:agency_owner', 'agency.active']);
    Route::get('/employees/{id}/performance', [AdminEmployeeActivityController::class, 'performance'])
        ->whereNumber('id')->middleware(['auth', 'role:agency_owner', 'agency.active']);
});

// Browser tab history, synced from the Chrome extension. Employees only, and only after consent;
// an employee reads and writes only their own history. Admin access is below, strictly read-only.
Route::middleware(['web', 'auth', 'role:employee'])->prefix('browser-history')->group(function () {
    Route::get('/status', [BrowserHistoryController::class, 'status']);
    Route::get('/me', [BrowserHistoryController::class, 'me']);
    Route::post('/sync', [BrowserHistoryController::class, 'sync'])->middleware('throttle:browser-history-sync');
});

// Admin: read-only across all employees. No create/update/delete route exists for this data at all.
Route::middleware(['web', 'auth', 'role:admin'])->prefix('admin/browser-history')->group(function () {
    Route::get('/summary', [BrowserHistoryController::class, 'adminSummary']);
    Route::get('/events', [BrowserHistoryController::class, 'adminEvents']);
});

// Reports: computed in SQL, scoped by the signed-in user. ?format=csv streams an export.
// Timeline review: the admin queue and actions, the employee's own work, and the agency's read-only view and reply.
Route::middleware(['web', 'auth', 'role:admin'])->prefix('admin')->whereNumber('workItem')->group(function () {
    Route::get('/timeline-reviews', [TimelineController::class, 'queue']);
    Route::get('/timeline-reviews/count', [TimelineController::class, 'count']);
    Route::get('/projects/{workItem}/timeline', [TimelineController::class, 'adminShow']);
    Route::patch('/projects/{workItem}/timeline', [TimelineController::class, 'update']);
    Route::post('/projects/{workItem}/timeline/start-review', [TimelineController::class, 'adminStartReview']);
    Route::post('/projects/{workItem}/timeline/confirm', [TimelineController::class, 'confirm']);
    Route::post('/projects/{workItem}/timeline/request-changes', [TimelineController::class, 'adminRequestChanges']);
});
Route::middleware(['web', 'auth', 'role:employee'])->prefix('work-items')->whereNumber('workItem')->group(function () {
    Route::get('/awaiting-timeline', [TimelineController::class, 'employeeAwaiting']);
    Route::get('/{workItem}/timeline', [TimelineController::class, 'employeeShow']);
    Route::patch('/{workItem}/timeline', [TimelineController::class, 'employeeUpdate']);
    Route::post('/{workItem}/timeline/confirm', [TimelineController::class, 'employeeConfirm']);
});
Route::middleware(['web', 'auth', 'role:agency_owner,agency_client', 'agency.active'])->prefix('agency/projects')->whereNumber('workItem')->group(function () {
    Route::get('/{workItem}/timeline', [TimelineController::class, 'agencyShow']);
    Route::post('/{workItem}/timeline/respond', [TimelineController::class, 'respond'])->middleware('throttle:60,1');
});

Route::middleware(['web', 'throttle:public-branding'])->get('/public/agencies/{slug}/branding', [PublicBrandingController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]{1,60}');

Route::middleware(['web', 'auth', 'role:admin'])->get('/admin/reports/{type}', [ReportController::class, 'admin'])
    ->whereIn('type', ['overview', 'employees', 'agencies', 'projects', 'tickets', 'assignments']);
Route::middleware(['web', 'auth', 'role:agency_owner,agency_client', 'agency.active'])->get('/agency/reports/{type}', [ReportController::class, 'agency'])
    ->whereIn('type', ['overview', 'employees', 'projects', 'tickets']);

Route::middleware(['web', 'auth', 'role:admin'])->group(function () {
    Route::get('/admin/users',[AdminUserController::class, 'adminUsers']);
    Route::patch('/admin/users/{adminUser}', [AdminUserController::class, 'updateAdminUser']);
    Route::delete('/admin/users/{adminUser}', [AdminUserController::class, 'deleteAdminUser']);
    // Agency onboarding wizard, drafts, contract files and plans (admin only).
    Route::post('/admin/agency-owners', [AdminAgencyOwnerController::class, 'store'])->middleware('throttle:auth-admin-signup');
    Route::get('/admin/employees/available', [AdminAgencyOwnerController::class, 'availableEmployees']);
    Route::get('/admin/account-managers', [AdminAgencyOwnerController::class, 'accountManagers']);
    Route::get('/admin/plans', [AdminPlanController::class, 'index']);
    Route::post('/admin/plans', [AdminPlanController::class, 'store']);
    Route::patch('/admin/plans/{plan}', [AdminPlanController::class, 'update'])->whereNumber('plan');
    Route::post('/admin/agencies/{agency}/activate', [AdminAgencyController::class, 'activate'])->whereNumber('agency');
    Route::put('/admin/agencies/{agency}/contract', [AdminAgencyController::class, 'updateContract'])->whereNumber('agency');
    Route::post('/admin/agencies/{agency}/employees', [AdminAgencyController::class, 'assignEmployee'])->whereNumber('agency');
    Route::patch('/admin/agencies/{agency}/details', [AdminAgencyController::class, 'updateDetails'])->whereNumber('agency');
    Route::patch('/admin/agencies/{agency}/branding', [AdminAgencyController::class, 'updateBranding'])->whereNumber('agency');
    Route::post('/admin/agencies/{agency}/logo', [AdminAgencyController::class, 'uploadLogo'])->whereNumber('agency')->middleware('throttle:attachments');
    Route::post('/admin/agencies/{agency}/contract-documents', [ContractDocumentController::class, 'store'])->whereNumber('agency')->middleware('throttle:attachments');
    Route::get('/admin/agencies/{agency}/contract-documents/{document}', [ContractDocumentController::class, 'download'])->whereNumber(['agency', 'document']);
    Route::get('/admin/agencies', [AdminAgencyController::class, 'index']);
    Route::get('/admin/agencies/{agency}', [AdminAgencyController::class, 'show'])->whereNumber('agency');
    Route::patch('/admin/agencies/{agency}', [AdminUserController::class, 'updateAdminAgency']);
    Route::get('/admin/time-summary', [TimeSummaryController::class, 'admin']);
    Route::get('/admin/dashboard', [WorkItemController::class, 'adminDashboard']);
    Route::get('/admin/projects', [WorkItemController::class, 'adminIndex']);
    Route::get('/admin/projects/{workItem}', [WorkItemController::class, 'adminShow']);
    Route::get('/admin/employees/{employee}/queue', [QueueController::class, 'admin'])->whereNumber('employee');
    Route::get('/admin/rush-requests', [AdminRushRequestController::class, 'index']);
    Route::post('/admin/rush-requests/{id}/quote', [AdminRushRequestController::class, 'quote'])->whereNumber('id');
    Route::post('/admin/rush-requests/{id}/reject', [AdminRushRequestController::class, 'reject'])->whereNumber('id');
    Route::get('/admin/charges', [AdminRushRequestController::class, 'charges']);
    Route::get('/admin/assignments', [EmployeeAssignmentController::class, 'adminIndex']);
    Route::delete('/admin/assignments/{assignment}', [EmployeeAssignmentController::class, 'adminDestroy']);
    Route::get('/admin/tickets', [TicketController::class, 'adminIndex']);
    Route::get('/admin/employees/{id}/activity', [AdminEmployeeActivityController::class, 'activity'])->whereNumber('id');
    Route::get('/admin/employees/{id}/performance', [AdminEmployeeActivityController::class, 'performance'])->whereNumber('id');

    // Employee profiles (personal, employment, finance, documents). Admin only; sensitive reads are audited.
    Route::post('/admin/employees', [AdminEmployeeController::class, 'store'])->middleware('throttle:auth-admin-signup');
    Route::get('/admin/employees/{id}', [AdminEmployeeController::class, 'show'])->whereNumber('id');
    Route::patch('/admin/employees/{id}', [AdminEmployeeController::class, 'update'])->whereNumber('id');
    Route::post('/admin/employees/{id}/reveal-cnic', [AdminEmployeeController::class, 'revealCnic'])
        ->whereNumber('id')->middleware('throttle:sensitive-reveal');
    Route::post('/admin/employees/{id}/photo', [AdminEmployeeController::class, 'uploadPhoto'])
        ->whereNumber('id')->middleware('throttle:attachments');
    Route::delete('/admin/employees/{id}/photo', [AdminEmployeeController::class, 'deletePhoto'])->whereNumber('id');
    Route::post('/admin/employees/{id}/documents', [EmployeeDocumentController::class, 'store'])
        ->whereNumber('id')->middleware('throttle:attachments');
    Route::get('/admin/employees/{id}/documents/{document}', [EmployeeDocumentController::class, 'download'])
        ->whereNumber(['id', 'document']);
    Route::patch('/admin/employees/{id}/documents/{document}/verify', [EmployeeDocumentController::class, 'verify'])
        ->whereNumber(['id', 'document']);
    Route::delete('/admin/employees/{id}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])
        ->whereNumber(['id', 'document']);
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
    Route::post('/employee/heartbeat', [EmployeeHeartbeatController::class, 'store'])->middleware('throttle:heartbeat');
    Route::get('/employee/monitoring-policy', [MonitoringConsentController::class, 'show']);
    Route::post('/employee/monitoring-policy/accept', [MonitoringConsentController::class, 'accept']);
    Route::get('/work-items/mine', [WorkItemController::class, 'mine']);
    Route::post('/work-items/{workItem}/start', [WorkItemController::class, 'start'])->whereNumber('workItem');
    Route::post('/work-items/{workItem}/time/start', [TimeController::class, 'startProject'])->whereNumber('workItem');
    Route::post('/work-items/{workItem}/time', [TimeController::class, 'storeProject'])->whereNumber('workItem');
    Route::post('/work-items/{workItem}/finish', [WorkItemController::class, 'finish'])->whereNumber('workItem');
    Route::get('/work-items/mine/tickets', [TicketController::class, 'mine']);
    Route::get('/work-items/mine/queue', [QueueController::class, 'mine']);
    // Ask the agency for more hours on one of my tickets, or withdraw the request.
    Route::post('/tickets/{ticket}/hours-requests', [HoursRequestController::class, 'store'])->whereNumber('ticket')->middleware('throttle:hours-request');
    Route::patch('/hours-requests/{id}/cancel', [HoursRequestController::class, 'cancel'])->whereNumber('id');
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

// Files on a project brief (private disk, authorised per request).
Route::middleware('web')->group(function () {
    Route::get('/projects/{workItem}/attachments', [ProjectAttachmentController::class, 'index'])
        ->middleware(['auth', 'role:agency_owner,agency_client,agency_member,employee,admin']);
    Route::post('/projects/{workItem}/attachments', [ProjectAttachmentController::class, 'store'])
        ->middleware(['auth', 'role:agency_owner,agency_client,agency_member,employee,admin', 'throttle:attachments']);
    Route::get('/project-attachments/{attachment}/download', [ProjectAttachmentController::class, 'download'])
        ->middleware(['auth', 'role:agency_owner,agency_client,agency_member,employee,admin']);
    Route::delete('/project-attachments/{attachment}', [ProjectAttachmentController::class, 'destroy'])
        ->middleware(['auth', 'role:agency_owner,agency_client,agency_member,employee,admin']);
});

// The in-app assistant: role-specific preset questions answered from the database (every role).
Route::middleware(['web', 'auth', 'throttle:insights'])->prefix('assistant')->group(function () {
    Route::get('/questions', [AssistantController::class, 'questions']);
    Route::post('/ask', [AssistantController::class, 'ask']);
});
