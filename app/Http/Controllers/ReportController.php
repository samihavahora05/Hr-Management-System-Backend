<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function headcountReport(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr', 'manager', 'company_manager', 'team_leader'])) {
            return response()->json(['message' => 'Unauthorized to view headcount reports'], 403);
        }

        $query = User::where('organization_id', $user->organization_id);

        if (in_array($roleName, ['manager', 'company_manager'])) {
            $teamLeaderIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();

            $subTeamUserIds = User::where('organization_id', $user->organization_id)
                ->whereIn('manager_id', $teamLeaderIds)
                ->pluck('id')
                ->toArray();

            $teamUserIds = array_unique(array_merge([$user->id], $teamLeaderIds, $subTeamUserIds));

            if (count($teamUserIds) > 1) {
                $query->whereIn('id', $teamUserIds);
            }
        } elseif ($roleName === 'team_leader') {
            $teamUserIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();

            $query->whereIn('id', array_unique(array_merge([$user->id], $teamUserIds)));
        }

        $totalHeadcount = (clone $query)->count();
        $activeEmployees = (clone $query)->where('status', 'active')->count();

        $byDepartment = (clone $query)
            ->selectRaw('department, count(*) as count')
            ->groupBy('department')
            ->get();

        $byRole = (clone $query)
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->selectRaw('roles.display_name as role_name, count(*) as count')
            ->groupBy('roles.display_name')
            ->get();

        return response()->json([
            'summary' => [
                'total_headcount' => $totalHeadcount,
                'active_employees' => $activeEmployees,
            ],
            'by_department' => $byDepartment,
            'by_role' => $byRole,
        ]);
    }

    public function attendanceTrendReport(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr', 'manager'])) {
            return response()->json(['message' => 'Unauthorized to view attendance trend reports'], 403);
        }

        $last30Days = Carbon::today()->subDays(30);

        $query = Attendance::where('organization_id', $user->organization_id)
            ->where('date', '>=', $last30Days);

        if ($roleName === 'manager') {
            $teamUserIds = User::where('organization_id', $user->organization_id)
                ->where(function ($q) use ($user) {
                    $q->where('manager_id', $user->id)
                      ->orWhere('id', $user->id);
                })
                ->pluck('id')
                ->toArray();

            $query->whereIn('user_id', $teamUserIds);
        }

        $trends = $query->selectRaw('date, status, count(*) as count')
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get();

        return response()->json(['trends' => $trends]);
    }

    public function leaveUsageReport(Request $request)
    {
        $user = $request->user();
        $roleName = strtolower($user->role->name ?? 'employee');

        if (!in_array($roleName, ['admin', 'hr', 'manager', 'company_manager'])) {
            return response()->json(['message' => 'Unauthorized to view leave usage reports'], 403);
        }

        $leaveTypes = \App\Models\LeaveType::where(function ($q) use ($user) {
            $q->where('organization_id', $user->organization_id)
              ->orWhereNull('organization_id');
        })->get();

        if ($leaveTypes->isEmpty()) {
            $leaveTypes = \App\Models\LeaveType::all();
        }

        if ($leaveTypes->isEmpty()) {
            $defaultTypes = [
                ['id' => 1, 'name' => 'Casual Leave', 'code' => 'CL', 'max_days_per_year' => 12],
                ['id' => 2, 'name' => 'Sick Leave', 'code' => 'SL', 'max_days_per_year' => 10],
                ['id' => 3, 'name' => 'Earned Leave', 'code' => 'EL', 'max_days_per_year' => 15],
                ['id' => 4, 'name' => 'Maternity Leave', 'code' => 'ML', 'max_days_per_year' => 84],
            ];
            $leaveTypes = collect($defaultTypes)->map(fn($t) => (object)$t);
        }

        $usage = $leaveTypes->map(function ($lt) use ($user, $roleName) {
            $query = LeaveRequest::where('organization_id', $user->organization_id)
                ->where('leave_type_id', $lt->id);

            if ($roleName === 'manager') {
                $teamUserIds = User::where('organization_id', $user->organization_id)
                    ->where(function ($q) use ($user) {
                        $q->where('manager_id', $user->id)
                          ->orWhere('id', $user->id);
                    })
                    ->pluck('id')
                    ->toArray();

                $query->whereIn('user_id', $teamUserIds);
            }

            $allRequests = (clone $query)->with(['user:id,name,email,employee_code,department', 'approver:id,name'])->get();
            $approvedRequests = $allRequests->filter(fn($r) => $r->status === 'approved');
            $totalDaysTaken = (float) $approvedRequests->sum('days_count');

            $requestsList = $allRequests->map(function ($r) {
                return [
                    'id' => $r->id,
                    'employee_name' => $r->user->name ?? 'Employee',
                    'employee_code' => $r->user->employee_code ?? '',
                    'department' => $r->user->department ?? '',
                    'start_date' => $r->start_date,
                    'end_date' => $r->end_date,
                    'days_count' => $r->days_count,
                    'status' => $r->status,
                    'reason' => $r->reason,
                    'approver_name' => $r->approver->name ?? 'HR Manager',
                ];
            });

            return [
                'leave_type' => $lt->name,
                'code' => $lt->code ?? 'LEAVE',
                'max_days_per_year' => $lt->max_days_per_year ?? 12,
                'total_days_taken' => $totalDaysTaken,
                'requests' => $requestsList,
            ];
        });

        return response()->json(['usage' => $usage]);
    }

    public function recruitmentReport(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->getCanonicalRole(), ['admin', 'hr'])) {
            return response()->json(['message' => 'Unauthorized: Only HR or Admin can view recruitment analytics'], 403);
        }

        $stages = ['applied', 'screening', 'interview_scheduled', 'interviewed', 'offered', 'joined', 'rejected'];
        $funnel = [];
        foreach ($stages as $stage) {
            $count = \App\Models\Candidate::where('organization_id', $user->organization_id)
                ->where('stage', $stage)
                ->count();
            $funnel[] = [
                'stage' => $stage,
                'count' => $count,
            ];
        }

        $totalOpenings = \App\Models\JobOpening::where('organization_id', $user->organization_id)->count();
        $activeOpenings = \App\Models\JobOpening::where('organization_id', $user->organization_id)->where('status', 'active')->count();

        return response()->json([
            'funnel' => $funnel,
            'total_openings' => $totalOpenings,
            'active_openings' => $activeOpenings,
        ]);
    }
}
