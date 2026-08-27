<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\JobOpening;
use App\Models\Candidate;
use App\Models\ExpenseClaim;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AssistantController extends Controller
{
    /**
     * Ask the Role-Aware AI Assistant
     */
    public function ask(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
            'context' => 'nullable|array',
        ]);

        $user = $request->user();
        $role = $user->getCanonicalRole();
        $orgId = $user->organization_id;
        $queryText = trim($request->input('query'));
        $lower = strtolower($queryText);

        // Security check for unauthorized salary inquiries
        if (preg_match('/salary|wage|compensation|pay/i', $lower) && !in_array($role, ['admin', 'hr'])) {
            return response()->json([
                'role' => $role,
                'assistant_type' => $this->getAssistantTitle($role),
                'answer' => 'Access Restricted: You are not authorized to view salary or compensation records for other workforce members according to organizational security policy.',
                'data' => null,
                'action_required' => false,
                'suggested_queries' => $this->getSuggestedQueries($role),
            ]);
        }

        // Process query based on intent and authorized role scope
        $response = $this->resolveQuery($user, $role, $orgId, $lower);

        return response()->json([
            'role' => $role,
            'assistant_type' => $this->getAssistantTitle($role),
            'answer' => $response['answer'],
            'data' => $response['data'] ?? null,
            'action_required' => $response['action_required'] ?? false,
            'action' => $response['action'] ?? null,
            'suggested_queries' => $this->getSuggestedQueries($role),
        ]);
    }

    /**
     * Execute an Assistant-proposed action after explicit user confirmation
     */
    public function executeAction(Request $request)
    {
        $request->validate([
            'action_type' => 'required|string',
            'action_payload' => 'nullable|array',
        ]);

        $user = $request->user();
        $role = $user->getCanonicalRole();
        $orgId = $user->organization_id;
        $type = $request->action_type;
        $payload = $request->action_payload ?? [];

        if ($type === 'approve_all_pending_leaves') {
            if (!in_array($role, ['admin', 'hr', 'manager', 'team_leader'])) {
                return response()->json(['message' => 'Unauthorized to approve leave requests'], 403);
            }

            $query = LeaveRequest::where('organization_id', $orgId)->where('status', 'pending');
            if (in_array($role, ['manager', 'team_leader'])) {
                $subordinateIds = User::where('organization_id', $orgId)->where('manager_id', $user->id)->pluck('id');
                $query->whereIn('user_id', $subordinateIds);
            }

            $pending = $query->get();
            $count = 0;
            foreach ($pending as $lr) {
                $lr->status = 'approved';
                $lr->approver_id = $user->id;
                $lr->approved_at = now();
                $lr->save();
                $count++;
            }

            AuditLog::create([
                'organization_id' => $orgId,
                'actor_id' => $user->id,
                'action' => 'assistant_bulk_approve_leaves',
                'target_type' => LeaveRequest::class,
                'target_id' => null,
                'payload' => ['approved_count' => $count],
            ]);

            return response()->json([
                'message' => "Successfully approved {$count} pending leave requests.",
                'approved_count' => $count,
            ]);
        }

        return response()->json(['message' => 'Unrecognized or unauthorized assistant action'], 400);
    }

    private function resolveQuery($user, $role, $orgId, $q)
    {
        $today = Carbon::today()->toDateString();

        // 1. Department Breakdown & Headcount per Department
        if (preg_match('/department|departments/i', $q)) {
            if (in_array($role, ['admin', 'hr'])) {
                $deptCounts = User::where('organization_id', $orgId)
                    ->whereNotNull('department')
                    ->selectRaw('department, count(*) as count')
                    ->groupBy('department')
                    ->orderBy('count', 'desc')
                    ->get();

                if ($deptCounts->isEmpty()) {
                    return [
                        'answer' => "No employees have been assigned to departments yet.",
                        'data' => [],
                    ];
                }

                $lines = $deptCounts->map(fn($d) => "• **{$d->department}**: {$d->count} " . ($d->count === 1 ? 'employee' : 'employees'))->join("\n");
                return [
                    'answer' => "Here is the employee distribution across departments in your organization:\n\n" . $lines,
                    'data' => $deptCounts,
                ];
            } else {
                return [
                    'answer' => "Your department is **{$user->department}**.",
                    'data' => ['department' => $user->department],
                ];
            }
        }

        // 2. Headcount & Active Employees
        if (preg_match('/how many employees|headcount|active employees|staff count|total employees|who is active/i', $q)) {
            if (in_array($role, ['admin', 'hr'])) {
                $total = User::where('organization_id', $orgId)->count();
                $active = User::where('organization_id', $orgId)->where('status', 'active')->count();
                $onLeave = User::where('organization_id', $orgId)->where('status', 'on_leave')->count();
                $inactive = User::where('organization_id', $orgId)->where('status', 'inactive')->count();

                return [
                    'answer' => "There are currently **{$total} registered members** in the organization:\n• **{$active}** active\n• **{$onLeave}** on leave\n• **{$inactive}** inactive.",
                    'data' => [
                        'total' => $total,
                        'active' => $active,
                        'on_leave' => $onLeave,
                        'inactive' => $inactive,
                    ],
                ];
            } elseif (in_array($role, ['manager', 'team_leader'])) {
                $teamCount = User::where('organization_id', $orgId)->where('manager_id', $user->id)->count();
                $activeTeam = User::where('organization_id', $orgId)->where('manager_id', $user->id)->where('status', 'active')->count();
                return [
                    'answer' => "Your assigned team has **{$teamCount} members** ({$activeTeam} currently active).",
                    'data' => ['team_count' => $teamCount, 'active' => $activeTeam],
                ];
            } else {
                return [
                    'answer' => "You are registered under the **{$user->department}** department as **{$user->designation}** (Status: " . ucfirst($user->status) . ").",
                    'data' => ['department' => $user->department, 'status' => $user->status],
                ];
            }
        }

        // 3. Pending Approvals & Leave Requests
        if (preg_match('/pending|leave requests|approvals|pending approvals/i', $q)) {
            if (in_array($role, ['admin', 'hr'])) {
                $pendingCount = LeaveRequest::where('organization_id', $orgId)->where('status', 'pending')->count();
                $pendingExpenses = ExpenseClaim::where('organization_id', $orgId)->where('status', 'pending')->count();

                $action = null;
                $actionRequired = false;
                if ($pendingCount > 0) {
                    $actionRequired = true;
                    $action = [
                        'type' => 'approve_all_pending_leaves',
                        'label' => "Bulk Approve All ({$pendingCount}) Pending Leaves",
                        'confirmation_prompt' => "Are you sure you want to approve all {$pendingCount} pending leave requests across the organization?",
                    ];
                }

                return [
                    'answer' => "There are currently **{$pendingCount} pending leave requests** and **{$pendingExpenses} pending expense claims** awaiting approval across the organization.",
                    'data' => ['pending_leaves' => $pendingCount, 'pending_expenses' => $pendingExpenses],
                    'action_required' => $actionRequired,
                    'action' => $action,
                ];
            } elseif (in_array($role, ['manager', 'team_leader'])) {
                $subIds = User::where('organization_id', $orgId)->where('manager_id', $user->id)->pluck('id');
                $teamPending = LeaveRequest::where('organization_id', $orgId)->whereIn('user_id', $subIds)->where('status', 'pending')->count();

                return [
                    'answer' => "You have **{$teamPending} pending leave requests** from your direct team members awaiting review.",
                    'data' => ['team_pending_leaves' => $teamPending],
                ];
            } else {
                $myPending = LeaveRequest::where('organization_id', $orgId)->where('user_id', $user->id)->where('status', 'pending')->count();
                return [
                    'answer' => "You currently have **{$myPending} pending leave applications** awaiting manager review.",
                    'data' => ['my_pending_leaves' => $myPending],
                ];
            }
        }

        // 4. Attendance Statistics & Trends
        if (preg_match('/attendance|late|absent|clock in/i', $q)) {
            if (in_array($role, ['admin', 'hr'])) {
                $present = Attendance::where('organization_id', $orgId)->whereDate('date', $today)->whereNotNull('check_in')->count();
                $late = Attendance::where('organization_id', $orgId)->whereDate('date', $today)->where('status', 'late')->count();
                $onTimeRate = $present > 0 ? round((($present - $late) / $present) * 100) : 100;

                return [
                    'answer' => "Today's Attendance Overview: **{$present} employees present** ({$late} late arrivals). Overall on-time arrival rate is **{$onTimeRate}%**.",
                    'data' => ['present' => $present, 'late' => $late, 'on_time_rate' => $onTimeRate],
                ];
            } elseif (in_array($role, ['manager', 'team_leader'])) {
                $subIds = User::where('organization_id', $orgId)->where('manager_id', $user->id)->pluck('id');
                $teamPresent = Attendance::where('organization_id', $orgId)->whereIn('user_id', $subIds)->whereDate('date', $today)->whereNotNull('check_in')->count();
                return [
                    'answer' => "Today, **{$teamPresent} out of " . count($subIds) . " team members** have clocked in.",
                    'data' => ['team_present' => $teamPresent, 'team_size' => count($subIds)],
                ];
            } else {
                $myAttendance = Attendance::where('organization_id', $orgId)->where('user_id', $user->id)->whereDate('date', $today)->first();
                $statusText = $myAttendance && $myAttendance->check_in ? "Clocked in at {$myAttendance->check_in}" : "Not clocked in yet today.";
                return [
                    'answer' => "Your today's attendance status: **{$statusText}**",
                    'data' => $myAttendance,
                ];
            }
        }

        // 5. Recruitment & Candidate Pipeline
        if (preg_match('/recruitment|candidate|candidates|job opening|openings|hiring/i', $q)) {
            if (in_array($role, ['admin', 'hr'])) {
                $activeOpenings = JobOpening::where('organization_id', $orgId)->where('status', 'active')->count();
                $candidatesCount = Candidate::where('organization_id', $orgId)->whereNotIn('stage', ['joined', 'rejected'])->count();
                $hiredCount = Candidate::where('organization_id', $orgId)->where('stage', 'joined')->count();

                return [
                    'answer' => "Recruitment ATS Funnel: **{$activeOpenings} active job openings**, **{$candidatesCount} active candidates** in evaluation, and **{$hiredCount} candidates hired/joined**.",
                    'data' => [
                        'active_openings' => $activeOpenings,
                        'active_candidates' => $candidatesCount,
                        'hired' => $hiredCount,
                    ],
                ];
            } else {
                return [
                    'answer' => "Recruitment operations and applicant tracking are managed by the HR and Admin teams.",
                    'data' => null,
                ];
            }
        }

        // 6. Recent Joiners / Activity
        if (preg_match('/recent|join|new joiners|activity|audit/i', $q)) {
            if ($role === 'admin') {
                $recentLogs = AuditLog::where('organization_id', $orgId)
                    ->with('actor:id,name')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();

                $logLines = $recentLogs->map(fn($l) => "• [{$l->created_at->format('M d, H:i')}] " . ($l->actor->name ?? 'System') . ": " . str_replace('_', ' ', $l->action))->join("\n");
                return [
                    'answer' => "Recent Organization Activity Log:\n\n" . ($logLines ?: 'No recent activity records.'),
                    'data' => $recentLogs,
                ];
            } else {
                $recentJoiners = User::where('organization_id', $orgId)
                    ->orderBy('joining_date', 'desc')
                    ->limit(3)
                    ->get(['id', 'name', 'department', 'designation', 'joining_date']);

                $lines = $recentJoiners->map(fn($u) => "• **{$u->name}** ({$u->designation} - {$u->department})")->join("\n");
                return [
                    'answer' => "Recently joined team members:\n\n" . $lines,
                    'data' => $recentJoiners,
                ];
            }
        }

        // Default Intelligent Fallback
        return [
            'answer' => "I am your **" . $this->getAssistantTitle($role) . "**. I can assist with organization headcount, attendance trends, pending approvals, recruitment metrics, department distribution, and audit logs. How can I help you today?",
            'data' => null,
        ];
    }

    private function getAssistantTitle($role)
    {
        switch ($role) {
            case 'admin':
                return 'Organization AI Assistant';
            case 'hr':
                return 'HR Operations Assistant';
            case 'manager':
            case 'team_leader':
                return 'Team Management Assistant';
            default:
                return 'Employee Personal Assistant';
        }
    }

    private function getSuggestedQueries($role)
    {
        switch ($role) {
            case 'admin':
                return [
                    'How many employees are currently active?',
                    'How many employees are in each department?',
                    'Show pending approvals.',
                    'Show attendance trends and on-time rate.',
                    'How many candidates are currently in recruitment?',
                    'Show recent organization activity.',
                ];
            case 'hr':
                return [
                    'How many active employees are in the organization?',
                    'Show pending leave requests.',
                    'Show recruitment candidate funnel.',
                    'Which departments have the most employees?',
                    'Who joined recently?',
                ];
            case 'manager':
            case 'team_leader':
                return [
                    'Show my team headcount.',
                    'Show my team pending leave requests.',
                    'How many team members clocked in today?',
                ];
            default:
                return [
                    'Show my today attendance status.',
                    'How many leave requests do I have pending?',
                    'Show my department and designation.',
                ];
        }
    }
}
