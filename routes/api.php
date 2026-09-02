<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\HelpdeskController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\MonthlyAttendanceReportController;
use App\Http\Middleware\TokenAuthMiddleware;
use App\Models\Organization;
use Illuminate\Http\Request;

// Public Auth & Branding Routes
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/organization/branding', [AdminController::class, 'getBranding']);

// Authenticated Routes
Route::middleware(TokenAuthMiddleware::class)->group(function () {
    // Auth & Personal
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1');

    // Departments & Shifts
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::get('/shifts', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        $shifts = \App\Models\Shift::where('organization_id', $user->organization_id)->get();
        return response()->json(['shifts' => $shifts]);
    });
    Route::post('/shifts', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        $request->validate([
            'name' => 'required|string',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'grace_period_minutes' => 'nullable|integer',
        ]);
        $shift = \App\Models\Shift::create([
            'organization_id' => $user->organization_id,
            'name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'grace_period_minutes' => $request->grace_period_minutes ?? 15,
            'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ]);
        return response()->json(['message' => 'Custom shift timing created successfully', 'shift' => $shift]);
    });
    Route::put('/shifts/{id}', function (\Illuminate\Http\Request $request, $id) {
        $user = $request->user();
        $shift = \App\Models\Shift::where('organization_id', $user->organization_id)->where('id', $id)->first();
        if (!$shift) {
            return response()->json(['message' => 'Shift not found'], 404);
        }
        $request->validate([
            'name' => 'sometimes|string',
            'start_time' => 'sometimes|string',
            'end_time' => 'sometimes|string',
            'grace_period_minutes' => 'nullable|integer',
        ]);
        $shift->update($request->only(['name', 'start_time', 'end_time', 'grace_period_minutes']));
        return response()->json(['message' => 'Shift timing updated successfully', 'shift' => $shift]);
    });

    // Employee Profile & Master Record
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('role:admin,hr');
    Route::get('/employees/{id}', [EmployeeController::class, 'show']);
    Route::put('/employees/{id}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])->middleware('role:admin,hr');
    Route::post('/employees/{id}/documents', [EmployeeController::class, 'uploadDocument']);

    // Attendance
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::post('/attendance/auto-checkout', [AttendanceController::class, 'triggerAutoCheckout']);
    Route::get('/attendance/history', [AttendanceController::class, 'history']);
    Route::get('/attendance/summary', [AttendanceController::class, 'summary']);
    Route::post('/attendance/correction', [AttendanceController::class, 'adminCorrection'])->middleware('role:admin,hr');
    Route::post('/attendance/correction/{id}', [AttendanceController::class, 'adminCorrection'])->middleware('role:admin,hr');
    Route::post('/attendance/update-schedule', [AttendanceController::class, 'updateSchedule'])->middleware('role:admin,hr');
    Route::get('/attendance/schedule', [AttendanceController::class, 'getSchedule']);
    Route::get('/attendance/office-location', [AttendanceController::class, 'getOfficeLocation']);
    Route::post('/attendance/office-location', [AttendanceController::class, 'updateOfficeLocation'])->middleware('role:admin,hr');

    // Leave
    Route::get('/leave/types', [LeaveController::class, 'getLeaveTypes']);
    Route::get('/leave/balances', [LeaveController::class, 'getBalances']);
    Route::get('/leave/requests', [LeaveController::class, 'index']);
    Route::post('/leave/requests', [LeaveController::class, 'store']);
    Route::post('/leave/requests/{id}/approve', [LeaveController::class, 'approve'])->middleware('role:admin');
    Route::post('/leave/requests/{id}/reject', [LeaveController::class, 'reject'])->middleware('role:admin');
    Route::post('/leave/requests/{id}/cancel', [LeaveController::class, 'cancel']);

    // Recruitment & ATS Module
    Route::get('/recruitment/openings', [RecruitmentController::class, 'getOpenings']);
    Route::post('/recruitment/openings', [RecruitmentController::class, 'storeOpening'])->middleware('role:admin,hr');
    Route::get('/recruitment/candidates', [RecruitmentController::class, 'getCandidates']);
    Route::post('/recruitment/candidates', [RecruitmentController::class, 'storeCandidate']);
    Route::put('/recruitment/candidates/{id}/stage', [RecruitmentController::class, 'updateCandidateStage']);
    Route::post('/recruitment/interviews', [RecruitmentController::class, 'scheduleInterview']);
    Route::post('/recruitment/candidates/{id}/onboard', [RecruitmentController::class, 'issueOfferAndConvert'])->middleware('role:admin,hr');

    // Performance Management
    Route::get('/performance/cycles', [PerformanceController::class, 'getCycles']);
    Route::post('/performance/cycles', [PerformanceController::class, 'createCycle'])->middleware('role:admin,hr');
    Route::get('/performance/reviews', [PerformanceController::class, 'getReviews']);
    Route::post('/performance/reviews', [PerformanceController::class, 'submitReview']);
    Route::get('/performance/goals', [PerformanceController::class, 'getGoals']);
    Route::post('/performance/goals', [PerformanceController::class, 'storeGoal']);

    // Expenses & Reimbursements
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/{id}/receipt', [ExpenseController::class, 'downloadReceipt']);
    Route::post('/expenses/{id}/approve', [ExpenseController::class, 'approve'])->middleware('role:admin,hr');
    Route::post('/expenses/{id}/reject', [ExpenseController::class, 'reject'])->middleware('role:admin,hr');

    // Loans & Advances
    Route::get('/loans', [LoanController::class, 'index']);
    Route::post('/loans', [LoanController::class, 'store']);
    Route::post('/loans/{id}/approve', [LoanController::class, 'approve'])->middleware('role:admin,hr');

    // Payroll & Salary Slips (Admin-Exclusive management + Employee-Scoped view)
    Route::get('/payroll', [PayrollController::class, 'index'])->middleware('role:admin');
    Route::post('/payroll', [PayrollController::class, 'store'])->middleware('role:admin');
    Route::post('/payroll/bulk-generate', [PayrollController::class, 'bulkGenerate'])->middleware('role:admin');
    Route::get('/payroll/{id}', [PayrollController::class, 'show']);
    Route::put('/payroll/{id}', [PayrollController::class, 'update'])->middleware('role:admin');
    Route::post('/payroll/{id}/mark-paid', [PayrollController::class, 'markPaid'])->middleware('role:admin');
    Route::get('/payroll/{id}/slip-data', [PayrollController::class, 'slipData']);
    Route::get('/employee/payslips', [PayrollController::class, 'employeePayslips']);

    // Timesheets
    Route::get('/timesheets', [TimesheetController::class, 'index']);
    Route::post('/timesheets', [TimesheetController::class, 'store']);

    // Asset Management
    Route::get('/assets', [AssetController::class, 'index']);
    Route::post('/assets', [AssetController::class, 'store'])->middleware('role:admin,hr');
    Route::post('/assets/{id}/assign', [AssetController::class, 'assign'])->middleware('role:admin,hr');

    // Helpdesk & Ticketing
    Route::get('/helpdesk', [HelpdeskController::class, 'index']);
    Route::post('/helpdesk', [HelpdeskController::class, 'store']);
    Route::put('/helpdesk/{id}/status', [HelpdeskController::class, 'updateStatus'])->middleware('role:admin,hr');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::match(['get', 'post', 'put'], '/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::match(['get', 'post', 'put'], '/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Document Management
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'upload']);
    Route::get('/documents/{id}/download', [DocumentController::class, 'download']);
    Route::get('/documents/{id}/view', [DocumentController::class, 'view']);
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);

    // Reports
    Route::get('/reports/headcount', [ReportController::class, 'headcountReport'])->middleware('role:admin,hr');
    Route::get('/reports/attendance-trends', [ReportController::class, 'attendanceTrendReport'])->middleware('role:admin,hr');
    Route::get('/reports/leave-usage', [ReportController::class, 'leaveUsageReport'])->middleware('role:admin,hr');
    Route::get('/reports/recruitment', [ReportController::class, 'recruitmentReport'])->middleware('role:admin,hr');
    Route::get('/reports/monthly-attendance', [MonthlyAttendanceReportController::class, 'generate'])->middleware('role:admin');
    Route::post('/reports/monthly-attendance/store', [MonthlyAttendanceReportController::class, 'store'])->middleware('role:admin');
    Route::get('/reports/monthly-attendance/stored', [MonthlyAttendanceReportController::class, 'storedList'])->middleware('role:admin');
    Route::get('/reports/monthly-attendance/stored/{id}', [MonthlyAttendanceReportController::class, 'showStored'])->middleware('role:admin');
    Route::delete('/reports/monthly-attendance/stored/{id}', [MonthlyAttendanceReportController::class, 'destroyStored'])->middleware('role:admin');

    // Document Vault & Daily Work Reports
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'upload']);
    Route::get('/documents/{id}/download', [DocumentController::class, 'download']);
    Route::get('/documents/{id}/view', [DocumentController::class, 'view']);
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);

    // Announcements
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('role:admin,hr');
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy'])->middleware('role:admin,hr');

    // Onboarding Checklists
    Route::get('/checklists', [ChecklistController::class, 'index']);
    Route::post('/checklists', [ChecklistController::class, 'store'])->middleware('role:admin,hr');
    Route::post('/checklists/{id}/toggle-item', [ChecklistController::class, 'toggleItem']);

    // AI Attrition & Anomaly Insights
    Route::get('/insights', [InsightsController::class, 'index'])->middleware('role:admin,hr');
    Route::post('/insights/scan', [InsightsController::class, 'triggerScan'])->middleware('role:admin,hr');

    // Tasks & Todo Tasker
    Route::get('/dashboard/stats', [TaskController::class, 'dashboardStats']);
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/performance', [TaskController::class, 'employeePerformance'])->middleware('role:admin');
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/assignable-users', [TaskController::class, 'assignableUsers']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::put('/tasks/{id}', [TaskController::class, 'update']);
    Route::put('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
    Route::post('/tasks/{id}/toggle-subtask', [TaskController::class, 'toggleSubtask']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);

    // Admin Command Center & Management
    Route::get('/admin/stats', [AdminController::class, 'stats'])->middleware('role:admin');
    Route::get('/admin/users', [AdminController::class, 'users'])->middleware('role:admin');
    Route::put('/admin/users/{id}/role', [AdminController::class, 'updateUserRole'])->middleware('role:admin');
    Route::put('/admin/users/{id}/status', [AdminController::class, 'updateUserStatus'])->middleware('role:admin');
    Route::put('/admin/users/{id}/manager', [AdminController::class, 'assignManager'])->middleware('role:admin');
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index'])->middleware('role:admin');

    // Organization Settings
    Route::get('/settings/organization', [AdminController::class, 'getOrganization'])->middleware('role:admin');
    Route::put('/settings/organization', [AdminController::class, 'updateOrganization'])->middleware('role:admin');
    Route::post('/settings/organization/logo', [AdminController::class, 'updateLogo'])->middleware('role:admin');

    // Role-Aware AI / Organization Assistant
    Route::post('/assistant/ask', [AssistantController::class, 'ask']);
    Route::post('/assistant/execute', [AssistantController::class, 'executeAction']);
});

// Preflight OPTIONS catch-all
Route::options('{any}', function () {
    return response('', 200);
})->where('any', '.*');
