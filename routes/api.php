<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PayrollController;
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
use App\Http\Middleware\TokenAuthMiddleware;
use App\Models\Organization;
use Illuminate\Http\Request;

// Public Auth Routes
Route::post('/auth/login', [AuthController::class, 'login']);

// Authenticated Routes
Route::middleware(TokenAuthMiddleware::class)->group(function () {
    // Auth & Personal
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

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
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::get('/employees/{id}', [EmployeeController::class, 'show']);
    Route::put('/employees/{id}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);
    Route::post('/employees/{id}/documents', [EmployeeController::class, 'uploadDocument']);

    // Attendance
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('/attendance/history', [AttendanceController::class, 'history']);
    Route::get('/attendance/summary', [AttendanceController::class, 'summary']);
    Route::post('/attendance/correction', [AttendanceController::class, 'adminCorrection']);
    Route::post('/attendance/correction/{id}', [AttendanceController::class, 'adminCorrection']);
    Route::post('/attendance/update-schedule', [AttendanceController::class, 'updateSchedule']);
    Route::get('/attendance/schedule', [AttendanceController::class, 'getSchedule']);

    // Leave
    Route::get('/leave/types', [LeaveController::class, 'getLeaveTypes']);
    Route::get('/leave/balances', [LeaveController::class, 'getBalances']);
    Route::get('/leave/requests', [LeaveController::class, 'index']);
    Route::post('/leave/requests', [LeaveController::class, 'store']);
    Route::post('/leave/requests/{id}/approve', [LeaveController::class, 'approve']);
    Route::post('/leave/requests/{id}/reject', [LeaveController::class, 'reject']);

    // Payroll
    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::post('/payroll/generate', [PayrollController::class, 'generatePayroll']);
    Route::put('/payroll/{id}/status', [PayrollController::class, 'updateStatus']);
    Route::get('/payroll/{id}/payslip', [PayrollController::class, 'getPayslipDetail']);

    // Recruitment & ATS Module
    Route::get('/recruitment/openings', [RecruitmentController::class, 'getOpenings']);
    Route::post('/recruitment/openings', [RecruitmentController::class, 'storeOpening']);
    Route::get('/recruitment/candidates', [RecruitmentController::class, 'getCandidates']);
    Route::post('/recruitment/candidates', [RecruitmentController::class, 'storeCandidate']);
    Route::put('/recruitment/candidates/{id}/stage', [RecruitmentController::class, 'updateCandidateStage']);
    Route::post('/recruitment/interviews', [RecruitmentController::class, 'scheduleInterview']);
    Route::post('/recruitment/candidates/{id}/onboard', [RecruitmentController::class, 'issueOfferAndConvert']);

    // Performance & Goals Module
    Route::get('/performance/cycles', [PerformanceController::class, 'getCycles']);
    Route::post('/performance/cycles', [PerformanceController::class, 'storeCycle']);
    Route::get('/performance/goals', [PerformanceController::class, 'getGoals']);
    Route::post('/performance/goals', [PerformanceController::class, 'storeGoal']);
    Route::put('/performance/goals/{id}', [PerformanceController::class, 'updateGoalProgress']);
    Route::get('/performance/reviews', [PerformanceController::class, 'getReviews']);
    Route::post('/performance/reviews/{id}/submit', [PerformanceController::class, 'submitReview']);

    // Expenses & Reimbursements
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::post('/expenses/{id}/approve', [ExpenseController::class, 'approve']);
    Route::post('/expenses/{id}/reject', [ExpenseController::class, 'reject']);

    // Loans & Advances
    Route::get('/loans', [LoanController::class, 'index']);
    Route::post('/loans', [LoanController::class, 'store']);
    Route::post('/loans/{id}/approve', [LoanController::class, 'approve']);

    // Timesheets
    Route::get('/timesheets', [TimesheetController::class, 'index']);
    Route::post('/timesheets', [TimesheetController::class, 'store']);

    // Asset Management
    Route::get('/assets', [AssetController::class, 'index']);
    Route::post('/assets', [AssetController::class, 'store']);
    Route::post('/assets/{id}/assign', [AssetController::class, 'assign']);

    // Helpdesk & Ticketing
    Route::get('/helpdesk', [HelpdeskController::class, 'index']);
    Route::post('/helpdesk', [HelpdeskController::class, 'store']);
    Route::put('/helpdesk/{id}/status', [HelpdeskController::class, 'updateStatus']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Document Management
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'upload']);

    // Reports
    Route::get('/reports/headcount', [ReportController::class, 'headcountReport']);
    Route::get('/reports/attendance-trends', [ReportController::class, 'attendanceTrendReport']);
    Route::get('/reports/leave-usage', [ReportController::class, 'leaveUsageReport']);

    // Announcements
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);

    // Onboarding Checklists
    Route::get('/checklists', [ChecklistController::class, 'index']);
    Route::post('/checklists', [ChecklistController::class, 'store']);
    Route::post('/checklists/{id}/toggle-item', [ChecklistController::class, 'toggleItem']);

    // AI Attrition & Anomaly Insights
    Route::get('/insights', [InsightsController::class, 'index']);
    Route::post('/insights/scan', [InsightsController::class, 'triggerScan']);

    // Tasks & Todo Tasker
    Route::get('/dashboard/stats', [TaskController::class, 'dashboardStats']);
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/performance', [TaskController::class, 'employeePerformance']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/assignable-users', [TaskController::class, 'assignableUsers']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::put('/tasks/{id}', [TaskController::class, 'update']);
    Route::put('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
    Route::post('/tasks/{id}/toggle-subtask', [TaskController::class, 'toggleSubtask']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);

    // Admin Audit Logs
    Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);

    // Organization Settings
    Route::get('/settings/organization', function (Request $request) {
        $user = $request->user();
        if (strtolower($user->role->name ?? '') !== 'admin') {
            return response()->json(['message' => 'Unauthorized: Admin access required for organization settings'], 403);
        }
        $org = Organization::find($user->organization_id);
        return response()->json(['organization' => $org]);
    });
});
