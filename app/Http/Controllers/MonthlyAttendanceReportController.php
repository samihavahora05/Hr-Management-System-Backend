<?php

namespace App\Http\Controllers;

use App\Models\MonthlyAttendanceReport;
use App\Models\Attendance;
use App\Models\User;
use App\Models\LeaveRequest;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

class MonthlyAttendanceReportController extends Controller
{
    private function ensureTableExists()
    {
        if (!Schema::hasTable('monthly_attendance_reports')) {
            Schema::create('monthly_attendance_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('title');
                $table->string('month'); // e.g. "2026-09"
                $table->integer('year');
                $table->string('month_name'); // e.g. "September 2026"
                $table->string('department')->default('all');
                $table->integer('total_employees')->default(0);
                $table->integer('total_working_days')->default(0);
                $table->decimal('avg_attendance_percentage', 5, 2)->default(0.00);
                $table->decimal('avg_performance_rate', 5, 2)->default(0.00);
                $table->json('summary')->nullable();
                $table->json('records')->nullable();
                $table->string('status')->default('stored');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    private function checkAdminAccess(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? $user->role ?? 'employee');
        if (method_exists($user, 'getCanonicalRole')) {
            $roleName = $user->getCanonicalRole();
        }

        if ($roleName !== 'admin') {
            return false;
        }
        return true;
    }

    /**
     * Compute and return the full monthly attendance report for a specified month.
     * Restricted: Only Admin can access.
     */
    public function generate(Request $request)
    {
        $this->ensureTableExists();

        if (!$this->checkAdminAccess($request)) {
            return response()->json([
                'message' => 'Unauthorized: Only Administrator can access or generate the monthly attendance report.'
            ], 403);
        }

        $user = $request->user();
        $orgId = $user->organization_id;

        // Month format expected: YYYY-MM (e.g. 2026-09)
        $monthParam = $request->query('month', Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $monthParam = Carbon::now()->format('Y-m');
        }

        $department = $request->query('department', 'all');
        $search = $request->query('search', '');

        $report = $this->calculateMonthlyReport($orgId, $monthParam, $department, $search);

        return response()->json($report);
    }

    /**
     * Store/save the monthly attendance report snapshot permanently into the database.
     * Restricted: Only Admin can store.
     */
    public function store(Request $request)
    {
        $this->ensureTableExists();

        if (!$this->checkAdminAccess($request)) {
            return response()->json([
                'message' => 'Unauthorized: Only Administrator can store the monthly attendance report.'
            ], 403);
        }

        $user = $request->user();
        $orgId = $user->organization_id;

        $request->validate([
            'month' => 'required|string',
            'title' => 'nullable|string',
            'department' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $monthParam = $request->input('month');
        $department = $request->input('department', 'all');
        $customTitle = $request->input('title');
        $notes = $request->input('notes');

        $reportData = $this->calculateMonthlyReport($orgId, $monthParam, $department);

        $title = $customTitle ?: "Monthly Attendance Register - " . $reportData['month_name'];
        if ($department && $department !== 'all') {
            $title .= " ({$department})";
        }

        $storedReport = MonthlyAttendanceReport::updateOrCreate(
            [
                'organization_id' => $orgId,
                'month' => $monthParam,
                'department' => $department ?: 'all',
            ],
            [
                'created_by' => $user->id,
                'title' => $title,
                'year' => $reportData['year'],
                'month_name' => $reportData['month_name'],
                'total_employees' => $reportData['total_employees'],
                'total_working_days' => $reportData['total_working_days'],
                'avg_attendance_percentage' => $reportData['avg_attendance_percentage'],
                'avg_performance_rate' => $reportData['avg_performance_rate'],
                'summary' => $reportData['summary'],
                'records' => $reportData['records'],
                'status' => 'stored',
                'notes' => $notes ?: 'Monthly attendance report snapshot generated and stored by Administrator.',
            ]
        );

        AuditLog::create([
            'organization_id' => $orgId,
            'actor_id' => $user->id,
            'action' => 'monthly_attendance_report_stored',
            'target_type' => MonthlyAttendanceReport::class,
            'target_id' => $storedReport->id,
            'payload' => [
                'month' => $monthParam,
                'title' => $title,
                'total_employees' => $reportData['total_employees'],
                'avg_performance_rate' => $reportData['avg_performance_rate'],
            ],
        ]);

        return response()->json([
            'message' => 'Monthly attendance report stored and archived successfully.',
            'report' => $storedReport,
        ]);
    }

    /**
     * List all stored/archived monthly reports.
     */
    public function storedList(Request $request)
    {
        $this->ensureTableExists();

        if (!$this->checkAdminAccess($request)) {
            return response()->json([
                'message' => 'Unauthorized: Only Administrator can access stored monthly attendance reports.'
            ], 403);
        }

        $user = $request->user();
        $reports = MonthlyAttendanceReport::where('organization_id', $user->organization_id)
            ->with('creator:id,name,email')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['stored_reports' => $reports]);
    }

    /**
     * Get a specific stored monthly report by ID with full details.
     */
    public function showStored(Request $request, $id)
    {
        $this->ensureTableExists();

        if (!$this->checkAdminAccess($request)) {
            return response()->json([
                'message' => 'Unauthorized: Only Administrator can view stored monthly attendance reports.'
            ], 403);
        }

        $user = $request->user();
        $report = MonthlyAttendanceReport::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->with('creator:id,name,email')
            ->first();

        if (!$report) {
            return response()->json(['message' => 'Stored monthly attendance report not found.'], 404);
        }

        return response()->json(['report' => $report]);
    }

    /**
     * Delete a stored monthly attendance report.
     */
    public function destroyStored(Request $request, $id)
    {
        $this->ensureTableExists();

        if (!$this->checkAdminAccess($request)) {
            return response()->json([
                'message' => 'Unauthorized: Only Administrator can delete stored monthly attendance reports.'
            ], 403);
        }

        $user = $request->user();
        $report = MonthlyAttendanceReport::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$report) {
            return response()->json(['message' => 'Stored monthly attendance report not found.'], 404);
        }

        $report->delete();

        return response()->json(['message' => 'Stored monthly attendance report deleted successfully.']);
    }

    /**
     * Core computation engine for monthly attendance and performance ratings.
     */
    private function calculateMonthlyReport($orgId, $monthStr, $department = 'all', $search = '')
    {
        $startOfMonth = Carbon::parse($monthStr . '-01')->startOfMonth();
        $endOfMonth = (clone $startOfMonth)->endOfMonth();
        $daysInMonth = $startOfMonth->daysInMonth;
        $today = Carbon::today();

        // Calculate Calendar Days info
        $calendarDays = [];
        $scheduledWorkingDays = 0;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = Carbon::createFromDate($startOfMonth->year, $startOfMonth->month, $day);
            $dayOfWeek = $currentDate->dayOfWeek; // 0 = Sunday, 6 = Saturday
            $isSunday = ($dayOfWeek === 0);
            $isSaturday = ($dayOfWeek === 6);
            $isWeekend = $isSunday; // Sunday is weekend off; Saturday is active working day (10:00 AM - 2:00 PM)
            if (!$isWeekend) {
                $scheduledWorkingDays++;
            }
            $calendarDays[] = [
                'day' => $day,
                'date' => $currentDate->toDateString(),
                'day_name' => $currentDate->format('D'), // Mon, Tue, etc.
                'is_weekend' => $isSunday,
                'is_saturday' => $isSaturday,
                'is_past_or_today' => $currentDate->lte($today),
            ];
        }

        // Query Employees
        $empQuery = User::where('organization_id', $orgId)
            ->where('status', 'active');

        if ($department && $department !== 'all') {
            $empQuery->where('department', $department);
        }

        if ($search) {
            $empQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $employees = $empQuery->orderBy('name', 'asc')->get();

        // Fetch all attendances for the month
        $attendances = Attendance::where('organization_id', $orgId)
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->get();

        // Fetch all approved leave requests for the month
        $leaveRequests = LeaveRequest::where('organization_id', $orgId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $endOfMonth->toDateString())
            ->where('end_date', '>=', $startOfMonth->toDateString())
            ->get();

        $employeeRecords = [];
        $totalPresentCount = 0;
        $totalLateCount = 0;
        $totalAbsentCount = 0;
        $totalHalfDayCount = 0;
        $totalLeaveCount = 0;
        $totalHoursAll = 0.0;
        $sumAttendancePct = 0.0;
        $sumPerformanceRate = 0.0;

        foreach ($employees as $emp) {
            $empAttendances = $attendances->where('user_id', $emp->id)->keyBy(function ($item) {
                return Carbon::parse($item->date)->toDateString();
            });

            $empLeaves = $leaveRequests->where('user_id', $emp->id);

            $dailyPunches = [];
            $presentDays = 0;
            $onTimeDays = 0;
            $lateDays = 0;
            $halfDays = 0;
            $absentDays = 0;
            $leaveDays = 0;
            $totalEmpHours = 0.0;

            foreach ($calendarDays as $cDay) {
                $dateStr = $cDay['date'];
                $isWeekend = $cDay['is_weekend'];
                $isPastOrToday = $cDay['is_past_or_today'];

                $att = $empAttendances->get($dateStr);
                $hasLeave = $empLeaves->first(function ($l) use ($dateStr) {
                    return $dateStr >= $l->start_date && $dateStr <= $l->end_date;
                });

                $status = 'upcoming';
                $checkIn = null;
                $checkOut = null;
                $hoursWorked = 0.0;
                $notes = '';

                if ($att) {
                    $status = $att->status; // present, late, half_day, absent, on_leave
                    $checkIn = $att->check_in;
                    $checkOut = $att->check_out;
                    $notes = $att->notes ?: '';

                    // Calculate hours
                    if ($checkIn && $checkOut) {
                        try {
                            $inTime = Carbon::createFromFormat('H:i:s', strlen($checkIn) === 5 ? $checkIn . ':00' : $checkIn);
                            $outTime = Carbon::createFromFormat('H:i:s', strlen($checkOut) === 5 ? $checkOut . ':00' : $checkOut);
                            if ($outTime->gte($inTime)) {
                                $hoursWorked = round($outTime->diffInMinutes($inTime) / 60, 2);
                            } else {
                                $hoursWorked = round((($outTime->addDay())->diffInMinutes($inTime)) / 60, 2);
                            }
                        } catch (\Exception $e) {
                            $hoursWorked = $cDay['is_saturday'] ? 4.0 : 8.0;
                        }
                    } elseif ($checkIn) {
                        $hoursWorked = $cDay['is_saturday'] ? 4.0 : 8.0;
                    }

                    if ($status === 'present') {
                        $presentDays++;
                        $onTimeDays++;
                    } elseif ($status === 'late') {
                        $presentDays++;
                        $lateDays++;
                    } elseif ($status === 'half_day') {
                        $halfDays++;
                        if ($hoursWorked == 0) $hoursWorked = 4.0;
                    } elseif ($status === 'on_leave') {
                        $leaveDays++;
                    } elseif ($status === 'absent') {
                        $absentDays++;
                    }
                } elseif ($hasLeave) {
                    $status = 'on_leave';
                    $notes = 'Approved Leave (' . ($hasLeave->reason ?: 'Personal') . ')';
                    $leaveDays++;
                } elseif ($cDay['is_weekend']) {
                    $status = 'week_off';
                    $notes = 'Scheduled Sunday Off';
                } elseif ($isPastOrToday) {
                    $status = 'absent';
                    $notes = $cDay['is_saturday'] ? 'Absent on Saturday' : 'Absent (No check-in record)';
                    $absentDays++;
                } else {
                    $status = 'upcoming';
                    $notes = $cDay['is_saturday'] ? 'Upcoming Saturday (Shift ends 2:00 PM)' : 'Upcoming working day';
                }

                $totalEmpHours += $hoursWorked;

                $dailyPunches[] = [
                    'day' => $cDay['day'],
                    'date' => $dateStr,
                    'day_name' => $cDay['day_name'],
                    'is_weekend' => $isWeekend,
                    'status' => $status,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'hours_worked' => $hoursWorked,
                    'notes' => $notes,
                ];
            }

            // Attendance % calculation
            // Base = scheduled working days in the month
            $attendancePercentage = $scheduledWorkingDays > 0
                ? min(100.0, round((($presentDays + ($halfDays * 0.5)) / $scheduledWorkingDays) * 100, 1))
                : 100.0;

            // Punctuality rate calculation
            $punctualityRate = $presentDays > 0
                ? min(100.0, round(($onTimeDays / $presentDays) * 100, 1))
                : 100.0;

            // Performance Rate calculation:
            // Combines attendance percentage (70%) and punctuality/on-time factor (30%)
            $performanceRate = round(($attendancePercentage * 0.70) + ($punctualityRate * 0.30), 1);

            // Performance Rating Category & Badge
            if ($performanceRate >= 95.0) {
                $performanceRating = 'Outstanding';
                $performanceBadge = 'emerald';
            } elseif ($performanceRate >= 85.0) {
                $performanceRating = 'Good / Consistent';
                $performanceBadge = 'blue';
            } elseif ($performanceRate >= 75.0) {
                $performanceRating = 'Average';
                $performanceBadge = 'amber';
            } else {
                $performanceRating = 'Needs Attention';
                $performanceBadge = 'rose';
            }

            $avgDailyHours = $presentDays > 0 ? round($totalEmpHours / $presentDays, 1) : 0.0;

            $employeeRecords[] = [
                'employee_id' => $emp->id,
                'name' => $emp->name,
                'email' => $emp->email,
                'employee_code' => $emp->employee_code ?: 'EMP' . str_pad($emp->id, 4, '0', STR_PAD_LEFT),
                'department' => $emp->department ?: 'General',
                'designation' => $emp->designation ?: 'Staff',
                'avatar' => $emp->avatar,
                'total_calendar_days' => $daysInMonth,
                'working_days' => $scheduledWorkingDays,
                'present_days' => $presentDays,
                'on_time_days' => $onTimeDays,
                'late_days' => $lateDays,
                'half_days' => $halfDays,
                'absent_days' => $absentDays,
                'leave_days' => $leaveDays,
                'total_hours' => round($totalEmpHours, 1),
                'avg_daily_hours' => $avgDailyHours,
                'attendance_percentage' => $attendancePercentage,
                'punctuality_rate' => $punctualityRate,
                'performance_rate' => $performanceRate,
                'performance_rating' => $performanceRating,
                'performance_badge' => $performanceBadge,
                'daily_punches' => $dailyPunches,
            ];

            $totalPresentCount += $presentDays;
            $totalLateCount += $lateDays;
            $totalAbsentCount += $absentDays;
            $totalHalfDayCount += $halfDays;
            $totalLeaveCount += $leaveDays;
            $totalHoursAll += $totalEmpHours;
            $sumAttendancePct += $attendancePercentage;
            $sumPerformanceRate += $performanceRate;
        }

        $empCount = count($employees);
        $avgAttendancePercentage = $empCount > 0 ? round($sumAttendancePct / $empCount, 1) : 0.0;
        $avgPerformanceRate = $empCount > 0 ? round($sumPerformanceRate / $empCount, 1) : 0.0;

        return [
            'month' => $monthStr,
            'year' => $startOfMonth->year,
            'month_name' => $startOfMonth->format('F Y'),
            'total_employees' => $empCount,
            'total_working_days' => $scheduledWorkingDays,
            'days_in_month' => $daysInMonth,
            'avg_attendance_percentage' => $avgAttendancePercentage,
            'avg_performance_rate' => $avgPerformanceRate,
            'calendar_days' => $calendarDays,
            'summary' => [
                'total_present' => $totalPresentCount,
                'total_late' => $totalLateCount,
                'total_absent' => $totalAbsentCount,
                'total_half_day' => $totalHalfDayCount,
                'total_on_leave' => $totalLeaveCount,
                'total_hours_worked' => round($totalHoursAll, 1),
                'avg_attendance_percentage' => $avgAttendancePercentage,
                'avg_performance_rate' => $avgPerformanceRate,
            ],
            'records' => $employeeRecords,
        ];
    }
}
