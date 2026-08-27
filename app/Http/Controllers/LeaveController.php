<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LeaveController extends Controller
{
    /**
     * Get subordinate employee IDs for the given user based on role and manager hierarchy.
     */
    private function getSubordinateUserIds(User $user): array
    {
        $role = $user->getCanonicalRole();

        if (in_array($role, ['admin', 'hr'])) {
            return User::where('organization_id', $user->organization_id)->pluck('id')->toArray();
        }

        if ($role === 'manager') {
            // Direct reports to this manager
            $directReportIds = User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();

            // Sub-reports (employees reporting to team leaders who report to this manager)
            $subReportIds = User::where('organization_id', $user->organization_id)
                ->whereIn('manager_id', $directReportIds)
                ->pluck('id')
                ->toArray();

            return array_unique(array_merge($directReportIds, $subReportIds));
        }

        if ($role === 'team_leader') {
            // Direct team members assigned to this team leader
            return User::where('organization_id', $user->organization_id)
                ->where('manager_id', $user->id)
                ->pluck('id')
                ->toArray();
        }

        return [];
    }

    /**
     * Check if the approver is authorized to act on the target leave request.
     * Only Administrator has authority to approve/reject leave requests for any user.
     */
    private function canAuthorizeLeave(User $approver, LeaveRequest $leaveRequest): bool
    {
        // Must be in the same organization
        if ((int) $leaveRequest->organization_id !== (int) $approver->organization_id) {
            return false;
        }

        $role = $approver->getCanonicalRole();

        // Strictly Admin only
        return $role === 'admin';
    }

    public function getLeaveTypes(Request $request)
    {
        $user = $request->user();
        $orgId = $user->organization_id ?? 1;

        $types = LeaveType::where('organization_id', $orgId)->get();

        if ($types->isEmpty()) {
            $types = LeaveType::all();
        }

        if ($types->isEmpty()) {
            $defaults = [
                ['name' => 'Casual Leave (CL)', 'annual_quota' => 12, 'is_paid' => true],
                ['name' => 'Sick Leave (SL)', 'annual_quota' => 10, 'is_paid' => true],
                ['name' => 'Earned / Privilege Leave (PL)', 'annual_quota' => 15, 'is_paid' => true],
                ['name' => 'Maternity / Paternity Leave', 'annual_quota' => 30, 'is_paid' => true],
                ['name' => 'Compensatory Off (Comp-Off)', 'annual_quota' => 5, 'is_paid' => true],
                ['name' => 'Unpaid Leave (LOP)', 'annual_quota' => 0, 'is_paid' => false],
            ];
            foreach ($defaults as $d) {
                LeaveType::create([
                    'organization_id' => $orgId,
                    'name' => $d['name'],
                    'annual_quota' => $d['annual_quota'],
                    'is_paid' => $d['is_paid'],
                ]);
            }
            $types = LeaveType::where('organization_id', $orgId)->get();
        }

        return response()->json(['leave_types' => $types]);
    }

    public function getBalances(Request $request)
    {
        $user = $request->user();
        $targetUserId = (int) $request->query('user_id', $user->id);

        if ($targetUserId !== $user->id) {
            $subordinateIds = $this->getSubordinateUserIds($user);
            if (!in_array($user->getCanonicalRole(), ['admin', 'hr']) && !in_array($targetUserId, $subordinateIds)) {
                return response()->json(['message' => 'Unauthorized: Cannot view leave balances of another employee'], 403);
            }

            $targetUser = User::where('organization_id', $user->organization_id)
                ->where('id', $targetUserId)
                ->first();

            if (!$targetUser) {
                return response()->json(['message' => 'Target employee not found in your organization'], 404);
            }
        }

        $balances = LeaveBalance::where('organization_id', $user->organization_id)
            ->where('user_id', $targetUserId)
            ->with('leaveType')
            ->get();

        if ($balances->isEmpty()) {
            $leaveTypes = LeaveType::where(function ($q) use ($user) {
                $q->where('organization_id', $user->organization_id)
                  ->orWhereNull('organization_id');
            })->get();

            if ($leaveTypes->isEmpty()) {
                $leaveTypes = LeaveType::all();
            }

            foreach ($leaveTypes as $lt) {
                $quota = $lt->max_days_per_year ?? $lt->annual_quota ?? 12;
                LeaveBalance::create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $targetUserId,
                    'leave_type_id' => $lt->id,
                    'allocated' => $quota,
                    'used' => 0,
                    'remaining' => $quota,
                ]);
            }

            $balances = LeaveBalance::where('organization_id', $user->organization_id)
                ->where('user_id', $targetUserId)
                ->with('leaveType')
                ->get();
        }

        return response()->json(['balances' => $balances]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->getCanonicalRole();
        $viewMode = $request->query('view_mode'); // 'personal', 'team', or null (default)

        $query = LeaveRequest::where('organization_id', $user->organization_id)
            ->with(['user.role', 'leaveType', 'approver']);

        if ($role === 'employee' || $viewMode === 'personal') {
            // Employee sees only their own leave requests
            $query->where('user_id', $user->id);
        } elseif ($role === 'team_leader') {
            $subordinateIds = $this->getSubordinateUserIds($user);
            if ($viewMode === 'team') {
                $query->whereIn('user_id', $subordinateIds);
            } else {
                $allowedIds = array_merge([$user->id], $subordinateIds);
                $query->whereIn('user_id', $allowedIds);
            }
        } elseif ($role === 'manager') {
            $subordinateIds = $this->getSubordinateUserIds($user);
            if ($viewMode === 'team') {
                $query->whereIn('user_id', $subordinateIds);
            } else {
                $allowedIds = array_merge([$user->id], $subordinateIds);
                $query->whereIn('user_id', $allowedIds);
            }
        } elseif (in_array($role, ['admin', 'hr'])) {
            if ($viewMode === 'personal') {
                $query->where('user_id', $user->id);
            }
            // By default, Admin & HR see all requests across the organization
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        if ($request->has('user_id') && $request->user_id !== '') {
            $targetId = (int) $request->user_id;
            $subordinateIds = $this->getSubordinateUserIds($user);
            if (in_array($role, ['admin', 'hr']) || $targetId === $user->id || in_array($targetId, $subordinateIds)) {
                $query->where('user_id', $targetId);
            }
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['leave_requests' => $requests]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
        ]);

        // Ensure leave_type belongs to caller organization
        $type = LeaveType::where('organization_id', $user->organization_id)
            ->where('id', $request->leave_type_id)
            ->first();

        if (!$type) {
            return response()->json(['message' => 'Invalid leave type for your organization'], 404);
        }

        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $daysCount = $start->diffInDays($end) + 1;

        // Check remaining balance
        $balance = LeaveBalance::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('leave_type_id', $request->leave_type_id)
            ->first();

        if ($balance && $balance->remaining < $daysCount) {
            return response()->json([
                'message' => "Insufficient leave balance. You have {$balance->remaining} days remaining, but requested {$daysCount} days."
            ], 422);
        }

        $leaveRequest = LeaveRequest::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'days_count' => $daysCount,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        NotificationService::notifyManagementChain(
            $user,
            'New Leave Request Submitted',
            "{$user->name} has requested leave from {$request->start_date} to {$request->end_date} ({$daysCount} days).",
            'warning',
            '/hr/leave'
        );

        return response()->json([
            'message' => 'Leave request submitted successfully',
            'leave_request' => $leaveRequest->load('leaveType')
        ], 201);
    }

    public function approve(Request $request, $id)
    {
        $approver = $request->user();

        $leaveRequest = LeaveRequest::where('organization_id', $approver->organization_id)
            ->where('id', $id)
            ->with(['user', 'leaveType'])
            ->first();

        if (!$leaveRequest) {
            return response()->json(['message' => 'Leave request not found'], 404);
        }

        if (!$this->canAuthorizeLeave($approver, $leaveRequest)) {
            return response()->json(['message' => 'Unauthorized: You do not have authority to approve this leave request'], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => "This request has already been {$leaveRequest->status}."], 400);
        }

        $leaveRequest->status = 'approved';
        $leaveRequest->approver_id = $approver->id;
        $leaveRequest->save();

        // Deduct from leave balance
        $balance = LeaveBalance::where('organization_id', $approver->organization_id)
            ->where('user_id', $leaveRequest->user_id)
            ->where('leave_type_id', $leaveRequest->leave_type_id)
            ->first();

        if ($balance) {
            $balance->used += $leaveRequest->days_count;
            $balance->remaining = max(0, $balance->allocated - $balance->used);
            $balance->save();
        }

        // Auto-mark attendance as 'on_leave' for non-weekend dates in range
        $start = Carbon::parse($leaveRequest->start_date);
        $end = Carbon::parse($leaveRequest->end_date);
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (!$date->isWeekend()) {
                $dateStr = $date->format('Y-m-d');
                $att = Attendance::where('organization_id', $approver->organization_id)
                    ->where('user_id', $leaveRequest->user_id)
                    ->whereDate('date', $dateStr)
                    ->first();

                if ($att) {
                    $att->status = 'on_leave';
                    $att->notes = 'Approved Leave Request #' . $leaveRequest->id;
                    $att->save();
                } else {
                    Attendance::create([
                        'organization_id' => $approver->organization_id,
                        'user_id' => $leaveRequest->user_id,
                        'date' => $dateStr,
                        'status' => 'on_leave',
                        'notes' => 'Approved Leave Request #' . $leaveRequest->id,
                    ]);
                }
            }
        }

        AuditLog::create([
            'organization_id' => $approver->organization_id,
            'actor_id' => $approver->id,
            'action' => 'approve_leave',
            'target_type' => LeaveRequest::class,
            'target_id' => $leaveRequest->id,
        ]);

        NotificationService::create(
            $approver->organization_id,
            $leaveRequest->user_id,
            'Leave Request Approved',
            "Your leave request from {$leaveRequest->start_date} to {$leaveRequest->end_date} was approved by {$approver->name}.",
            'success',
            '/employee/leave'
        );

        return response()->json([
            'message' => 'Leave request approved successfully',
            'leave_request' => $leaveRequest->load(['user', 'leaveType', 'approver'])
        ]);
    }

    public function reject(Request $request, $id)
    {
        $approver = $request->user();

        $leaveRequest = LeaveRequest::where('organization_id', $approver->organization_id)
            ->where('id', $id)
            ->with(['user', 'leaveType'])
            ->first();

        if (!$leaveRequest) {
            return response()->json(['message' => 'Leave request not found'], 404);
        }

        if (!$this->canAuthorizeLeave($approver, $leaveRequest)) {
            return response()->json(['message' => 'Unauthorized: You do not have authority to reject this leave request'], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => "This request has already been {$leaveRequest->status}."], 400);
        }

        $leaveRequest->status = 'rejected';
        $leaveRequest->approver_id = $approver->id;
        $leaveRequest->rejection_reason = $request->rejection_reason ?? 'Not approved by management';
        $leaveRequest->save();

        AuditLog::create([
            'organization_id' => $approver->organization_id,
            'actor_id' => $approver->id,
            'action' => 'reject_leave',
            'target_type' => LeaveRequest::class,
            'target_id' => $leaveRequest->id,
            'payload' => ['reason' => $leaveRequest->rejection_reason],
        ]);

        NotificationService::create(
            $approver->organization_id,
            $leaveRequest->user_id,
            'Leave Request Rejected',
            "Your leave request from {$leaveRequest->start_date} to {$leaveRequest->end_date} was rejected: {$leaveRequest->rejection_reason}",
            'error',
            '/employee/leave'
        );

        return response()->json([
            'message' => 'Leave request rejected',
            'leave_request' => $leaveRequest->load(['user', 'leaveType', 'approver'])
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $user = $request->user();

        $leaveRequest = LeaveRequest::where('organization_id', $user->organization_id)
            ->where('id', $id)
            ->first();

        if (!$leaveRequest) {
            return response()->json(['message' => 'Leave request not found'], 404);
        }

        // Only the employee who created the request (or an Admin) can cancel it
        if ((int) $leaveRequest->user_id !== (int) $user->id && $user->getCanonicalRole() !== 'admin') {
            return response()->json(['message' => 'Unauthorized: You can only cancel your own leave requests'], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending leave requests can be cancelled'], 400);
        }

        $leaveRequest->status = 'cancelled';
        $leaveRequest->save();

        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_id' => $user->id,
            'action' => 'cancel_leave',
            'target_type' => LeaveRequest::class,
            'target_id' => $leaveRequest->id,
        ]);

        return response()->json([
            'message' => 'Leave request cancelled successfully',
            'leave_request' => $leaveRequest
        ]);
    }
}
